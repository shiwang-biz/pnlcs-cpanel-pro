# cPanel/WHM Pro for PNLCS

Everything the built-in **cPanel/WHM** server module does, plus:

| Where | What you get |
|---|---|
| **Client's service page** | **Log in to control panel**: opens the client's own cPanel, already signed in (a one-time WHM link, no password) |
| **Client's service page** | **Live usage**: disk used / quota and bandwidth this month, read live from WHM |
| **Admin service page** | **Log in to cPanel as `<username>`** and **Log in to WHM**, each recorded in the activity log |
| **Live Servers page** | A **cPanel/WHM Pro servers** box with one-click **Log in to WHM** per server |
| **Client portal: 8 hosting tools** | File Manager, Email Accounts, Databases, FTP Accounts, Subdomains, DNS Zone, Cron Jobs, Backups (details below) |

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

- `create-user-session`: the login buttons
- `list-accts`: usage (disk, bandwidth)
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
| **DNS Zone** | A, AAAA, CNAME, MX and TXT records: add, edit, delete | API2 `ZoneEdit` |
| **Cron Jobs** | Add (presets or custom schedule) and delete | API2 `Cron` |
| **Backups** | Start a full account backup (saved in the home folder, cPanel emails when done), list, delete | UAPI `Backup`, `Fileman` |

**Kept safe:** every call runs through WHM *as the client's own cPanel user*, so cPanel itself confines it to that
account. On top of that, every domain, mailbox, database, FTP login, DNS record and file path sent from the browser
is checked against the account's own lists first, and paths must stay inside `/home/<user>`.

**Locked on purpose** (shown, but not editable here): the zone's SOA and NS records, and the `A`/`AAAA` records that
keep the site and mail online (`@`, `www`, `mail`, `ftp`, `cpanel`, `webmail`, `autodiscover` …). The account's main
FTP login and system mailbox aren't listed. Restoring a backup stays in cPanel.

**What cPanel can't do here:** pause a cron job or run it on demand (those buttons explain this), and SRV/CAA DNS
records (use cPanel's Zone Editor via **Log in to control panel**). phpMyAdmin opens from cPanel.

## Files

```
CPanelPro/
├── pnlcs.json                    server module "cpanelpro" + service provider
├── CPanelProModule.php           extends the built-in CPanelModule: ssoLogin, liveUsage, operatorLogin, admin panel
├── CPanelProController.php       staff cPanel / WHM login links
├── CPanelProServiceProvider.php  routes, views
├── hooks.php                     server form settings, Live Servers box, "cPanel/WHM Pro" label
├── views/
└── tests/CPanelProModuleTest.php copy to tests/Feature/ in a PNLCS checkout to run
```
