# Multi-server (multi-web) support

This document describes how the multi-server feature works and the two
invariants the whole design relies on. Read the **Two important rules** section
before deploying — the design is only correct while both hold.

## Goal

Run several Froxlor nodes off a **single shared database**, where each node only
does the work that belongs to it, without rewriting Froxlor's single-server
architecture. Every node generates its own webserver / Let's Encrypt config from
the current database state; no node steps on another node's domains.

## Supported stack: nginx + php-fpm ONLY

The multi-server feature targets **nginx with php-fpm exclusively**. The
Apache code paths are deliberately left untouched (byte-for-byte upstream):
`Apache::createIpPort()` neither filters foreign IPs nor skips the panel vhost
on slaves, and `ApacheFcgi::createOwnVhostStarter()` is not slave-gated. Only
the shared domain query (`WebserverBase`) and the nginx classes carry the
multi-server scoping — running a slave node with apache2 as webserver is
unsupported and refused by `froxlor:cron-slave`.

## The two crons

Froxlor's original model is **imperative and queue-based**: the panel writes rows
into `panel_tasks`, and `froxlor:cron` (the *master* cron) consumes that queue.
Multi-server keeps that untouched and adds a second, **declarative** cron.

| | `froxlor:cron` (MasterCron) | `froxlor:cron-slave` (SlaveCron) |
|---|---|---|
| Model | imperative, consumes the `panel_tasks` queue | **declarative, idempotent** — never looks at the queue |
| Role | the source of truth; processes tasks (create/delete home, mail, DNS, SSL cleanup, …) | renders the *current* database state onto this node's filesystem |
| Runs on | **the master node only** | **every slave node** (never on the master) |
| Node-global work (panel certificate, queue) | yes | no — explicitly skipped |

Because everything the slave does is declarative and idempotent (it *generates*
config from DB state rather than reacting to events), the slave needs **no
cross-server locking and no queue coordination** — it can be re-run at any time
and always converges to the same result.

## Configuration — the multi-server switch

The feature is enabled purely via `lib/userdata.inc.php`:

```php
$multiserver = [
    'local_ips' => ['198.51.100.10', '2001:db8::10'],
    'role' => 'master', // exactly ONE node carries this; slave nodes omit it
];
```

The presence of a non-empty `local_ips` list **is** the switch:

- **not set / empty** → single-server mode: `ServerInfo::ipFilterSql()` returns
  `''`, and every config-generation path is byte-for-byte identical to upstream.
- **set** → this node only generates webserver / Let's Encrypt config for domains
  whose assigned IP is in the list.

IPs are taken verbatim from the config (no auto-detection), so the feature works
regardless of NAT / `ip_nonlocal_bind`: the admin lists the exact IPs that also
live in `panel_ipsandports` for this machine. IP comparison is done on the packed
binary form (`inet_pton`), so compressed vs. expanded IPv6 notation matches
correctly.

The `role` flag enforces rule 2 (see below) in code: with `local_ips` set,
`froxlor:cron` refuses to run unless `role` is `'master'`, and
`froxlor:cron-slave` refuses to run when `role` **is** `'master'`. On the master
node, `role => 'master'` is therefore **mandatory** — without it the master cron
will not start.

## The mechanism — additive IP scoping

Instead of branching the code per query, a single additive SQL fragment
(`ServerInfo::ipFilterSql()`) is appended to the existing domain queries in
`WebserverBase`, `AcmeSh` (issue/renew), and `rebuildWebserverConfigs`. Three
states:

1. **scope disabled** (single-server) → `''`, no change.
2. **scope enabled, local IPs matched** →
   `AND <domain>.id IN (SELECT id_domain FROM panel_domaintoip WHERE id_ipandports IN (...))`.
3. **scope enabled, no local IP matched** → `AND <domain>.id IN (0)` — a
   *match-nothing* safety net, so a misconfigured node generates **nothing**
   rather than falling back to "all domains".

The same scoping exists on the ip/port level (`ServerInfo::ipPortFilterSql()`
for SQL, `ServerInfo::servesIpAndPort()` for loops), with the identical three
states. It is applied to:

- `Nginx::createIpPort()` — a node never emits `listen` statements or ip/port
  config files for another node's IPs (nginx would fail to bind a foreign IP).
- `Nginx::getVhostContent()` + the ssl-redirect destination-port lookup and the
  SSL-cert lookup in `WebserverBase` — for a domain assigned to IPs on
  *several* nodes, each node only renders its own ip:port into the vhost.
  Note: such a cross-node domain must have an IP of **every** assigned kind
  (non-ssl / ssl) on each node, otherwise the vhost on the node missing one
  renders with an empty listen list.

## What the slave cron does each run

1. Bail out if `local_ips` is empty (not a multi-server setup).
2. Acquire a **per-machine** lock (`/run/lock/froxlor_cron-slave.lock`) so two
   slave-cron instances never run on the same box at once. Stale locks whose PID
   is dead are auto-recovered.
3. Mark the run as a slave node (`ServerInfo::setSlaveNode(true)`) so node-global
   actions (the Froxlor panel certificate, domainid 0) stay on the master.
