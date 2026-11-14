# cPanel/WHM Pro for PNLCS

Everything the built-in **cPanel/WHM** server module does, plus:

| Where | What you get |
|---|---|
| **Client's service page** | **Log in to control panel**: opens the client's own cPanel, already signed in (a one-time WHM link, no password) |
| **Client's service page** | **Live usage**: disk used / quota and bandwidth this month, read live from WHM |
| **Admin service page** | **Log in to cPanel as `<username>`** and **Log in to WHM**, each recorded in the activity log |
| **Live Servers page** | A **cPanel/WHM Pro servers** box with one-click **Log in to WHM** and live **server health** (load, disk, account count) per server |
| **Client portal: 8 hosting tools** | File Manager, Email Accounts, Databases, FTP Accounts, Subdomains, DNS Zone, Cron Jobs, Backups (details below) |
| **Client portal: 9 more tools** *(1.2–1.3)* | SSL / HTTPS, PHP Settings, Email Extras, Webmail, phpMyAdmin, Domains, File Tools, Error Log, IP Blocker (details below) |
| **WordPress** *(1.4)* | Everything in cPanel's **WP Toolkit**, in the client portal: install, one-click wp-admin login, plugins and themes (search WordPress.org, install, upload .zip, activate, auto-update, delete), updates with restore points, security hardening, vulnerability checks, backups and restore, cloning/staging, maintenance mode and more (details below) |
| **Usage warnings** *(1.3)* | A daily check emails the client at **80%** and **95%** of their disk or monthly bandwidth, and fires the `HostingUsageWarning` hook, so [Flowsensy Notification](https://github.com/shiwang-biz/pnlcs-flowsensy-notification) 1.1+ can send it on WhatsApp too |
| **Client's service page** *(1.2)* | The "Soon" tiles (apps, Laravel, Node.js, Python, which only Panelica servers have) are hidden, so clients see only tools that work |

Create, suspend, unsuspend, terminate, change password, change package, usage sync, package list and the
connection test are unchanged: they're the built-in cPanel module's own code.

> ### 🧩 Install modules from your admin, with no SSH: [PNLCS Module Manager](https://github.com/shiwang-biz/pnlcs-module-manager)

## Install

**Module Manager (recommended):** **Extensions → Module Manager** → upload **CPanelPro-pnlcs-module.zip** → review →
**Install**. Then **Setup → Modules**: make sure **cPanel/WHM Pro** is on.

**Manual:** extract the zip into `modules/Servers/` so you have `modules/Servers/CPanelPro/pnlcs.json`, then run
`php artisan optimize:clear`.

## Switch a WHM server over

1. **Setup → Servers → Edit** your WHM server → **Type: cPanel/WHM Pro**. Port 2087, username (usually `root`)
   and API token stay as they are. **Test connection.**
2. **Setup → Products → edit each cPanel product → Module: cPanel/WHM Pro**, with the same server/group and WHM package.

Both are needed: PNLCS picks the module from the **server's** type, and new orders look for servers whose type
matches the **product's** module.

Existing accounts aren't touched or re-created. They're the same WHM accounts, now with the extra buttons.
To go back, set both to **cPanel/WHM** again.

## WHM API token

A token made by `root` with all privileges works. With a restricted or reseller token, it needs:

- `create-user-session`: the login buttons (cPanel, phpMyAdmin, webmail)
- `list-accts`: usage (disk, bandwidth)
- `cpanel-api`: the client hosting tools (they run as the client's own cPanel user)
- for the Live Servers health: `list-accts` plus server status (`systemloadavg`, `getdiskusage`); without it the
  box shows "n/a" and everything else still works
- the privileges the built-in module already needs for creating and managing accounts

## How it stays safe

- Each client's login link and usage are always for **that service's own cPanel username**, checked by PNLCS
  (the client must own the service, and suspended or cancelled services are refused).
- Links come from WHM's `create_user_session`: single-use and short-lived. No password is stored or shown.
- Staff links need the **manage services** permission (cPanel) or **manage servers** permission (WHM).

## The hosting tools (client portal)

The same screens PNLCS shows for Panelica accounts, working on the client's cPanel account:

| Tool | What the client can do | How (cPanel API) |
|---|---|---|
| **File Manager** | Browse, edit text files, create files/folders, rename, delete (to cPanel's trash), upload, download | UAPI `Fileman`, API2 `Fileman::fileop`, a one-time cPanel session for upload/download |
| **Email Accounts** | Create/delete mailboxes, change passwords, open webmail (port 2096) | UAPI `Email` |
| **Databases** | Create MySQL databases with a user, add users to a database (read / read-write / full), change passwords, delete | UAPI `Mysql` (cPanel's `user_` prefix is added automatically) |
| **FTP Accounts** | Create (home folder `public_html/<name>`), change password, delete (files kept) | UAPI `Ftp` |
| **Subdomains** | Create under any of the account's domains, delete (files kept) | API2 `SubDomain` |
| **DNS Zone** | A, AAAA, CNAME, MX, TXT, SRV and CAA records: add, edit, delete | API2 `ZoneEdit` |
| **Cron Jobs** | Add (presets or custom schedule) and delete | API2 `Cron` |
| **Backups** | Start a full account backup (saved in the home folder, cPanel emails when done), list, delete | UAPI `Backup`, `Fileman` |

**Kept safe:** every call runs through WHM *as the client's own cPanel user*, so cPanel itself confines it to that
account. On top of that, every domain, mailbox, database, FTP login, DNS record and file path sent from the browser
is checked against the account's own lists first, and paths must stay inside `/home/<user>`.

**Locked on purpose** (shown, but not editable here): the zone's SOA and NS records, and the `A`/`AAAA` records that
keep the site and mail online (`@`, `www`, `mail`, `ftp`, `cpanel`, `webmail`, `autodiscover` …). The account's main
FTP login and system mailbox aren't listed. Restoring a backup stays in cPanel.

**What cPanel can't do here:** pause a cron job or run it on demand (those buttons explain this).

### More tools (1.2)

Shown as extra tiles in the same tools grid (or as a "More tools" section if the grid isn't there):

| Tool | What the client can do | How (cPanel API) |
|---|---|---|
| **SSL / HTTPS** | See each domain's certificate (issuer, expiry, warnings under 15 days), run AutoSSL now, turn "always use HTTPS" on or off | UAPI `SSL` |
| **PHP Settings** | Choose the PHP version per domain (only versions installed on the server), set memory limit, upload size, POST size, execution/input time, input vars | UAPI `LangPHP` (MultiPHP) |
| **Email Extras** | Forwarders, autoresponders (out of office, with start/end dates), catch-all: bounce, discard or forward | UAPI `Email` |
| **Webmail** | Open any of the account's mailboxes in webmail, already signed in | WHM `create_user_session` (webmaild) |
| **phpMyAdmin** | Opens straight into phpMyAdmin, already signed in | WHM `create_user_session` (app `Database_phpMyAdmin`) |
| **Domains** | Add an addon domain (folder kept inside home), remove it (files kept), add/remove aliases (parked domains) | API2 `AddonDomain`, `Park` |
| **File Tools** *(1.3)* | Extract archives (.zip, .tar.gz …) in place, compress selected files/folders into zip/tar.gz/tar.bz2/tar, set permissions (3 digits like 644/755) | API2 `Fileman::fileop` (extract, compress, chmod) |
| **Error Log** | The latest website errors per domain, newest first | UAPI `Stats::get_site_errors` |
| **IP Blocker** | Block an IP, a CIDR range (/16 or narrower) or an IPv4 range; unblock | API2 `DenyIp` |

Same rules as the other tools: the client must own the service, it must not be terminated or cancelled, and a
suspended service can be looked at but not changed. Everything sent from the browser is checked against the account's
own domains, mailboxes, forwarders and block list.

### WordPress (1.4)

Needs **WP Toolkit 6.x** on the WHM server (it ships with cPanel; WP Toolkit Deluxe adds Smart Updates and
vulnerability protection). A **WordPress** tile opens the client's sites; each site has these tabs:

| Tab | What the client can do |
|---|---|
| **Sites list** | Install WordPress on any of their domains (optional folder, title, admin login/email/password, auto-update policy, a plugin/theme **set** you prepared in WP Toolkit), scan for sites installed another way, see version, updates, security and vulnerability status at a glance, **Log in** to wp-admin with one click |
| **Overview** | Maintenance mode, search engine indexing, debugging, hotlink protection, Smart Updates, vulnerability protection (virtual patching), password protection for the whole site, clear cache, install a set |
| **Plugins / Themes** | Search WordPress.org and install (and activate), upload a premium .zip (up to 64 MB), activate / deactivate, turn auto-updates on/off, delete (never the active theme) |
| **Updates** | Check now, update WordPress / plugins / themes selectively (a restore point is made first), automatic update policy |
| **Security** | Hardening measures (apply / revert, recommended ones pre-selected), known vulnerabilities with risk level and fixed-in version |
| **Backups** | Back up files and database, restore, delete |
| **Clone** | Copy the site to a new subdomain (e.g. `staging.`) or an existing domain, with a new database |
| **Settings** | The WordPress administrator's email, password and site language |

Long jobs (install, updates, backups, clone, security) run in WP Toolkit's background; the page shows their progress
and refreshes when they finish.

**How it stays safe:** every call goes to WP Toolkit's REST API (`/3rdparty/wpt/index.php/v1`) in a one-time cPanel
session **of the client's own cPanel user** (WHM `create_user_session`), so WP Toolkit itself only lets them see and
change their own sites. On top of that, every site id, domain, plugin/theme slug, backup file and security measure from
the browser is checked against the account's own lists; site paths must be inside `/home/<user>`. The admin password
WP Toolkit stores is never sent to the browser. Removing a WordPress site stays in cPanel's WP Toolkit (the REST API has
no removal call).

### Usage warnings (1.3)

Runs every day at 09:17 through PNLCS's own scheduler (the cron you already have; nothing to set up). For each active
cPanel/WHM Pro service it reads disk and bandwidth for the whole server in two WHM calls (`listaccts`, `showbw`) and,
at 80% and again at 95%:

- emails the client (subject like "Disk space 83% used: example.com"), and
- fires the hook `HostingUsageWarning` with `service`, `client` and `usage` (`resource`, `percent`, `used`, `limit`, `level`).

Each level is sent once: disk again only after usage drops below 80%, bandwidth once per level per month. Unlimited
accounts are skipped. Run it by hand with `php artisan cpanelpro:usage-check`.

**WhatsApp:** in Flowsensy Notification (1.1+), add a rule for **Hosting usage warning (disk / bandwidth)** and map
the template's variables to `{client.first_name}`, `{usage.resource}`, `{usage.percent}`, `{usage.used}`, `{usage.limit}`,
`{service.domain}` and so on.

## Files

```
CPanelPro/
├── pnlcs.json                    server module "cpanelpro" + service provider
├── CPanelProModule.php           extends the built-in CPanelModule: ssoLogin, liveUsage, operatorLogin, admin panel
├── CPanelTools.php               the 8 hosting tools (trait)
├── CPanelExtras.php              SSL, PHP, email extras, domains, file tools, error log, IP blocker, phpMyAdmin, webmail (trait)
├── ExtrasController.php          the client pages for those extras (and File Tools)
├── WpToolkit.php                 WordPress via WP Toolkit's REST API (trait)
├── WordPressController.php       the client's WordPress pages
├── UsageAlerts.php               the daily 80% / 95% disk and bandwidth check
├── UsageWarningMail.php          its email; UsageCheckCommand.php: `cpanelpro:usage-check`
├── CPanelProController.php       staff cPanel / WHM login links, server health
├── CPanelProServiceProvider.php  routes, views
├── hooks.php                     server form settings, Live Servers box, "cPanel/WHM Pro" label, hides "Soon" tiles
├── views/
└── tests/CPanelProModuleTest.php copy to tests/Feature/ in a PNLCS checkout to run
```
