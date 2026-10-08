# vps_router

**English** · [Русский](README.ru.md)

**vps_router** is a PHP web panel that turns an ordinary VPS into a "smart router"
for traffic. You connect your devices to it (phone, laptop, TV, a home Keenetic
router), and the panel decides **which traffic goes where**: part of it goes
directly from the VPS itself, while selected sites (e.g. YouTube, Claude, etc.) go
through an intermediate "exit server" in another country. Everything is configured
with the mouse on a visual graph, without editing configs by hand.

> In short: one entry point, rule-based selective routing, a clear server graph,
> several connection protocols and multiple routers, and a built-in setup wizard
> right in the browser.

---

## Why you'd want it

- **Selective routing.** Don't push all traffic through a remote server (that's
  slower) — only the domains/subnets you specify. Everything else goes directly and
  fast.
- **One panel instead of a dozen configs.** Servers, tunnels, routing rules and
  devices — all in a single web interface. Click "Apply" and the panel assembles the
  sing-box config itself, lays it out into files and restarts the service.
- **Visual clarity.** The infrastructure is drawn as a graph: servers, the links
  between them, server sets with automatic failover, routes.
- **Many devices and protocols.** Devices connect over VLESS+Reality (disguised as
  ordinary HTTPS), Shadowsocks, Trojan, Hysteria2 or WireGuard/AmneziaWG.
- **Keenetic router out of the box.** Route export for a home router and "smart DNS"
  so that devices behind the router (e.g. a Smart TV) work correctly.

---

## How it works (in plain words)

```
   Your devices                 Entry VPS (panel + sing-box)            Exit servers
 ┌───────────────┐            ┌──────────────────────────────┐      ┌────────────────┐
 │ Phone         │            │                              │      │  Region A      │
 │ Laptop        │  VLESS/WG  │   sing-box (routing engine)  │──────│  Region B      │
 │ TV            │──────────▶ │   ├─ rule: youtube→exit      │ WG/  │  (any from     │
 │ Router        │            │   ├─ rule: claude→exit       │ VLESS│   the pool)    │
 └───────────────┘            │   └─ everything else → direct│      └────────────────┘
                              │                              │
                              │   PHP panel (SQLite)         │──▶ you manage it
                              └──────────────────────────────┘     from a browser
```

1. **A device** connects to the entry VPS over the chosen protocol.
2. **sing-box** on the VPS is the only engine. It looks at the domain/address of each
   connection and, by the rules from the database, decides whether to send it directly
   or into a tunnel to an exit server.
3. **The panel** does not route anything itself — it only generates the sing-box config
   (and WireGuard/AmneziaWG configs) from the database and applies it with a single
   privileged command. Switching a set's exit server is an instant change of a "label"
   in the config, not a tunnel rebuild.
4. Tunnels to exit servers stay up permanently, so switches happen without pauses.

<details>
<summary><b>📁 What each file is responsible for (click to expand)</b></summary>

### Repository root

| Path | Purpose |
|------|---------|
| `README.md` | this file |
| `LICENSE` | license |
| `deploy/` | everything for installing on servers (scripts, nginx, sudoers, provisioning) |
| `panel/` | the PHP panel code itself (deployed to the VPS at `/var/www/panel`) |

### `panel/` — panel code

| Path | Purpose |
|------|---------|
| `composer.json` / `composer.lock` | PHP dependencies (see the "Software" spoiler) |
| `config.php.example` | config template → copied to `/etc/panel/config.php` outside the webroot |
| `phpunit.xml` | test configuration |
| `public/` | webroot — what the browser sees |
| `src/` | application classes (logic) |
| `src/Models/` | data models (working with SQLite tables) |
| `migrations/` | SQL schema migrations (applied in order) |
| `bin/` | CLI scripts (cron, maintenance) |
| `lang/` | interface translations (`ru.php`, `en.php`) |
| `tests/` | PHPUnit tests |

### `panel/public/` — pages (webroot)

| File | Purpose |
|------|---------|
| `index.php` | entry point / redirect |
| `install.php` | **visual setup wizard** (available until an admin exists) |
| `login.php`, `logout.php` | sign in/out |
| `forgot-password.php`, `reset-password.php` | password recovery |
| `dashboard.php` | **main screen — infrastructure graph** + live KPIs (rate, devices online, uptime, exit health) |
| `servers.php` | servers and exit servers |
| `groups.php`, `group.php` | routes (rule groups) + presets, external-list import, Route Inspector, Keenetic/HydraRoute export |
| `devices.php`, `device-config.php` | devices (icons by type, status, traffic ↓↑) and the add wizard, config/link delivery |
| `traffic.php` | traffic: total / today / 30 days, by exit, by device |
| `connections.php` | live sessions (country flags, traffic, exit, connection age) |
| `logs.php` | sing-box journal viewer (journalctl) |
| `settings.php` | settings: protocols, ad/QUIC blocking, smart DNS, privacy, password/2FA change |
| `client-policy.php` | client policies |
| `wg-peers.php`, `wg-peer-config.php` | WireGuard peers and their configs |
| `audit.php` | action log |
| `api/*.php` | JSON API for the graph and pages (servers, connections, routes, traffic, keenetic-export, etc.) |
| `assets/` | static files: fonts, graph JS (Cytoscape.js), styles |

