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

namespace Froxlor\System;

use Froxlor\Database\Database;
use Froxlor\Froxlor;
use PDO;

/**
 * Multi-server helper.
 *
 * The multi-server feature is configured purely via lib/userdata.inc.php:
 *
 *   $multiserver = [
 *       'local_ips' => ['198.51.100.10', '2001:db8::10'],
 *       'role' => 'master', // exactly one node carries this; slaves omit it
 *   ];
 *
 * The presence of a non-empty 'local_ips' list IS the multi-server switch:
 *
 *   - not set / empty  -> single-server: ipFilterSql() returns '' and every
 *                         config-generation path is byte-for-byte identical to
 *                         upstream.
 *   - set              -> this node only generates webserver/Let's Encrypt
 *                         config for domains whose assigned IP is in the list.
 *
 * The 'role' flag enforces MULTIWEB.md rule 2 (exactly one master): with
 * local_ips set, froxlor:cron refuses to run unless role is 'master', and
 * froxlor:cron-slave refuses to run when role IS 'master'.
 *
 * IPs are taken verbatim from the config (no auto-detection), so the feature
 * works regardless of NAT / ip_nonlocal_bind: the admin lists the exact IPs
 * that also live in panel_ipsandports for this machine.
 */
class ServerInfo
{
	/**
	 * runtime flag marking this run as a slave node; used to keep node-global
	 * actions (e.g. the froxlor panel certificate) on the master only
	 *
	 * @var bool
	 */
	private static $isSlaveNode = false;

	/**
	 * cached $multiserver array from userdata.inc.php
	 *
	 * @var array|null
	 */
	private static $multiserverConfig = null;

	/**
	 * cached list of configured local IP addresses
	 *
	 * @var array|null
	 */
	private static $localIps = null;

	/**
	 * cached list of panel_ipsandports.id values matching the local IPs
	 *
	 * @var array|null
	 */
	private static $localIpIds = null;

	/**
	 * whether domain-queries should be restricted to this node's local IPs.
	 * True whenever local IPs are configured (i.e. in a multi-server setup).
	 */
	public static function isLocalIpScopeEnabled(): bool
	{
		return !empty(self::getLocalIps());
	}

	/**
	 * mark/unmark the current run as a slave node
	 */
	public static function setSlaveNode(bool $isSlave = true): void
	{
		self::$isSlaveNode = $isSlave;
	}

	public static function isSlaveNode(): bool
	{
		return self::$isSlaveNode;
	}

	/**
	 * the local IP addresses configured for this machine in userdata.inc.php
	 *
	 * @return array list of IP strings (empty when not in a multi-server setup)
	 */
	public static function getLocalIps(): array
	{
		if (self::$localIps !== null) {
			return self::$localIps;
		}

		$multiserver = self::getMultiserverConfig();

		$ips = [];
		if (!empty($multiserver['local_ips']) && is_array($multiserver['local_ips'])) {
			foreach ($multiserver['local_ips'] as $ip) {
				$ip = trim((string)$ip);
				if ($ip !== '') {
					$ips[] = $ip;
				}
			}
		}

		self::$localIps = $ips;
		return self::$localIps;
	}

	/**
	 * the raw $multiserver configuration array from userdata.inc.php
	 * (empty array when not configured)
	 */
	private static function getMultiserverConfig(): array
	{
		if (self::$multiserverConfig !== null) {
			return self::$multiserverConfig;
		}

		$multiserver = null;
		$userdata_file = Froxlor::getInstallDir() . '/lib/userdata.inc.php';
		if (file_exists($userdata_file)) {
			require $userdata_file;
		}

		self::$multiserverConfig = is_array($multiserver) ? $multiserver : [];
		return self::$multiserverConfig;
	}

	/**
	 * the multi-server role configured for this machine
	 * ($multiserver['role'] in userdata.inc.php); '' when not set
	 */
	public static function getRole(): string
	{
		$multiserver = self::getMultiserverConfig();
		return isset($multiserver['role']) ? strtolower(trim((string)$multiserver['role'])) : '';
	}