4. Refresh system users (libnss-extrausers) — **everywhere, unfiltered**.
5. Ensure customer home directories — **everywhere, unfiltered** (create-only;
   existing homes are skipped to avoid the expensive recursive chown). The mail
   base directory (`vmail_homedir/<loginname>/`) is **not** created on slave
   nodes: `ensureCustomerHomes()` calls `createNewHome(..., $createMaildir=false)`,
   so a slave manages only the web home + stats dir, never mail storage.
6. Set filesystem quota — **everywhere, unfiltered**.
7. Rebuild webserver config (vhosts + php-fpm) — **IP-scoped** to this node's
   domains. This also runs the Let's Encrypt cron (via `HttpConfigBase::init()`)
   for the node's own domains, and finalizes the `ssl_redirect 2 → 3` handshake
   for those domains only.

The webserver rebuild wipes the whole vhost directory (`ConfigIO::cleanUp()`) and
regenerates only the IP-scoped subset, so a domain that no longer belongs to this
node loses its vhost automatically.

`panel_fpmdaemons` is shared across all nodes, but not every php version is
installed everywhere: during the reload step a node **skips** every fpm daemon
whose `config_dir` (e.g. `/etc/php/8.2/fpm/pool.d/`) does not exist locally,
instead of running a failing restart and creating dummy pools for daemons it does
not have. Corollary: domains served by this node must only use php versions that
are actually installed here — that mapping is the admin's responsibility.

## Master / slave split for node-global work

Some actions must happen **exactly once**, on the master:

- **The Froxlor panel certificate** (domainid 0): `AcmeSh::issueFroxlorVhost()` /
  `renewFroxlorVhost()` return early when `ServerInfo::isSlaveNode()` is true, so
  the panel cert is only ever issued by the master.
- **The Froxlor panel vhost + its PHP config**: slaves do not serve the panel.
  The `vhostcontainer` block in `Nginx::createIpPort()` and
  `NginxFcgi::createOwnVhostStarter()` (panel fpm pool, including its
  `chown -R` over the install dir) are skipped when
  `ServerInfo::isSlaveNode()` is true.
- **The `panel_tasks` queue**: only `froxlor:cron` consumes it. All *deletion*
  (customer files, mail data, FTP data, DNS, SSL directory cleanup) flows through
  the queue and therefore runs on the master only.

Customer certificates, by contrast, are issued per node (IP-scoped) and their
data is written back to the shared DB, but only for that node's own domains.

---

## Two important rules

The design is lock-free and consistent **only while both of these hold**. They
are *not* enforced by code — the admin must guarantee them operationally.

### Rule 1 — Each IP belongs to exactly one node (clean IP → node partition)

Every IP in `panel_ipsandports` must be served by exactly one physical node, and
each domain's IP(s) must all live on that same node. Do **not**:

- assign a domain IPs that live on two different nodes (multi-homed across nodes), or
- put the same IP in the `local_ips` of two nodes (shared / floating / anycast IP).

If this is violated, two nodes match the same domain via `ipFilterSql()` and both
issue Let's Encrypt certificates for it concurrently — burning LE rate limits and
racing writes to the same `panel_domain_ssl_settings` row, with no cross-server
lock to protect it. IP scoping replaces cross-server locking; it only works when
the IP → node mapping is a clean partition.

### Rule 2 — Exactly one master; the master never runs the slave cron

Exactly one node is the master. It runs `froxlor:cron` (and only that) and is the
sole consumer of `panel_tasks`. Every other node runs `froxlor:cron-slave` (and
only that). Specifically:

- The **master runs `froxlor:cron` only** — never `froxlor:cron-slave`.
- Each **slave runs `froxlor:cron-slave` only** — never `froxlor:cron`.

If `froxlor:cron` runs on more than one node, both consume and `DELETE` the same
task rows (racing, double execution) and both try to issue the panel certificate.
There is no leader election and no DB-level guard enforcing a single master, but
the rule is enforced per node via the `role` config flag: a node with `local_ips`
set refuses `froxlor:cron` unless it is marked `role => 'master'`, and the master
refuses `froxlor:cron-slave`. A standard-installer-provisioned slave (whose
`/etc/cron.d/froxlor` calls `froxlor:cron`) therefore fails loudly instead of
silently consuming the master's queue.

### Consequences to be aware of (not bugs, but by design)

- **Deletion is master-only.** The slave cron only ever *creates* home
  directories; it never removes them. When a customer is deleted, the master
  removes their files on the master, but their home dir remains on every slave
  node. Clean these up out-of-band if it matters (disk / data retention). Mail
  storage is not created on slaves (see step 5), so no mail data accumulates
  there from the slave cron.
- **`ssl_redirect` finalization is per node.** A domain's `2 → 3` redirect
  handshake only completes when *its* node's slave cron runs; if that node's cron
  is down, the redirect never finalizes even if the certificate exists.
- **Homes/users/quota exist on every node.** Because those steps are unfiltered,
  every customer's home and quota entry is created on every node, even nodes that
  never serve that customer's web traffic.