### `panel/src/` — key classes

| File | Purpose |
|------|---------|
| `bootstrap.php` | initialization (autoload, config, session, i18n) |
| `App.php` | access to the app config |
| `Database.php` | SQLite connection (WAL mode, migrations) |
| `Auth.php`, `Totp.php` | authentication, two-factor (TOTP) |
| `Secrets.php` | encryption of secrets (servers' SSH keys) with `app_secret` |
| `Ssh.php` | SSH client (phpseclib) — connection test, provisioning |
| `SingboxConfigBuilder.php` | **builds the sing-box config** from the database; orchestrator over `Singbox\*` (rules, DNS, inbound/outbound) |
| `Singbox/*` | modular builders: `ExitOutboundBuilder`, `RuleSetRegistry`, `RouteBuilder`, `DnsBuilder`, `InboundsBuilder`, `ConfigValidator` (pre-validation of references + version warnings) |
| `SingboxVersion.php`, `SingboxLog.php` | version of the installed sing-box, journal reading (journalctl) |
| `RouteInspector.php`, `RoutePresets.php` | "where a domain/IP will go" + curated geosite presets (youtube, google, etc.) |
| `ExternalListImporter.php` | external-list import (Antizapret/URL), optional fetch via exit (SOCKS), SSRF guard |
| `HydraRouteExport.php` | route export for HydraRoute (`domain.conf` + `ip.list` by policy) |
| `Amnezia/AwgParams.php`, `Amnezia/AwgKernel.php` | AmneziaWG obfuscation validation, awg-quick/kernel-module detection |
| `LocalSystem.php` | info about the node itself without SSH (load, sing-box status, public IPv6) |
| `AmneziaConfigBuilder.php` | builds AmneziaWG/WireGuard configs |
| `Applier.php` | preview/validate/**apply**/roll back the configuration |
| `DeviceInbounds.php` | device inbound protocols (VLESS/SS/Trojan/Hysteria2/WG) |
| `ClientPolicyBuilder.php` | client-level routing policies |
| `RouterContext.php` | current router context (multi-router) |
| `Provisioner.php` | auto-install software on exit servers over SSH/SFTP |
| `ExitServerFactory.php`, `ExitServerBalancer.php` | creation/balancing of exit servers |
| `KeeneticExport.php` | route export for the router (`.bat` with `route ADD`, ZIP splitting) |
| `KeeneticSyncService.php` | route sync with Keenetic via API |
| `TrafficCollector.php`, `ServerTraffic.php` | device/server traffic accounting |
| `Installer.php` | setup/restore wizard logic |
| `PanelBackup.php` | backup of panel settings |
| `Diagnostics.php`, `BlockChecker.php`, `NetworkInfo.php` | reachability diagnostics, IP detection |
| `RiskScanner.php`, `SecurityAudit.php` | risk/security scanners |
| `IpListImporter.php`, `FreeSubscriptions.php` | IP-list import, free subscriptions/pools |
| `I18n.php`, `View.php` | localization and page rendering |
| `Http.php`, `Mailer.php` | HTTP helpers, mail sending |

### `panel/src/Models/` — DB models

`Server`, `ExitServer`, `ExitServerPeer`, `ExitServerLoad`, `Connection`,
`ServerSet`, `RuleGroup` / `Rule` (routes), `Client` (device),
`PolicyProfile` / `PolicyDevice` / `PolicyVersion` (policies), `ConfigVersion` (config versions),
`NodeSetting` / `Setting` (node/global settings), `AuditLog`, `RiskScanResult`.

### `panel/bin/` — CLI and cron

| File | Purpose |
|------|---------|
| `create-admin.php` | create/reset the administrator |
| `collect_traffic.php` | collect device traffic (cron) |
| `collect_exit_load.php` | collect exit-server load (cron) |
| `sync-ip-lists.php` | update IP-subnet lists (cron) |
| `free_pool_health.php`, `health_check.php`, `health_check_servers.php` | health checks |
| `risk_scan.php`, `security_scan.php` | risk and update scans (cron) |
| `encrypt-exit-secrets.php`, `fix-reality-key-encoding.php` | one-off maintenance scripts |

### `deploy/` — installing on servers

| Path | Purpose |
|------|---------|
| `install.sh` | **interactive installer** on a fresh VPS (`sudo bash deploy/install.sh`) |
| `router/` | everything for a router node: `apply-router.sh`, sudoers, nginx vhost, firewall, hardening, README |
| `exit/` | exit-node setup guide |
| `exit-front/` | exit with a disguising TLS front (reality-front) |
| `provision/` | auto-install scripts: software install on a node, `router-apply.sh`, `exit-*.sh`, hardening, wg peers |

### `migrations/` — DB schema (in order)

`001` base · `002` infrastructure (graph) · `003` multi-protocol · `004` route
hierarchy · `005` wg peers · `006` port-knock · `007` client policies · `008`
risk-scan / scanner ban · `009` login security · `010` exit-server pool and load ·
`011` device inbounds · `012` device traffic · `013` traffic total/quotas · `014`
user language · `015` multi-router · `016` free exits · `017` two-factor (TOTP).

</details>

<details>
<summary><b>🧩 Which additional software is used (click to expand)</b></summary>

### On the server (installed automatically by the installer)

| Software | Why | Required? |
|----------|-----|-----------|
| **PHP 8.1+** (php-fpm) + extensions `pdo_sqlite`, `sodium`, `mbstring`, `curl`, `xml` | the panel itself | yes |
| **SQLite** (via `ext-pdo_sqlite`) | the panel's database, no separate DB server needed | yes |
| **sing-box** | the routing engine (the only one) | yes |
| **nginx** | web server for the panel (a separate vhost) | yes |
| **AmneziaWG / WireGuard** (`amneziawg-tools`) | tunnels to exit servers and WG inbound | optional* |
| **certbot** | free TLS certificate for the panel's domain | optional |
| **fail2ban** | brute-force protection (recommended) | optional |

\* Without AmneziaWG the other protocols (VLESS, Shadowsocks, Trojan, Hysteria2) still work.

### PHP dependencies (composer)

| Package | Why |
|---------|-----|
| `phpseclib/phpseclib` | SSH/SFTP client: connection test and auto-provisioning (not via `shell_exec`) |
| `phpunit/phpunit` (dev) | tests |

### Client side (already vendored in the repo, npm not needed)

- **Cytoscape.js** + **edgehandles** — rendering of the infrastructure graph. They live in
  `panel/public/assets/vendor/`, no build step required.

</details>

---

## Installation — what to click where

You need a **fresh VPS** (Ubuntu/Debian or CentOS/RHEL-compatible) with root access.
A domain is optional — the panel can run over an IP.

### Step 1. Upload the code and run the installer

Copy the repository to the server (via `git clone` or `scp`) and run:

```bash
sudo bash deploy/install.sh
```

The installer will ask a few questions (you can just press Enter for the defaults):

1. **Language** — `ru` or `en`.
2. **Domain or IP** — if you have no domain, leave it empty: the panel opens on the
   server's IP.
3. The rest is automatic: it installs PHP+extensions, sing-box, (optionally) AmneziaWG,
   deploys the code to `/var/www/panel`, creates `/etc/panel/config.php`, sets up the
   nginx vhost and sudoers, and applies the DB migrations. If a domain is present it
   will offer to issue an **HTTPS certificate**.

> The installer **does not touch existing sites** — the panel lives in its own vhost,
> and the device inbound (Reality) is on a separate IP/port that you set in the wizard.

At the end it prints the address to open in the browser.

### Step 2. The visual wizard in the browser

Open the shown address (`https://your-domain` or `http://IP`). The
**setup wizard** (`install.php`) starts. Step by step:

1. **Fresh install** OR **restore from a settings backup** (if you're migrating).
2. IP auto-detection, generation of **Reality keys**, choice of connection protocols.
3. Creating the **administrator login and password**.

After it finishes, the wizard files are **removed automatically**; from then on you
sign in via `login.php`.

> If the panel runs on an IP with no domain, it works over HTTP. For HTTPS, attach a
> domain and later run `certbot --nginx -d your-domain`.

---

## After installation — what to configure

Sign in to the panel as administrator and go through the steps.

### 1. Add an exit server (where to divert traffic)

`Servers` → add an exit server (any region of your choice). Supported protocols:
`amneziawg`, `wireguard`, `vless`, `shadowsocks`, `hysteria2`, `tuic`, `trojan`.
The panel can **install the software itself** on the exit over SSH (provisioning) —
just provide server access; the SSH key is stored encrypted in the database.

### 2. Check the device inbound protocols

`Settings` → "Device connection protocols". Enable what you need (VLESS+Reality is
recommended). The panel tells you which ports to open in the host's firewall.

### 3. Create routes (what to send through the exit)

`Routes` → create a group and rules: domains (`youtube.com`), subnets (`ip_cidr`),
geosite categories. Attach the group to an exit server or to a **server set** with
automatic failover. Anything not matched by a rule goes directly from the VPS.

### 4. Add a device

`Devices` → "Add device wizard": pick the type (Phone/Tablet/Computer/Router) and the
protocol — the panel issues a link/QR/config file. If the protocol is disabled, the
wizard offers to enable it.

### 5. Apply the configuration

The **"Apply"** button. The panel assembles the sing-box config + tunnels, validates
it and restarts the service. There is a preview and a roll back to the previous version.

### Useful things after launch

- **Smart DNS** (`Settings`) — DNS interception and resolving exit-bound domains through
  the exit. Fixes the case where a site won't open due to DNS substitution (e.g. YouTube
  on a Smart TV).
- **Ad / QUIC blocking** (`Settings`) — blocks ad domains and QUIC (removes video stalls
  through the proxy).
- **Keenetic router**: on the `Routes` page — export buttons. A `.bat` with `route ADD`
  commands (domains are resolved to IPs, the file is split automatically into parts by
  the router's 1024-route limit — if there are several parts, a ZIP is downloaded).
  Next to it — HydraRoute export (`domain.conf` + `ip.list` by policy).
- **Presets and external lists** (`Routes`) — quick geosite sets (YouTube, Google, etc.)
  and import of external lists (Antizapret/URL). If the source site itself is not
  directly reachable, enable fetching the list through the exit (SOCKS).
- **Route Inspector** (`Routes`) — checks "where will this domain/IP go": through which
  rule and which exit.
- **Live data**: `Dashboard` — rate/devices online/uptime/exit health; `Connections` —
  active sessions; `Traffic` — totals and breakdown; `Logs` — the sing-box journal.
- **Pre-apply warnings**: the preview flags broken references in rules, incompatibility
  with the sing-box version, questionable AmneziaWG parameters and the risk of an IPv6
  leak (if the server has a public IPv6 while the exits are IPv4-only).
- **Two-factor**: `Settings` → enable TOTP for sign-in.
- **Cron** (recommended), examples:
  ```cron
  */5 * * * *  php /var/www/panel/bin/collect_traffic.php
  0 * * * *    php /var/www/panel/bin/collect_exit_load.php
  30 3 * * *   php /var/www/panel/bin/sync-ip-lists.php
  0 */6 * * *  php /var/www/panel/bin/security_scan.php
  0 */6 * * *  php /var/www/panel/bin/check_updates.php
  ```
- **Update check** (`Settings` → "Updates"): point it at the RAW URL of your repo's
  version file (`panel/VERSION` or a release JSON) and the repository address. The panel
  compares versions and shows an "update available" banner. Updating the code is a deploy
  of a new version; DB migrations are applied automatically afterwards.
- **Admin password reset** (if forgotten), on the server:
  ```bash
  php /var/www/panel/bin/create-admin.php <login>
  ```

---

## Things you might need / FAQ

- **No domain.** Fine — the panel works over an IP via HTTP. For HTTPS you need a domain
  + `certbot`.
- **A device behind the router won't open a site (DNS error).** The device must be added
  to the tunnel on the router itself (routing policy), and enable "Smart DNS".
- **Some traffic bypasses the tunnel (IPv6 leak).** If the server/devices have a public
  IPv6 while the exits are IPv4-only, IPv6 traffic may go directly. The panel warns about
  this in the preview; the fix is to disable IPv6 on the clients or use an exit with IPv6.
- **"Database is locked".** The panel already runs in WAL mode with a timeout; on bulk
  operations just retry. If PHP cached old code — restart php-fpm.
- **Changing `app_secret`.** NOT allowed on a populated database — it makes the stored SSH
  keys undecryptable. Guard it like the root password.
- **Backup.** Settings can be exported and restored through the wizard (`Settings` →
  export; at install time — "restore from backup").

---

## Development and tests

```bash
cd panel
composer install            # dependencies (+dev)
vendor/bin/phpunit          # run tests
```

The panel code uses PSR-4 autoloading (`App\` → `src/`). The only routing engine is
sing-box; the panel merely generates its config and applies it with a single argument-less
sudo command.

---

## Support the project

The project is developed in spare time. If you find it useful, you can support it:

### ❤ [Boosty — boosty.to/iygen/donate](https://boosty.to/iygen/donate)

Any donation is voluntary and grants no right to priority support; it's just a "thank
you" to the author. Thanks! 🙏

## License

Distributed under the **GNU Affero General Public License v3.0 (AGPLv3)** — see
[`LICENSE`](LICENSE).

In short: you may freely use, study and modify it. But if you modify the panel and
**provide it to others as a network service**, you must publish the source code of your
version under the same license. This protects the project from closed commercial
re-use without contributing back.

© 2026 Ygen.
