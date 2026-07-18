<?php

/**
 * This file is part of the froxlor project.
 * Copyright (c) 2010 the froxlor Team (see authors).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, you can also view it online at
 * https://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  the authors
 * @author     froxlor team <team@froxlor.org>
 * @license    https://files.froxlor.org/misc/COPYING.txt GPLv2
 */

namespace Froxlor\Cli;

use Exception;
use Froxlor\Cron\System\TasksCron;
use Froxlor\Database\Database;
use Froxlor\FileDir;
use Froxlor\FroxlorLogger;
use Froxlor\Settings;
use Froxlor\System\ServerInfo;
use PDO;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Multi-server slave cron.
 *
 * Unlike the master cron (froxlor:cron) this command does NOT consume the
 * panel_tasks queue. It regenerates this node's view of the shared database in
 * a fully declarative, idempotent way:
 *
 *   - system users (libnss-extrausers)  -> on every node, unfiltered
 *   - customer home directories + quota -> on every node, unfiltered
 *   - webserver vhosts + php-fpm pools  -> only for domains whose assigned IP
 *                                          belongs to THIS machine
 *
 * Because everything is declarative and idempotent there is no cross-server
 * locking needed and the master's panel_tasks handling is left untouched.
 */
final class SlaveCron extends CliCommand
{
	protected function configure()
	{
		$this->setName('froxlor:cron-slave');
		$this->setDescription('Multi-server slave cron: regenerate this node\'s users, homes, quota and (IP-scoped) webserver configs from the shared database');
		$this->addOption('debug', 'd', InputOption::VALUE_NONE, 'Output debug information about what is going on to STDOUT.');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$result = $this->validateRequirements($output);
		if ($result != self::SUCCESS) {
			return $result;
		}

		if ($input->getOption('debug')) {
			define('CRON_DEBUG_FLAG', 1);
		}

		// this command only makes sense once local IPs are configured in
		// userdata.inc.php ($multiserver['local_ips']); without that we are not
		// in a multi-server setup and must not touch anything
		$localIps = ServerInfo::getLocalIps();
		if (empty($localIps)) {
			$output->writeln('<error>No multi-server configuration found: set $multiserver[\'local_ips\'] in lib/userdata.inc.php to use the slave cron.</>');
			return self::INVALID;
		}

		// MULTIWEB.md rule 2: the master runs froxlor:cron only, never the
		// slave cron (it would e.g. skip the panel vhost it is supposed to serve)
		if (ServerInfo::isMasterRole()) {
			$output->writeln('<error>This node is configured as the multi-server master ($multiserver[\'role\'] = \'master\') - the master must run froxlor:cron only, never froxlor:cron-slave.</>');
			return self::INVALID;
		}

		// multi-server only supports nginx + php-fpm; the apache code paths
		// carry none of the multi-server scoping (see MULTIWEB.md)
		if (Settings::Get('system.webserver') != 'nginx' || (int)Settings::Get('phpfpm.enabled') != 1) {
			$output->writeln('<error>Multi-server slave mode only supports nginx with php-fpm (system.webserver=nginx, phpfpm.enabled=1).</>');
			return self::INVALID;
		}

		// only ever run one slave-cron per machine at a time
		if (!$this->lockJob('cron-slave', $output)) {
			return self::SUCCESS;
		}

		try {
			$cronLog = FroxlorLogger::getInstanceOf([
				'loginname' => 'cronjob'
			]);
			$cronLog->setCronDebugFlag(defined('CRON_DEBUG_FLAG'));
			TasksCron::setCronlog($cronLog);

			// keep node-global actions (panel certificate) on the master; the
			// local-IP scope itself is already active because local_ips is set
			ServerInfo::setSlaveNode(true);
			$localIpIds = ServerInfo::getLocalIpIds();
			$output->writeln('<info>cron-slave: local IPs [' . implode(', ', $localIps) . '] match ' . count($localIpIds) . ' ipandport entry/entries</>');

			// 1) system users everywhere (libnss-extrausers + nscd/crond reload)
			$output->writeln('<info>Refreshing system users</>');
			TasksCron::refreshUsers();

			// 2) customer home directories everywhere (idempotent)
			$output->writeln('<info>Ensuring customer home directories</>');
			$this->ensureCustomerHomes();

			// 3) filesystem quota everywhere
			if ((int)Settings::Get('system.diskquota_enabled') != 0) {
				$output->writeln('<info>Setting filesystem quota</>');
				TasksCron::setFilesystemQuota();
			}

			// 4) webserver configs (vhosts + php-fpm), scoped to local IPs
			if (empty($localIpIds)) {
				$output->writeln('<comment>None of this machine\'s IPs match panel_ipsandports - skipping webserver config generation (safety net).</>');
			} else {
				$output->writeln('<info>Rebuilding webserver configuration for local domains</>');
				// rebuildWebserverConfigs() already runs the Let's Encrypt cron
				// itself (via HttpConfigBase::init()) before regenerating the
				// vhost configs, so there is no separate AcmeSh::run() here; the
				// froxlor panel certificate (domainid 0) is intentionally left to
				// the master (see AcmeSh::issueFroxlorVhost/renewFroxlorVhost).
				TasksCron::rebuildWebserverConfigs();
			}
		} finally {
			$this->unlockJob();
		}

		$output->writeln('<info>cron-slave finished.</>');
		return self::SUCCESS;
	}

	/**
	 * create the home directory of every customer on this node that does not
	 * have one yet.
	 *
	 * Reuses TasksCron::createNewHome() (single source of truth) but suppresses
	 * its per-customer user-refresh, since we refresh users once up-front.
	 * Customers whose home already exists are skipped to avoid the expensive
	 * recursive chown/mkdir that createNewHome() performs over the whole tree.
	 */
	private function ensureCustomerHomes(): void
	{
		$stmt = Database::query("SELECT `loginname`, `guid` FROM `" . TABLE_PANEL_CUSTOMERS . "` WHERE `guid` <> 0");
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$userhomedir = FileDir::makeCorrectDir(Settings::Get('system.documentroot_prefix') . '/' . $row['loginname'] . '/');
			// home already present -> nothing to create, skip the recursive chown
			if (is_dir($userhomedir)) {
				continue;
			}
			TasksCron::createNewHome([
				'data' => [
					'loginname' => $row['loginname'],
					'uid' => (int)$row['guid'],
					'gid' => (int)$row['guid'],
					// do not (re-)deploy the default index on every run
					'store_defaultindex' => 0,
				]
				// refreshUsers=false: users are refreshed once up-front;
				// createMaildir=false: mail directories are not created on this node
			], false, false);
		}
	}
}