	/**
	 * whether this machine is explicitly configured as the multi-server master
	 */
	public static function isMasterRole(): bool
	{
		return self::getRole() === 'master';
	}

	/**
	 * normalize an IP address to its packed binary form for reliable
	 * comparison (handles e.g. compressed vs. expanded IPv6 notation)
	 *
	 * @return string|null packed in_addr, or null on invalid input
	 */
	private static function normalizeIp(string $ip): ?string
	{
		$bin = @inet_pton(trim($ip));
		return $bin === false ? null : $bin;
	}

	/**
	 * resolve the panel_ipsandports.id values whose IP belongs to this machine
	 *
	 * @return array list of integer ids (empty if none match)
	 */
	public static function getLocalIpIds(): array
	{
		if (self::$localIpIds !== null) {
			return self::$localIpIds;
		}

		$localBins = [];
		foreach (self::getLocalIps() as $ip) {
			$bin = self::normalizeIp($ip);
			if ($bin !== null) {
				$localBins[$bin] = true;
			}
		}

		$ids = [];
		if (!empty($localBins)) {
			$stmt = Database::query("SELECT DISTINCT `id`, `ip` FROM `" . TABLE_PANEL_IPSANDPORTS . "`");
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$bin = self::normalizeIp($row['ip']);
				if ($bin !== null && isset($localBins[$bin])) {
					$ids[] = (int)$row['id'];
				}
			}
		}

		self::$localIpIds = $ids;
		return self::$localIpIds;
	}

	/**
	 * additive SQL fragment restricting a domain-query to the domains served
	 * by this node's local IPs.
	 *
	 * - scope disabled (single-server): returns '' (no change)
	 * - scope enabled, local IPs matched: returns an "AND <alias>.id IN (...)"
	 *   subquery against panel_domaintoip
	 * - scope enabled, NO local IP matched: returns a match-nothing clause as a
	 *   safety net, so a misconfigured node never falls back to "all domains"
	 *
	 * @param string $domainAlias SQL alias of panel_domains in the outer query
	 */
	public static function ipFilterSql(string $domainAlias = 'd'): string
	{
		if (!self::isLocalIpScopeEnabled()) {
			return '';
		}

		$ids = self::getLocalIpIds();
		if (empty($ids)) {
			// safety: scope requested but nothing matched -> generate nothing
			return " AND `" . $domainAlias . "`.`id` IN (0) ";
		}

		$idList = implode(',', array_map('intval', $ids));
		return " AND `" . $domainAlias . "`.`id` IN (
			SELECT `id_domain` FROM `" . TABLE_DOMAINTOIP . "` WHERE `id_ipandports` IN (" . $idList . ")
		) ";
	}

	/**
	 * additive SQL fragment restricting an ipsandports-query to this node's
	 * local IPs. Same three states as ipFilterSql(), including the
	 * match-nothing safety net when scope is enabled but no local IP matched.
	 *
	 * @param string $ipAlias SQL alias of panel_ipsandports in the outer query
	 */
	public static function ipPortFilterSql(string $ipAlias = 'i'): string
	{
		if (!self::isLocalIpScopeEnabled()) {
			return '';
		}

		$ids = self::getLocalIpIds();
		if (empty($ids)) {
			// safety: scope requested but nothing matched -> generate nothing
			return " AND `" . $ipAlias . "`.`id` IN (0) ";
		}

		return " AND `" . $ipAlias . "`.`id` IN (" . implode(',', array_map('intval', $ids)) . ") ";
	}

	/**
	 * whether this node serves the given panel_ipsandports entry
	 * (always true in single-server mode)
	 */
	public static function servesIpAndPort(int $ipandportId): bool
	{
		if (!self::isLocalIpScopeEnabled()) {
			return true;
		}
		return in_array($ipandportId, self::getLocalIpIds(), true);
	}
}
