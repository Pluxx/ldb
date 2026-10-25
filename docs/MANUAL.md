# LDB User Manual

Version 0.1 (beta). This manual is for administrators who install and configure LDB and for
people who use it to work with databases. Interface labels are written in **bold** exactly as
the English interface shows them. Other languages translate the same labels.

## Table of contents

1. [Introduction](#1-introduction)
2. [Installation](#2-installation)
3. [Configuration](#3-configuration)
4. [Logging in](#4-logging-in)
5. [The interface](#5-the-interface)
6. [Working with data](#6-working-with-data)
7. [SQL editor](#7-sql-editor)
8. [Structure, routines, triggers and events](#8-structure-routines-triggers-and-events)
9. [Databases, diagram, search and schema diff](#9-databases-diagram-search-and-schema-diff)
10. [Import and export](#10-import-and-export)
11. [Server administration](#11-server-administration)
12. [Security features for administrators](#12-security-features-for-administrators)
13. [Troubleshooting](#13-troubleshooting)
14. [Getting help](#14-getting-help)

---

## 1. Introduction

LDB is a web-based database administration tool and a replacement for phpMyAdmin. It runs on a
plain PHP backend with a prebuilt frontend, needs no database of its own and has no runtime
Composer dependencies. Node.js is not needed to run it.

### Supported engines

| Engine | PHP extension |
|---|---|
| MySQL, MariaDB, Percona | `pdo_mysql` |
| PostgreSQL | `pdo_pgsql` |
| SQLite | `pdo_sqlite` |
| Microsoft SQL Server 2016+ | `pdo_sqlsrv` (preferred) or `pdo_dblib` (FreeTDS) |

Features that an engine cannot support are hidden automatically. For example, SQLite has no
user administration, and only MySQL/MariaDB has storage engines and scheduled events.

### Requirements

- PHP 7.4 or newer (8.x works) with `pdo`, `json`, `openssl` and `mbstring`.
- The PDO driver for each engine you want to use (table above).
- Optional extensions: `zlib` (gzip import and export), `zip` (zip import and export),
  `bz2` (bzip2 import), `iconv` (CSV import in non-UTF-8 encodings).
- A web server: Apache, or nginx with PHP-FPM, or any server that can run PHP.
- A current browser. Everything is served from your own server; LDB uses no CDN and works offline.

### Status

LDB is beta software. It has not had an external security audit and its translations have not
been reviewed by native speakers. Try it on non-critical servers first, preferably with
read-only connections (see [Read-only connections](#read-only-connections)).

### Languages

English, Deutsch, Español, Français, Italiano, Português (Brasil), Polski, Türkçe, Русский, العربية, עברית, اردو, हिन्दी, বাংলা, Bahasa Indonesia, Tiếng Việt, 日本語, 한국어, 简体中文 and 繁體中文.
Arabic, Hebrew and Urdu use a right-to-left layout.

---

## 2. Installation

LDB has no installer. Put the files on a web server, point the web root at the `public/`
directory and (optionally) create `config/config.php`.

### 2.1 Release archive (recommended)

1. Download `ldb-<version>.zip` (or `.tar.gz`) and `SHA256SUMS` from the GitHub Releases page.
2. Verify the download:

   ```sh
   sha256sum -c SHA256SUMS --ignore-missing
   ```

3. Unpack it on the server, for example to `/srv/ldb`.
4. Make the web server serve `/srv/ldb/public` (see [Apache](#23-apache) and [nginx](#24-nginx-and-php-fpm)).

The archive contains the built frontend and needs neither Composer nor Node.js.

### 2.2 Git clone

```sh
git clone https://github.com/Pluxx/ldb.git
cd ldb
composer install --no-dev   # optional
```

`composer install` is optional. When `vendor/` is missing, `src/autoload.php` provides an
autoloader. The built frontend is part of the repository (`public/assets/dist/`).

### 2.3 Apache

**Recommended:** make `public/` the document root, so nothing else is reachable at all.

```apache
<VirtualHost *:443>
    ServerName db.example.com
    DocumentRoot /srv/ldb/public

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/db.example.com.pem
    SSLCertificateKeyFile /etc/ssl/private/db.example.com.key

    <Directory /srv/ldb/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`public/.htaccess` sets `Options -Indexes`, denies dotfiles and registers the MIME types for
`.js` and `.svg`. If you do not allow `.htaccess` files, copy those rules into the vhost.

**Drop-in under an existing site** (for example `https://example.com/ldb/`): copy the whole
directory into the web root. The root `.htaccess` serves `public/` and denies access to `src/`,
`config/`, `vendor/`, `bin/`, `docs/` and other internals. It needs `mod_rewrite`. Without
`mod_rewrite`, the root `index.php` redirects to `public/`, and the internals are protected by
`Require all denied` files in their own directories. Using `public/` as the document root is
still the safer setup.

### 2.4 nginx and PHP-FPM

A complete example is in `docs/nginx.conf.example`. The points that matter:

- `root` is the `public/` directory.
- Only `index.php`, `api.php` and `sso.php` are executed; every other `*.php` request returns 404.
- `client_max_body_size` should match `upload_max` (and PHP's `upload_max_filesize` and
  `post_max_size`).
- For `api.php`, set `fastcgi_buffering off` so exports stream, and a long `fastcgi_read_timeout`
  (the example uses `600s`) for big imports and exports.
- Pass `HTTPS on` to PHP (`fastcgi_param HTTPS on;`) so LDB sets `Secure` cookies and HSTS.
- Adjust `fastcgi_pass` to your PHP-FPM socket and add your `ssl_certificate` directives.
- If you use GET single sign-on links, do not log query strings for `/sso.php`.

If the whole repository sits inside a web root, the commented block at the end of the example
file shows the rules that deny everything except `public/`.

### 2.5 Docker development stack

The repository includes a Compose stack for trying LDB and for development. It is not meant for
production: `config/config.docker.php` contains public example secrets and fixed passwords.

```sh
docker compose up -d --build --wait
```

Open <http://localhost:8080>. The stack runs the app (PHP 7.4 by default;
`PHP_VERSION=8.3 docker compose up -d --build` selects another PHP version) plus MariaDB 11,
MySQL 8.0 and PostgreSQL 17 with a demo database named `ldb_demo`.

| Preset | Server | User / password |
|---|---|---|
| `mariadb` | MariaDB 11 | `root` / `root` |
| `mysql` | MySQL 8.0 | `root` / `root` |
| `pgsql` | PostgreSQL 17 | `postgres` / `postgres` |
| `mssql` | SQL Server 2022 (profile `mssql`) | `sa` / `Ldb_Passw0rd!` |
| SQLite | files in `/var/www/data` | any file name below it; the SQLite password is `sqlite` |

The default image has no SQL Server driver. To add SQL Server:

```sh
WITH_SQLSRV=1 docker compose --profile mssql up -d --build --wait
```

`docker compose down` stops the stack; the volumes keep the data.

### 2.6 SQL Server drivers

LDB uses `pdo_sqlsrv` (needs the Microsoft ODBC Driver 17 or 18 for SQL Server) or, as a
fallback, `pdo_dblib` (FreeTDS). To force FreeTDS for a preset, add `'options' => ['pdo_driver' => 'dblib']`
to that preset. With ODBC Driver 18 encryption is on by default, and LDB sets
`TrustServerCertificate=1` unless the preset option `trust_server_certificate` is `false`.

On Debian or Ubuntu: install `msodbcsql18` and `unixodbc-dev` from `packages.microsoft.com`, then
`pecl install sqlsrv pdo_sqlsrv` and enable both extensions. If a login form shows **PHP
extension missing** under the SQL Server entry, neither driver is loaded.

SQL Server notes: schemas are shown under the database (default `dbo`); paging uses
`OFFSET … FETCH` (an `ORDER BY` is added when missing); there are no storage engines or
character sets; comments are stored as `MS_Description` extended properties; IDENTITY cannot be
added to or removed from an existing column.

### 2.7 Upgrading

1. Keep `config/config.php` (and your `data_dir`, if you use two-factor authentication; it lives
   outside the application directory).
2. Unpack the new release next to the old one, or over it. If you unpack over the old
   installation, delete `public/assets/dist/` first: the frontend files carry content hashes in
   their names, so old files would otherwise stay behind.
3. Copy `config/config.php` into the new tree if you unpacked to a new directory, and switch the
   web server to the new `public/` directory.
4. Compare your `config/config.php` with the new `config/config.sample.php` for new options.
   Missing options simply use their defaults.
5. Reload the page. The version is shown below the login form (`LDB 0.1.0`).

Query history, saved queries and UI preferences are stored in the browser, not on the server,
so an upgrade does not affect them. See [CHANGELOG.md](../CHANGELOG.md) for what changed.

---

## 3. Configuration

Configuration is optional. Every key has a default. To change anything, copy
`config/config.sample.php` to `config/config.php` and edit the copy. The file returns a PHP
array:

```php
<?php
return [
    'servers' => [
        ['id' => 'prod', 'label' => 'Production', 'driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306],
    ],
    'session_idle_timeout' => 900,
];
```

Keep `config/config.php` out of version control and readable only by the PHP user. If the file is
missing, defaults are used. There is no environment-variable override.

### 3.1 All options

| Option | Default | Description and when to change it |
|---|---|---|
| `debug` | `false` | Detailed errors (stack traces) in API responses. Only for local debugging; never in production. |
| `servers` | `[]` | Preset servers on the login page (see [3.2](#32-server-presets)). With no presets and `allow_arbitrary_server` off, presets for localhost MySQL/MariaDB and PostgreSQL are offered. |
| `only_presets` | `false` | Only the presets can be used; no free host entry, and drivers without a preset are hidden (SQLite excepted). |
| `allow_arbitrary_server` | `false` | Let the login form accept any host and port. Off by default so LDB cannot be used to probe internal hosts. Turn it on only on a trusted network. |
| `read_only` | `false` | Force every connection to be read-only. See [Read-only connections](#read-only-connections). |
| `sqlite_paths` | `[]` | Directories or glob patterns where SQLite files may be opened or created. Empty disables SQLite. Keep them outside the web root. See [3.3](#33-sqlite). |
| `sqlite_password_hash` | `''` | `password_hash()` output. SQLite has no users, so every SQLite login must enter this password. |
| `sqlite_allow_no_password` | `false` | Allow SQLite logins without a password. Trusted networks only. |
| `session_idle_timeout` | `1800` | Seconds of inactivity until the connections are dropped. `0` disables the timeout. |
| `session_name` | `ldb_sid` | Name of the session cookie. Change it if another application on the same host uses the same name. |
| `cookie_secure` | `null` | Force the `Secure` cookie flag. `null` detects HTTPS from the request. Set `true` behind a TLS proxy that does not pass `HTTPS=on` to PHP. |
| `query_timeout` | `30` | Statement timeout in seconds, where the engine supports it (see below). `0` disables it. |
| `connect_timeout` | `10` | Connect timeout in seconds. For SQLite this value is used as the busy timeout. |
| `max_rows_browse` | `1000` | Upper limit for rows per page when browsing a table (the page-size list offers 25 to 1000 and is capped by this value). |
| `upload_max` | `67108864` (64 MiB) | Import size limit in bytes. PHP's `upload_max_filesize` and `post_max_size` also apply; the smallest of the three wins. |
| `theme_default` | `'system'` | Default theme: `light`, `dark` or `system`. Users can override it in the interface. |
| `login_max_attempts` | `5` | Failed logins allowed per client IP and window. |
| `login_window` | `300` | The window, in seconds. |
| `rate_limit_dir` | `null` | Private directory (mode 0700, owned by the PHP user) for rate-limit counters. Default: a private directory in the system temp directory. Set it on shared hosting where `/tmp` is shared. |
| `run_registry_dir` | `null` | Private directory for the registry of running SQL editor queries, which the **Stop** button needs. Default: a private directory in the system temp directory. |
| `export_time_limit` | `0` | PHP time limit in seconds for an export download (`0` = unlimited). |
| `export_memory_limit` | `null` | Present in the defaults but not read by this version; it has no effect. |
| `sso_secret` | `''` | Shared secret (at least 32 bytes) for single sign-on links. Empty disables SSO. See [12.3](#123-single-sign-on-links). |
| `sso_ttl` | `60` | Maximum token lifetime in seconds (capped at 300). |
| `sso_allowed_presets` | `[]` | Preset ids SSO may target (`[]` = all presets). |
| `sso_allow_arbitrary_server` | `false` | Allow SSO tokens that name a driver/host/port instead of a preset (never together with `only_presets`). |
| `sso_nonce_dir` | `null` | Private directory for used SSO token nonces (replay protection). Default: a private directory in the system temp directory. |
| `secret_key` | `''` | Key (at least 32 bytes) for the encrypted two-factor records. Empty means two-factor authentication is unavailable. |
| `data_dir` | `null` | Persistent private directory for two-factor records (stored in `<data_dir>/twofa`). Two-factor authentication needs an explicit value. |
| `twofa_required` | `false` | `true` (every login) or a list of preset ids: logins must set up two-factor authentication before they can use anything. |
| `sso_bypass_2fa` | `false` | Let SSO links log in to logins that have two-factor authentication enabled. |
| `twofa_max_failures` | `10` | Wrong second-factor codes per login before a lockout. |
| `twofa_window` | `900` | The lockout window, in seconds. |

**`query_timeout` per engine.** MySQL sets `max_execution_time`, which applies to `SELECT`
statements only. MariaDB sets `max_statement_time` for all statements. PostgreSQL sets
`statement_timeout`. SQL Server with `pdo_sqlsrv` sets the query timeout; with `pdo_dblib` it sets
a lock timeout only. SQLite has no statement timeout.

### 3.2 Server presets

A preset is an entry in `servers`. Users pick it on the login page and only type user and
password; the host and port are fixed by you. Credentials are never stored in the configuration.

```php
'servers' => [
    ['id' => 'local', 'label' => 'Local MariaDB',  'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306],
    ['id' => 'pg',    'label' => 'Postgres',       'driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432, 'database' => 'postgres'],
    ['id' => 'mssql', 'label' => 'SQL Server',     'driver' => 'sqlsrv', 'host' => 'mssql', 'port' => 1433],
    ['id' => 'prod',  'label' => 'Production (read-only)', 'driver' => 'mysql', 'host' => 'db.prod', 'read_only' => true],
],
```

| Key | Meaning |
|---|---|
| `id` | Identifier used by the login form, `bin/sso-link` and `twofa_required`. Use letters, digits and dashes. |
| `label` | Name shown in the preset list. Without it, the host is shown. |
| `driver` | `mysql` (also MariaDB and Percona), `pgsql`, `sqlsrv` or `sqlite`. Default `mysql`. |
| `host`, `port` | Server address. The port defaults to the engine's standard port. |
| `database` | Optional default database or schema to open after login. |
| `read_only` | `true` forces read-only connections for this preset; users cannot turn it off. |
| `options` | Optional driver options, for example `['pdo_driver' => 'dblib']` for SQL Server. |

Preset host and port are visible to anyone who can reach the login page. Do not expose
internal host names you consider sensitive.

### 3.3 SQLite

SQLite has no users, so LDB protects it with a single server-wide password.

1. Choose directories for the database files, outside the web root, and list them:

   ```php
   'sqlite_paths' => ['/var/lib/ldb/sqlite', '/srv/data/*.sqlite'],
   ```

   Entries can be directories or glob patterns. Paths are checked with `realpath()`.
2. Generate a password hash and store it:

   ```sh
   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   ```php
   'sqlite_password_hash' => '$2y$10$...',
   ```

3. Users log in with **Database file** (an absolute path inside an allowed directory) and the
   password. Anyone who knows the password can read and write every database in those
   directories.

Without `sqlite_password_hash`, SQLite logins are refused, unless you set
`sqlite_allow_no_password` to `true` (trusted networks only).

In the default configuration the login page shows only the preset list, without the database
type cards (see [4.1](#41-the-login-form)). To make SQLite selectable there, either add a preset
with `'driver' => 'sqlite'`, for example `['id' => 'sqlite', 'label' => 'SQLite files', 'driver' => 'sqlite']`,
or enable `allow_arbitrary_server`.

### 3.4 Secure defaults

Out of the box LDB is restrictive:

- Only preset servers can be used (`allow_arbitrary_server` is off). With no presets configured,
  localhost MySQL/MariaDB and PostgreSQL presets are offered.
- SQLite is disabled until `sqlite_paths` is set, and then needs `sqlite_password_hash`.
- Two-factor authentication and single sign-on are unavailable until `secret_key` and
  `data_dir` / `sso_secret` are set.
- `debug` is off.
- Cookies are `HttpOnly` and `SameSite=Strict`; the `Secure` flag is set automatically over HTTPS.
- Login attempts are rate limited per client IP.

See [12.5](#125-production-checklist) for a checklist.

---

## 4. Logging in

LDB has no accounts of its own. You log in with a database login. The credentials are kept
encrypted in the PHP session for the duration of the session and are never written to the
configuration.

### 4.1 The login form

The page is titled **Connect to a database** (or **Add a connection** when you add a further
one). What you see depends on the configuration:

- **Preset** (or **Server** when free entry is disabled): the configured servers. Choosing one
  fills in driver, host and port. **Custom server…** appears only when free host entry is enabled.
- **Database type**: cards for MySQL / MariaDB, PostgreSQL, SQLite and SQL Server. They are
  shown when `allow_arbitrary_server` is on and `only_presets` is off. A card marked **PHP
  extension missing** cannot be selected because the PHP driver is not installed.
- **Host** and **Port**: shown for a free server entry. The port defaults to 3306, 5432 or 1433.
- **User** and **Password**. The eye button (**Show password**) reveals the password.
- **Database**: optional default database.
- **Database file** (SQLite): absolute path inside a directory allowed by `sqlite_paths`. The
  password is the SQLite password from the configuration. If the user field is hidden, that is
  expected: SQLite has no users.
- **More options**: **Connection label** (a name for the connection switcher, such as
  "Production replica") and **Connect read-only**.

Click **Connect**. The form remembers the last driver, host, user and preset, never the password.

### 4.2 Read-only option

Tick **Connect read-only** under **More options** to disable all changes for the connection;
the SQL editor then runs only `SELECT`-like statements. When the administrator forces read-only
(globally or for a preset), the box is ticked and disabled and shows **This server is configured
as read-only.** A read-only connection shows a **Read-only** badge in the top bar. See
[Read-only connections](#read-only-connections) for what is enforced.

### 4.3 Two-factor code step

If the database login has two-factor authentication enabled, LDB first verifies the password and
then shows **Two-factor authentication**.

- Enter the 6-digit code from your authenticator app and click **Verify**.
- Choose **Use a recovery code** to enter one of your single-use recovery codes instead (format
  like `abcde-fghjk`). **Use an authentication code** switches back.
- **Back to login** returns to the password form.

The pending sign-in lasts 5 minutes and allows at most 5 codes; after that you must log in
again. Too many wrong codes show **Too many wrong codes. Please wait a few minutes and try
again.** If the administrator requires two-factor authentication for your login and you have
not set it up yet, you are taken straight to **Account security** after the password step (see
[12.2](#122-two-factor-authentication)).

### 4.4 Several connections

You can be connected to several servers or logins at once. Open the connection switcher in the
top bar and choose **Add connection…**. The login form opens with the title **Add a connection**
and a **Back** link. The switcher lists all connections; select one to jump to its overview.

### 4.5 Logging out and session timeout

- **Disconnect** (connection switcher) ends only the current connection.
- **Log out** (the icon at the right end of the top bar) ends all connections.
- After `session_idle_timeout` seconds without activity (default 30 minutes), LDB drops the
  connections. The next action shows **Your session has expired. Please log in again.**

### 4.6 Links and reconnecting

A link that points into a connection you have not opened (a shared query or table view) leads to
the login form with the notice **This link needs a connection**. Connect to the named server and
you continue where the link leads. Links contain no passwords; they carry only a non-secret hint
about the server.

---

## 5. The interface

### 5.1 Layout

- **Top bar**: sidebar toggle (small screens), the LDB logo, the connection switcher, the
  breadcrumbs, the command palette field (**Search or jump to…**), the theme menu, the width
  toggle, the language menu and **Log out**.
- **Sidebar**: the tree of databases (for PostgreSQL and SQL Server also schemas) with their
  **Tables**, **Views** and **Routines** (plus triggers and events where supported).
- **Main area**: the page you are on. Table pages have tabs: **Browse**, **Structure**, **SQL**,
  **Search**, **Insert**, **DDL**, **Operations** (views and routines show fewer).

### 5.2 Sidebar

- **Filter databases and tables** narrows the tree. Press the Down arrow in the filter to move
  into the tree.
- Click a database to open it. Expanded nodes are remembered per connection.
- Right-click an item (or press Shift+F10, or use the menu button) for its context menu.
  A database offers **Open**, **New query**, **Search in database**, **Show diagram**,
  **Settings**, **Compare…**, **New table**, **Import…**, **Export…**, **Refresh**, **Copy name** and
  **Drop database**. A table or view offers **Browse**, **Structure**, **SQL**, **Insert**, **DDL**,
  **Operations**, **Copy name**, **Empty** (tables only) and **Drop table** / **Drop view**
  (**Insert** and **Operations** are not offered for views). Routines, triggers and events offer
  **Edit**, **DDL**, **Copy name** and **Drop**.
- Write actions are removed on read-only connections.
- Drag the edge of the sidebar to resize it (200 to 520 px); the width is remembered.
- Below the large-screen breakpoint the sidebar becomes an off-canvas drawer opened with
  **Toggle sidebar**; Esc closes it.

### 5.3 Breadcrumbs

The breadcrumbs show where you are (database, table or object, page). Each part is a link.
They are hidden on small screens.

### 5.4 Command palette

Press **Ctrl+K** (**Cmd+K** on macOS), or click **Search or jump to…**. Type to filter, then use
the Up and Down arrows and Enter. Esc closes it. Some actions (for example **Import into…**) open
a second level that lists databases; Backspace on an empty field goes back.

The palette offers:

- **Databases** and **Tables & views** of the current connection (tables of up to 12 databases,
  the current one first), including **Structure of {name}** for the current database.
- **Actions**: **New query**, **Go to server overview**, **Import**, **Export**, **Import into…**,
  **Export database…**, **Export table {name}**, **Diagram**, **Search database**, **Database
  settings for {db}**, **Compare schema of {db}…**, **Create table in {db}**, **Edit routine,
  trigger or event in {db}…**, **New procedure in…**, **New function in…**, **Server monitor**,
  **Process list**, **Users**, **Server variables**, **Server status**, **Toggle theme**,
  **Change language…**, **Add connection…** and **Log out**. Entries that would change data are
  not offered on read-only connections, and server pages only appear when the engine supports them.
- Your **Saved queries**; choosing one opens it in the SQL editor.

### 5.5 Theme, language and width

- **Theme** (top bar): **Light**, **Dark** or **System**. The default comes from `theme_default`;
  your choice is stored in the browser.
- **Language** (top bar, also on the login page): chooses one of the 20 languages. The first
  visit uses the browser language when available.
- **Use full width** / **Limit content width** (top bar, large screens only) switches between a
  content width capped at 1600 px and the full window.

### 5.6 Keyboard shortcuts

On macOS, use Cmd instead of Ctrl.

**Global**

| Keys | Action |
|---|---|
| Ctrl+K | Open the command palette |
| Shift+F10 | Open the context menu of the focused cell, row or tree item |
| Esc | Close the palette, menus, dialogs and the mobile sidebar |

**SQL editor**

| Keys | Action |
|---|---|
| Ctrl+Enter | Run the selection, or the statement under the cursor |
| Ctrl+Shift+Enter | Run the whole editor content |
| Esc | Stop the running query (closes the autocomplete list first when it is open) |
| Ctrl+Shift+F | Format the SQL |
| Ctrl+/ | Toggle comment on the current line or selection |
| Ctrl+Space | Show autocomplete suggestions (Ctrl, also on macOS) |
| Up / Down, Enter or Tab, Esc | Choose, accept and dismiss a suggestion |
| Tab / Shift+Tab | Indent / outdent |
| Esc, then Tab | Leave the editor (keyboard focus) |
| Ctrl+Shift+S | Save the query as a saved query |

**Data grids** (Browse, query results)

| Keys | Action |
|---|---|
| Arrow keys, Home, End, PageUp, PageDown | Move between cells. Ctrl+Home/End jump to the first/last row |
| Enter or F2, or double-click | Edit the cell |
| Space, Shift+Space | Select the row, or extend the selection |
| Shift+Arrow | Extend the selection |
| Ctrl+A | Select all rows on the page |
| Ctrl+C | Copy the cell, or the selected rows as TSV |
| Delete | Delete (or mark for deletion) the selected rows |
| Esc | Revert the edited cell (batch mode), or clear the selection |
| Shift+click on a header | Add a column to a multi-column sort |
| Ctrl+S | Review and apply pending changes (Browse) |

In the cell editor: Enter saves and moves down, Tab / Shift+Tab save and move sideways, Esc cancels.
For multi-line values, Ctrl+Enter saves.

**Editors and other views**

| Keys | Action |
|---|---|
| Ctrl+S | Save in the routine, trigger and event editor |
| Arrow keys, Home, End, Delete | Move between tabs; Delete closes a closable tab |
| Tab, Enter, R / Shift+R, Esc | In the diagram: move between tables, open the table menu, cycle relations, go back |
| Arrow keys, + / -, 0 | In the diagram: move the focused card, zoom, fit to screen |
| Left / Right, Space | In the sidebar tree: collapse / expand and toggle |

### 5.7 Mobile

LDB is usable at 360 px width. The sidebar becomes a drawer, secondary toolbar buttons move
into a **More actions** menu, the history and saved-queries panels open as dialogs, wide tables
scroll horizontally, and controls are touch-sized. The diagram supports pan with one finger,
pinch to zoom and **Link mode** for creating relations by tapping.

---

## 6. Working with data

Open a table from the sidebar, the database overview or the command palette. The **Browse** tab
is the default.

### 6.1 Browse

The toolbar contains **Filter**, the **Search all columns** field, **Insert**, **Edit**,
**Duplicate**, **Delete**, **Export**, **Copy link**, the **Edit settings** gear and **Refresh**.
Below the grid are the paging controls. A bar under the toolbar shows the SQL that was run, its
duration and **Open in SQL editor**.

- **Sorting**: click a column header to sort, click again to reverse. Shift+click adds further
  sort columns.
- **Filter**: **Filter** opens the filter builder: pick a **Column**, an **Operator** and a
  **Value**; **Add condition** adds more. Conditions are combined with `and`. Operators: `=`,
  `!=`, `<`, `>`, `<=`, `>=`, **contains**, **starts with**, **ends with**, **LIKE**,
  **NOT LIKE**, **in list**, **not in list**, **between**, **is NULL**, **is not NULL**. Lists
  are comma separated (`a, b, c`). **Apply** runs the filter; **Clear** removes it. A badge on
  the button shows the number of conditions.
- **Search all columns**: type to search every column of the table at once.
- **Paging**: **First page**, **Previous page**, **Next page**, **Last page**, a page box, and
  **Rows per page** (25, 50, 100, 250, 500 or 1000, capped by `max_rows_browse`). The range shows
  as `1-50 of 1,234`.
- **Query by example**: the **Search** tab lets you enter values per column, choose which
  columns to show and an **Order by**; the result opens in Browse with those filters. Empty rows
  are ignored.
- **Binary values** appear as **BINARY** and cannot be edited inline. **View full value**
  (context menu) shows a value in full, with JSON formatted.

### 6.2 Editing rows

Editing needs a primary key. Without one, **Edit** and **Delete** are disabled with the hint
**Editing needs a primary key**. Read-only connections hide all editing controls.

**Inline editing.** Double-click a cell, or move to it and press Enter or F2. Type the new
value. **Set to NULL** (context menu) writes NULL for nullable columns.

**Row form.** **Insert** opens a form with a field per column: `auto` for auto-increment
columns, `DEFAULT`, a NULL checkbox (**Set {column} to NULL**), true/false for booleans, and for
foreign-key columns a suggestion list from the referenced table (**Suggestions from {table}**).
**Edit** (or **Edit row…** in the row menu) opens the same form for
the selected row; **Duplicate** opens it prefilled with the row's values. The **Insert** tab of
the table opens the insert form directly.

**Selecting rows.** Click the checkbox of a row, or use Space. Shift+click selects a range,
Ctrl+click toggles one row, Ctrl+A selects all rows on the page.

**Deleting rows.** Select rows and click **Delete** (the button then reads **Delete 3 rows**), or
press the Delete key.

### 6.3 Batched edits and review

By default LDB collects changes instead of saving each one. This is *batch mode*.

1. Edit cells, delete rows, or add rows with the row form (its button reads **Add to pending
   changes**). Changed cells are highlighted, deleted rows are struck through and new rows are
   marked.
2. A bar appears at the bottom showing **N changes** (for example `2 edited · 1 deleted · 1 new`).
3. Click **Review & apply**, or press **Ctrl+S**. The dialog **Apply changes to {table}** shows the
   exact SQL. Click **Apply changes** to run everything in one transaction. If the database
   rejects a change, nothing is applied and the offending row is highlighted
   (**The highlighted row could not be saved; fix or revert it and try again**).
4. **Discard** drops all pending changes after a confirmation.

Press Esc in a changed cell to revert it. The row context menu offers **Revert cell**,
**Revert row** and **Restore row** (for rows marked for deletion).

While changes are pending, sorting, filtering, searching, paging and leaving the page ask
**Unsaved row changes** with the choices **Keep editing**, **Discard** and **Review & apply**.
Reloading the browser tab also warns.

**Immediate mode.** Open the **Edit settings** gear and choose **When editing rows** →
**Save edits immediately** to save every edit as soon as you make it. Inline edits are sent one
request each; deleting asks for a confirmation; the row form saves directly.
**Batch edits (review SQL before saving)** switches back. The choice is stored in the browser.

### 6.4 Foreign keys

Cells in a column with a foreign key show a link. Click it (or choose **Go to referenced row in
{table}** from the context menu) to open the referenced table filtered to that row.

### 6.5 Copying and downloading rows

Select rows (or none, to use the whole page) and open **Export**. The menu is headed with the
scope (**Current page** or **N selected rows**) and offers **Copy as TSV**, **Copy as JSON**,
**Copy as SQL INSERT**, **Download CSV**, **Download JSON** and **Export whole table…** (which
opens the full [export page](#10-import-and-export)). The cell context menu also has **Copy
value**.

### 6.6 Share links

**Copy link** copies a URL that reproduces the current view: filters, sorting, search, visible
columns, page and page size. It never contains data or credentials. Whoever opens it must log in
to a matching server first (see [4.6](#46-links-and-reconnecting)).

---

## 7. SQL editor

Open it from **SQL** on a server, database or table page, from **New query** in the sidebar, or
from the command palette. Each table also has an **SQL** tab prefilled with a `SELECT` for that
table.

### 7.1 Editor and toolbar

- **Database** selector: the database the statements run against (**(no database)** if none).
- **Run**: runs the selection, or the whole editor content when nothing is selected.
- **Transaction mode** (see [7.4](#74-transaction-modes)).
- **Explain**, **Format**, **Save current query** (the bookmark button), **Copy link to query**, **Row limit** (**Limit 100 / 1,000 / 5,000 / 10,000**).
- **History** and **Saved queries** open side panels.
- The gear (**Editor settings**) offers **Save query**, **Copy link to query** and the toggle
  **Confirm risky statements**.
- Query tabs: **New tab** adds one; double-click a tab to rename it; the x closes it. The tabs and
  their text are kept in the browser. Drag the divider between editor and results to resize it.

The editor highlights SQL, closes brackets and quotes automatically, and works with IME input.
Procedure and trigger bodies with `BEGIN … END` need no `DELIMITER` command; a pasted
`DELIMITER` line is understood as well.

### 7.2 Running SQL

- **Ctrl+Enter** runs the selection, or the statement under the cursor when nothing is selected.
- **Ctrl+Shift+Enter** runs the whole script.
- The **Run** button runs the selection, or everything when nothing is selected.

You can run several statements at once, separated by `;`. Each statement's outcome appears as a
tab in the results (**Result 1**, **Result 2**, …; failures show **Error N**). A header shows the
number of rows (or **N rows affected**), the duration, an **Insert ID** where the engine gives
one, and the SQL. **Truncated** means the row limit was hit; raise **Row limit** or add `LIMIT`.
Statements that return several result sets (for example procedure calls) show **N result sets**
as sub-tabs; export and copy then apply to the first set only.

While a query runs the **Run** button turns into **Stop** and a timer is shown. See
[7.5](#75-stopping-queries).

**Results menu.** **Export** in the result header offers **Copy as TSV**, **Copy as JSON**,
**Download JSON**, **Download TSV** and **Export result…**, which opens the export page with the
query preselected.

### 7.3 Autocomplete, format and comments

Autocomplete suggests tables, columns, functions and keywords of the selected database and
engine. It opens as you type; Ctrl+Space opens it on demand. **Format** (Ctrl+Shift+F) re-indents
the SQL. Ctrl+/ toggles comments.

### 7.4 Transaction modes

The segmented control **Transaction mode** has three settings:

| Mode | What happens |
|---|---|
| **Auto-commit** | Every statement is saved as soon as it runs. |
| **Atomic** | The whole script runs in one transaction. It is committed only if every statement succeeds; on the first error everything is rolled back. The result shows **Committed** or **Rolled back**. |
| **Dry run (rollback)** | The script runs in a transaction that is always rolled back. You see the results, nothing is saved. |

Limits you must know:

- Each run uses its own database connection, so a transaction never stays open between runs.
  `BEGIN`, `COMMIT`, `ROLLBACK` and similar statements inside the script are refused in Atomic
  and Dry run mode, as are statements that cannot run inside a transaction (for example
  PostgreSQL `VACUUM` or `CREATE DATABASE`).
- **MySQL and MariaDB commit DDL implicitly** (`CREATE`, `ALTER`, `DROP`, `TRUNCATE`,
  `RENAME`, `GRANT`, `LOCK TABLES`, `OPTIMIZE`, and more). Such statements are refused in Dry run
  and give a warning in Atomic mode, because they would be saved anyway. PostgreSQL, SQLite and
  SQL Server run DDL inside the transaction.
- Procedure calls (`CALL` on MySQL, `EXEC` on SQL Server) produce a warning: the procedure may
  commit by itself.
- Changes to non-transactional tables (such as MyISAM) and sequence or auto-increment counters
  are not rolled back.

### 7.5 Stopping queries

**Stop** (or Esc) asks the server to cancel the running query. It works on MySQL/MariaDB,
PostgreSQL and SQL Server; it is not offered for SQLite. A stopped run shows **Cancelled**, and in
Atomic mode everything is rolled back. If the query cannot be stopped, LDB reports that it may
have finished already or that the database user lacks the privilege to cancel it. On SQL Server,
Stop ends the whole session (`KILL`), which needs the `ALTER ANY CONNECTION` permission.

### 7.6 Risky-SQL confirmation

Before running, LDB checks the SQL for statements that can destroy data and asks **Run risky
statements?** with the reasons: `DELETE` without `WHERE`, `UPDATE` without `WHERE`, `DROP`,
`TRUNCATE`, and `ALTER TABLE … DROP`. Choose **Run anyway** to continue. The check is skipped in
Dry run mode. Switch it off with the gear → **Confirm risky statements**.

### 7.7 History

**History** lists the last 200 runs with database, time, duration and a success or failure mark.
Click an entry to load it into the editor (in a new tab when the editor is not empty). Use the
search field to filter and **Clear history** to empty it. History is stored in your browser only.

### 7.8 Saved queries

Click the bookmark button or press Ctrl+Shift+S to save the current tab; you are asked for a
**Query name**. Saving again updates the saved entry the tab came from. **Saved queries** shows
the list with search. Each entry has a menu: **Open in new tab**, **Copy**, **Copy link to
query**, **Rename**, **Duplicate** and **Delete**.

- Up to 500 saved queries are kept, in this browser only. They are not shared with other
  browsers or users, and they appear in the command palette.
- **Export** in the panel writes `ldb-saved-queries.json`; **Import** reads such a file (up to
  5 MB), renaming entries whose names clash and skipping duplicates. This is the way to move
  queries between browsers or to share a collection.

Do not put passwords into saved queries or history: they are plain text in the browser's local
storage.

### 7.9 Share link for a query

**Copy link to query** copies a URL containing the SQL (up to 8 KB of SQL; larger queries need
**Saved queries → Export**). Opening the link, after logging in to a matching server, puts the
query in a new tab. It is never run automatically.

### 7.10 EXPLAIN

**Explain** shows the execution plan of the selection, or of the statement under the cursor (the
first statement if there are several), in a result tab labelled **Explain**. The statement itself
is not executed. On read-only connections the same read-only checks as for the SQL editor apply.

### 7.11 Editable results

When a result comes from a simple query on a single table that includes the table's primary key,
the result shows an **Editable** badge. Double-click a cell to edit it; **changes are saved
immediately** (there is no batch mode here). Read-only connections never show the badge.

---

## 8. Structure, routines, triggers and events

### 8.1 Table structure

The **Structure** tab shows **Columns**, **Indexes**, **Foreign keys**, **Referenced by**,
**Triggers** and **Table options**. For a view, the columns are shown read-only; change the view
definition in the SQL editor.

Every change is *staged*, not executed. A bar appears with **N pending changes**, **Discard**
and **Review & apply**. **Review & apply** shows the generated SQL in **Review changes to
{table}**; **Execute** runs it, **Copy SQL** copies it. A warning is shown when columns, indexes or
constraints are dropped (**Data in dropped columns is lost.**). Staged changes can be undone
individually (**Undo**).

**Columns.**
- **Add column** adds a row; the pencil (**Edit column**) opens the full column form. Fields:
  **Name**, **Type** (a searchable list grouped by category, or free text), **Length / values**,
  **Allow NULL**, **Default** (**None**, **Value** or **Expression**), **Auto increment**,
  **Primary key**, **Comment**, **Collation** and, on engines that support it, the **Position**
  (**At the end**, **First**, **After {name}**).
- The row menu offers move up, move down, move to first position, **Revert changes**,
  **Copy name** and **Drop column**.
- Renaming a column is done by changing its **Name** in the edit form.
- Generated columns keep their expression unchanged.

**Indexes.** **Add index**: **Name**, **Kind** (**Index**, **Unique**, **Primary key**,
**Full-text**, **Spatial**, depending on the engine), **Columns** (with **Add column…**), and an
optional **Prefix** length for long text columns.

**Foreign keys.** **Add foreign key**: **Constraint name**, **Columns**, **Referenced table**,
**Referenced columns** and **Actions** (on update, on delete). **Referenced by** lists foreign
keys in other tables that point to this table.

**Table options.** **Storage engine**, **Default collation** (applies to new columns),
**Comment** and **Next auto-increment value**, as the engine supports them.

**Triggers** of the table are listed with a DDL viewer; edit them with the
[routine editor](#85-routines-triggers-and-events-editor).

### 8.2 Creating tables

**New table** (database overview, sidebar menu, or **Create table in {db}** in the palette)
opens a form: **Table name**, the column editor (tick **PK** for primary key columns; the sliders
button opens collation and more options), indexes, foreign keys and options. The **SQL preview**
updates as you type. **Create table** runs it and opens the new table's structure. Engine
specific options (for example SQLite `WITHOUT ROWID` and `STRICT`) appear where supported.

### 8.3 Table operations

The **Operations** tab (not shown for views) contains:

- **Rename table**.
- **Copy table**: **Structure only** or **Structure and data**, optionally into another database
  or schema (**Target database** / **Target schema**).
- **Move table**: copies structure and data to the target, then drops this table. Foreign keys
  pointing to it and triggers are not moved.
- **Comment, engine and collation**: **Review & save** shows the SQL first.
- **Maintenance**: MySQL/MariaDB **Optimize**, **Analyze**, **Check**, **Repair** (repair only
  helps MyISAM and Aria tables); PostgreSQL **Vacuum**, **Analyze**, **Optimize**; SQLite
  **Analyze**, **Check**, **Optimize**, **Vacuum**; SQL Server **Analyze**, **Optimize**,
  **Check**. The result appears in a grid.
- **Danger zone**: **Empty table** (`DELETE` of all rows; triggers fire and auto-increment
  counters are kept), **Truncate table** (fast removal, resets counters) and **Drop table**.
  Each asks you to type the table name to confirm.

### 8.4 DDL

The **DDL** tab shows the `CREATE` statement of a table, view, routine or trigger with syntax
highlighting. **Download .sql** saves it. Copy it with the usual shortcuts, or open it in the
SQL editor.

### 8.5 Routines, triggers and events editor

Stored procedures and functions (**Routines**), **Triggers** and scheduled **Events** are listed
on the database overview and in the sidebar, where available (events are MySQL/MariaDB only;
SQLite has triggers but no routines). Create one with **New procedure**, **New function**,
**New trigger** or **New event**; open one to edit it.

The editor has a form for the header and an SQL editor for the **Body**:

- **Routines**: **Name**, **Parameters** (**Add parameter**: name, type, mode, default; move or
  remove them), **Returns** (functions), **Characteristics** (deterministic, **SQL data
  access**, **SQL security**, **Definer**; PostgreSQL adds language, volatility, parallel
  safety and configuration), **Comment**.
- **Triggers**: **Table**, **Timing**, **Events**, plus engine specifics (PostgreSQL triggers call
  a trigger function chosen in **Trigger function**, with **Arguments**, **UPDATE OF columns** and a
  **WHEN condition**).
- **Events**: **Schedule** (**Once at** or **Every** with an interval and **Unit**), **Starts**,
  **Ends**, **Status**. If the event scheduler is off, a notice says
  events do not run until it is enabled.

The body is sent to the server exactly as written. Buttons: **Save** (Ctrl+S), **Execute**
(routines), **Copy as SQL**, **Open in SQL editor** and **Drop**.

**Saving** shows the SQL for review (**Create {name}** / **Save {name}**) before running it.
Warnings may appear: on engines that cannot replace an object in one step, the object is
dropped and recreated, which loses privileges granted on it (**The object is dropped and created
again…**); if creating the new version fails, LDB recreates the previous definition.

**Execute** asks for the input parameters (**Values are sent as bound parameters.**; **Default**
uses the parameter default), runs the routine and shows its result sets and **Output
parameters**. **Copy call** copies the call statement. Execution uses the saved version, not
unsaved edits. Read-only connections can view everything and still execute functions, but cannot
call procedures.

---

## 9. Databases, diagram, search and schema diff

### 9.1 Database overview

Opening a database lists its tables and views with **Rows** (approximate), **Size**, **Engine**,
**Collation** and **Comment**. Sort by clicking a header; **Filter tables** searches names and
comments. Below are the **Routines**, **Triggers** and **Events** of the database.

The top buttons are **SQL**, **Search**, **Diagram**, **Import**, **Export**, **Settings**,
**Compare…** and **New table**. Tick table checkboxes (Shift+click for a range) to get bulk
actions: **Export**, **Optimize**, **Empty**, **Drop** and **Clear selection**; emptying and
dropping ask for confirmation. The row menu offers **Browse**, **Structure**, **SQL**, **DDL**,
**Operations**, **Export…**, **Import CSV…**, **Empty** and **Drop**.

PostgreSQL and SQL Server databases contain schemas. A database page shows `database.schema`,
and links use `db@schema`.

### 9.2 Server overview: creating and dropping databases

On the server overview, **New database** asks for a **Database name** and, where supported, a
collation (default **Server default**). Dropping a database (**Drop database**) requires typing its
name. SQLite has no server-level database list: to create a new SQLite database, log in with a new
file name inside an allowed directory (the file is created if its directory exists); there is no
create or drop action for SQLite files in the interface.

### 9.3 Database settings

Open **Settings** on a database page (also in the sidebar and palette). The page shows an
**Overview** and, depending on the engine, these sections. Every change is shown as SQL for
review before it runs; read-only connections see only the overview.

**MySQL / MariaDB**
- **Character set & collation**: choose a new default for new tables. Tick **Also convert all
  tables and columns** to run `ALTER TABLE … CONVERT TO` for the selected tables. Choose them with
  **All**, **Only differing** or **None**. Converting rewrites the tables and can take long and
  block writes on large data; views are skipped. Make a backup first.
- **Copy database**: creates a new database and copies all tables with data, then views,
  routines, triggers and events (events are disabled in the copy). User privileges are not
  copied. Objects that could not be copied are listed.
- **Rename database**: MySQL cannot rename a database, so LDB copies it to the new name and then
  drops the old one, only when everything was copied. Privileges for the old name are not moved.

**PostgreSQL**
- **Owner, comment & connection limit** (`-1` means unlimited). Encoding and locale are fixed at
  creation; to change them create a new database and copy the data.
- **Copy database** uses the database as a template. PostgreSQL requires that nobody else is
  connected to it meanwhile.
- **Rename database** uses `ALTER DATABASE … RENAME TO`; nobody may be connected meanwhile.

**SQLite**
- **PRAGMA settings** stored in the file, such as journal mode, auto-vacuum and user version, with
  an option **Run VACUUM afterwards**. Foreign key enforcement is per connection, and LDB
  turns it on for every connection. Copy and rename are not offered.

**SQL Server**
- **Collation**, which needs exclusive access (no other session may use the database).
- **Rename database** uses `ALTER DATABASE … MODIFY NAME`, also needing exclusive access. Copy is
  not offered. The recovery model is shown but not editable here.

**Danger zone**: **Drop database** permanently deletes the database with all its tables and data,
after you type its name. If your connection logs in to that very database you will have to log
in again, and renaming it from that connection is not possible.

### 9.4 ER diagram

**Diagram** (database overview, sidebar **Show diagram**, palette) draws all tables with primary
key, foreign key and nullable markers, and the foreign-key relations between them.

- **Navigation**: drag the background to pan; scroll or pinch to zoom; **Zoom in**, **Zoom out**
  and **Fit to screen** are in the toolbar. **Find table…** locates a table. **Keys only** shows
  only key columns. **Highlight relations** emphasises a table's connections (also on hover or
  focus). **Auto-arrange** discards manual positions and lays tables out automatically.
- Drag a table card to move it. Positions and the view are remembered per connection and database
  in the browser.
- Click a table (or press Enter) for its menu: **Browse**, **Structure**, **Search**, **DDL**,
  **Highlight relations**, and on writable connections **Add relation…**, **Add column…**,
  **Rename table…**, **Drop table…**.
- Click a relation line for **Details…**, **Edit relation…** and **Drop relation…**.
- **Export**: **Download as SVG** or **Download as PNG**.

**Editing relations** (writable connections, engines with foreign keys): drag the handle of a
column onto a column of another table (**Drag onto a column of another table to create a
relation**), or use **Link mode**: tap the column that should reference another table, then the
referenced column or table header. A dialog (**New relation from {table}**) lets you adjust names
and actions; a warning appears when the column types differ. Every structural change is shown as
SQL for review before it runs, and the diagram reloads keeping card positions. Dropping a relation
removes only the foreign key, not data.

### 9.5 Search in a database

**Search** (or **Search in database** in the sidebar) finds text in all tables of a database.
Enter a **Search term** and choose **Match mode**: **Contains** (case-insensitive), **Exact**, or
**Regex** (MySQL/MariaDB and PostgreSQL only). By default **All tables** are searched; narrow it
with the table list (**Select all** / **Select none**). Results are grouped per table with the
matching columns and counts. **Open matching rows** opens Browse filtered to the match; for regex
matches, the SQL editor opens with a ready `SELECT` instead. The last search is kept in the URL,
so Back returns to it. Only text columns are searched.

### 9.6 Schema diff

**Compare…** (database page, sidebar, or **Compare schema of {db}…** in the palette) compares two
schemas and builds a migration script. The **Source (desired state)** is the structure you want;
the **Target (will be changed)** is the database the script is generated for. Both must use the
same engine, and may be on different connections (**Open another connection…**). **Swap source and
target** exchanges them. If the target connection is read-only you can generate and download
the script but not apply it.

1. Under **Options**, choose what to **Compare** (tables, views, routines, triggers, events) and
   what to ignore (**Comments**, **AUTO_INCREMENT counters**, **Collations**, **Column order**,
   **DEFINER** of views, routines and triggers). **Detect renamed columns** turns a removed and an
   added column with the same definition at the same position into a rename that keeps the
   data; check the result.
2. Click **Compare**. LDB reads both schemas and reports **N differences found** (or **No
   differences**). Changes are grouped by object type; expand an object to see each change, and
   **Show definition** to see a side-by-side or unified diff of the DDL (**Diff layout**).
3. Tick the changes to include. **Select all**, **Select none** and **Deselect destructive**
   help. Destructive changes (dropped tables or columns, narrower types, `NOT NULL` on columns
   that contain NULLs) are flagged, with warnings.
4. Click **Generate script**. The **Migration script** appears in dependency-correct order. You can
   copy it, **Download .sql**, or **Open in SQL editor**.
5. **Apply to target…** runs it: you choose **All or nothing** (one transaction) or **Statement
   by statement**, then confirm. The script is generated again from the current schemas and
   must match what you reviewed; if the schemas changed, LDB asks you to compare again. MySQL
   and MariaDB commit every DDL statement immediately, so a failure cannot undo the statements
   before it. Results are listed per statement. Make a backup before applying destructive changes.

SQLite tables that cannot be altered in place are rebuilt by copying the data to a new table.

---

## 10. Import and export

Open **Import** and **Export** from a server, database or table page, the sidebar, or the
command palette. Both stream data, so large files do not need to fit in memory.

### 10.1 Export

**Source**
- **Whole database**: all tables and views.
- **Selected tables**: a checklist with search, **Select all** and **Select none**, and row counts.
- **SQL query**: the result of one `SELECT` statement.

**Format** (options appear below the format):

| Format | Notes |
|---|---|
| **SQL** | **Structure (CREATE)**, **Data (INSERT)**, **Add DROP TABLE IF EXISTS**, **Add IF NOT EXISTS to CREATE TABLE**, **Include routines, triggers and events**, **INSERT style** (`INSERT`, `INSERT IGNORE`, `REPLACE`), **Rows per INSERT statement** (100), **Disable foreign key checks**, **Wrap in a transaction**, **Header comment**. SQL Server dumps use `GO` batch separators for views, routines and triggers. |
| **CSV** | **Delimiter** (comma, semicolon, tab, pipe), **Enclosure**, **Escape character**, **Line ending** (LF or CRLF), **First row contains column names**, **Write NULL as** (`\N`), **UTF-8 BOM (for Excel)**, **Allow several tables (sections)**, **Neutralise spreadsheet formulas** (on by default). Several tables go into one file as sections separated by the table name, only with the multi option. |
| **JSON** | **Pretty print**. |
| **XML** | **Include column definitions**, **Include data**. |
| **Markdown** | **Include column overview**, **Include data**, **Write NULL as**. |
| **Excel (XML)** | **First row contains column names**. |

**Compression**: **None**, **gzip (.gz)**, **zip** (gzip and zip appear when PHP has `zlib` and
`zip`). Set a **File name**, then click **Export**; the download starts when the server has
prepared the file. Your last options per format are remembered in the browser.

**Failure marker.** If an export fails after the download has started, the file cannot be
completed. LDB then appends an explicit marker so a truncated file is not mistaken for a complete
one: a `# LDB EXPORT FAILED: …` line for CSV and Markdown, `-- LDB EXPORT FAILED: …` followed by a
`LDB_EXPORT_FAILED;` statement for SQL (so importing it fails at that point), an XML comment for
XML and Excel, and a `/* … */` comment for JSON. The error is also logged on the server. Always
check the end of important dumps. If nothing was written yet, you get a normal error message.

### 10.2 Import

Import needs a writable connection. The page **Import** has:

**Target**: **Database** (**Choose a target database**; and **Schema** where applicable). For CSV
files you also pick the existing **Target table**.

**File**: drop a file onto the area or click it to choose one. Accepted: `.sql`, `.gz`, `.zip`,
`.bz2`, `.csv`, `.tsv` and `.txt`. The limit shown is the smaller of `upload_max` and PHP's
`upload_max_filesize` / `post_max_size`; larger files are rejected (**The file exceeds the upload
limit of …**). Archives are decompressed as a stream and never extracted to disk. Compressed
files need the matching PHP extension (`zlib`, `zip`, `bz2`).

**Format**: **SQL** or **CSV**, detected from the file name. You can change it.

**SQL options**
- **Stop on first error**: otherwise failing statements are reported and the import continues.
- **Skip statements**: resume an interrupted import: the first N statements are not executed.

**CSV options**
- **Encoding**: UTF-8 by default. For files in other encodings, LDB offers Unicode (UTF-8 and
  UTF-16 variants), Japanese (Shift_JIS/CP932, EUC-JP), Chinese (GB18030, Big5), Korean (EUC-KR,
  CP949), Vietnamese (Windows-1258), Cyrillic (Windows-1251, KOI8-R, KOI8-U), Arabic, Hebrew,
  Greek, Turkish, Baltic and Central European Windows code pages, and ISO-8859-1 to -15. Converting
  non-UTF-8 files needs the PHP `iconv` extension. When a file is not valid UTF-8 LDB tries
  encodings that fit your interface language.
- **Delimiter**: **Detect automatically** (shows **Detected: …**), comma, semicolon, tab or pipe.
  `.tsv` files select tab.
- **Enclosure**, **NULL marker** (fields with exactly this text become NULL; default `\N`),
  **First row contains column names**, **Empty fields become NULL**, **Empty the table first**.
- **Preview and column mapping**: the first rows, decoded in your browser, with a **Table column
  for file column N** selector per file column (or the skip entry). Columns are matched by header
  names automatically when the first row contains names. At least one column must be mapped,
  and a table column may be mapped only once. Compressed files cannot be previewed; their
  columns are matched by the header row.

Click **Import**. A progress bar shows the upload, then **Upload complete, the server is
importing…**. The result shows statements or rows, skipped statements, errors and time, with
messages like **Line 12: …**. You can then **Open database** or **Browse table**.

**Resuming.** If an SQL import stops, LDB tells you **The import stopped after statement N** and
offers **Resume after statement N** or **Retry the failed statement**. Both set **Skip statements**;
choose the corrected file again if needed and start the import. A CSV import stops when there are
too many errors.

Statements run with your own database privileges. A single statement larger than PHP's
`memory_limit` cannot be imported.

---

## 11. Server administration

Open the server overview with the connection switcher or **Go to server overview**. It shows
**Server information** (**Version**, **Host**, **User**, **Character set**, **Uptime**,
**Driver**), buttons for **SQL**, **Import** and **Export**, links to the administration pages and
the list of **Databases** with collation, table count and size.

The administration pages appear only when the engine supports them:

| Page | MySQL / MariaDB | PostgreSQL | SQL Server | SQLite |
|---|---|---|---|---|
| **Variables** | yes | yes | yes | yes (PRAGMA values) |
| **Status** | yes | yes | yes | no |
| **Monitor** | yes | yes | yes | no |
| **Processes** | yes | yes | yes | no |
| **Users** | yes | yes (roles) | yes | no |
| **Character sets** | yes | yes | no | no |
| **Collations** | yes | yes | yes | yes |
| **Engines** | yes | no | no | no |

### 11.1 Variables, status and processes

- **Variables**: searchable (**Search by name or value**), grouped by name prefix; click to copy.
- **Status**: key figures (**Uptime**, **Connections**, **Queries / second**, **Commits / second**,
  **Traffic**, **Cache hit ratio**) and a searchable list of counters.
- **Processes**: the running sessions. the **Auto refresh** toggle (off by default; refreshes every 3 seconds
  while the browser tab is visible), **Hide sleeping**, and queries running longer than 10 seconds are highlighted
  (**Long-running query**). **Kill** ends a process after a confirmation (**The connection is
  terminated and its running statement is aborted.**). Not available on read-only connections.
- **Character sets**, **Collations** and **Engines** are reference lists.

### 11.2 Server monitor

**Monitor** is a live dashboard. It needs no setup for the charts, which are computed from server
counters in your browser.

- **Window** (**Last 5 / 15 / 60 min**) and **Refresh** (**Every 2 / 5 / 10 s**) set the chart range and
  polling rate. **Pause** and **Resume** stop and restart polling (**Live** / **Paused**).
  Samples live in browser memory only (up to 60 minutes); polling pauses while the browser tab is
  hidden and starts fresh when you reopen the page.
- **Key metrics** tiles and charts depend on the engine: queries or transactions or batch requests
  per second, a statement-mix chart, **Connections**, buffer pool or cache hit ratio and **Network
  traffic**, plus items such as replication lag, deadlocks, page life expectancy and blocked
  processes. Charts can be read with the keyboard (arrow keys, Home, End).
- **Queries running longer than 10 s** lists long-running statements with a link to **Process
  list** and, on writable connections, a kill action.
- **Top queries**: pick the **Source** and **Sort by** (**Total time**, **Average time**,
  **Executions**). Each entry offers **EXPLAIN** and **Open in SQL editor**.
- **Tuning hints**: severity **Critical**, **Warning** or **Info**, each with the reason and a
  suggestion. They are heuristics based on counters since the server started; check them against
  your workload before changing settings.

**Prerequisites for Top queries**

| Engine | Source | What to enable |
|---|---|---|
| MySQL / MariaDB | **Slow query log (recent)** | `slow_query_log = ON` with `log_output = 'TABLE'` (the statistics come from `mysql.slow_log`). |
| MySQL / MariaDB | **Performance schema (statement digests)** | `performance_schema = ON` in the server configuration (needs a restart). |
| PostgreSQL | **pg_stat_statements** | Add `pg_stat_statements` to `shared_preload_libraries` in `postgresql.conf`, restart the server, then run `CREATE EXTENSION pg_stat_statements;` in a database. |
| SQL Server | **Query statistics (plan cache)** | Nothing to enable; it reads `sys.dm_exec_query_stats`. |

Example for MySQL or MariaDB:

```sql
SET GLOBAL slow_query_log = ON;
SET GLOBAL log_output = 'TABLE';
SET GLOBAL long_query_time = 1;
```

When neither source is available LDB shows the exact commands for your engine under the panel.
Statements recorded by the monitor itself are filtered out.

### 11.3 Users and privileges

**Users** lists accounts (MySQL/MariaDB users, PostgreSQL roles, SQL Server logins and database
users) with badges such as **Superuser**, **No login**, **Locked**, **Password expired**,
**Member of {roles}**. Use **Filter users** to search.

**Creating accounts.** **New user** (on PostgreSQL the button is called **New role** and
creates a role that can log in) asks for the name, host (MySQL; `%` allows any host), a password
(**Generate** creates one and copies it to the clipboard) and a privilege preset:
**None (login only)**, **All privileges on a database** or **Read-only on a database**. The
resulting **GRANT statements** are shown, one per line, and you can edit them before creating the
user. **New role** creates a role where the engine supports roles; on PostgreSQL the button next to it
is **New group role**, a role without login that other roles can be members of.

**Account actions.** Per account: **Show grants**, **Change password…** and **Drop user** (you
must type the account name). Selecting an account opens the **Privileges** editor.

**Privileges editor.** Section tabs depend on the engine:

- **Overview**: account options (authentication plugin, password expiry, **Account locked**,
  **Require TLS**, resource limits such as **Queries per hour**; PostgreSQL role attributes such as
  **Superuser**, **Create databases**, **Can log in**, **Connection limit**, **Password valid until**).
  Actions: **Rename…** and **Copy privileges from…** (adds everything the other account has;
  removes nothing).
- **Global**: server-wide privileges, grouped as **Data**, **Structure** and **Administration**.
- **Databases**: privileges per database, with **Add a database** (or a wildcard pattern such as
  `app\_%`).
- **Tables & columns**: privileges per table; expand a table to grant on single columns.
- **Roles**: roles granted to the account and **Default role**.
- **Raw**: the reconstructed `GRANT` statements (output of `SHOW GRANTS` on MySQL).

Tick or clear privileges; changes are **staged** in a bar (**N pending changes**, **Discard**,
**Review & apply**). **Review & apply** shows the SQL in **Privilege changes for {name}**;
**Apply** runs it. If some statements fail, **Some statements failed** shows how many succeeded.

LDB never accepts SQL from the browser for this editor: the browser sends a description of the
change, and the server validates privilege names, quotes identifiers and generates the
statements. The database still decides what your account may grant.

**Self-lockout guard.** Changes that could lock out the account you are logged in with
(revoking administrative privileges, `CONNECT`, locking, expiring, renaming or limiting your own
account, requiring TLS for it, and similar) show **These changes affect the account you are
logged in with** and require ticking **I understand I may lose access through this connection**.
This is a safety net against mistakes, not a permission boundary.

On read-only connections the editor opens in view mode (**Read-only**).

---

## 12. Security features for administrators

The full threat model and deployment checklist are in [SECURITY.md](../SECURITY.md). This section
explains what you configure.

### 12.1 Read-only connections

<a id="read-only-connections"></a>

A connection is read-only when the user ticks **Connect read-only**, when its preset has
`'read_only' => true`, or when the global `read_only` option is set. Users can only add the
restriction, never remove one set by the configuration; the preset and global flags are checked on
every request. Enforcement is layered on the server:

1. **API**: every mutating action (row changes, create, alter, drop, rename, copy or truncate of
   tables and databases, maintenance other than check, user administration, killing processes,
   import) is refused with `403 read_only`. Browsing, searching and exporting still work.
2. **SQL editor and EXPLAIN**: statements must start with `SELECT`, `WITH`, `SHOW`, `EXPLAIN`,
   `DESCRIBE`/`DESC`, `VALUES` or `TABLE` (`USE` on MySQL). Data-changing or session-changing
   keywords, `SELECT … INTO`, locking reads, `EXPLAIN ANALYZE` and a list of functions with side
   effects reject the whole batch.
3. **Database session**: MySQL/MariaDB `SET SESSION TRANSACTION READ ONLY`, PostgreSQL
   `default_transaction_read_only = on`, SQLite `PRAGMA query_only = ON` (and, on PHP 8.1 or newer, the file is opened
   read-only). **SQL Server has no session read-only mode**, so only layers 1 and 2 apply.

Read-only mode is a strong guard-rail, but the real security boundary is the database account.
For production, connect with a login that only has `SELECT` (and `SHOW VIEW` / `PROCESS` as
needed) privileges, especially on SQL Server.

### 12.2 Two-factor authentication

Users can protect their database login with a TOTP authenticator app (RFC 6238: SHA-1, 6 digits,
30 seconds) and receive 10 single-use recovery codes. Because LDB has no accounts of its own, the
identity is the database login: the server address (driver, host, port, or the SQLite file) plus
the database user name.

**Administrator setup**

1. Generate a key of at least 32 bytes and put it in `config/config.php`:

   ```sh
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   ```php
   'secret_key' => '…',
   'data_dir'   => '/var/lib/ldb',   // persistent, private, outside the web root
   ```

2. Create `data_dir` with mode 0700, owned by the PHP user. LDB stores one encrypted file per
   login in `<data_dir>/twofa`. Two-factor authentication needs an explicit `data_dir`: a
   temporary-directory default could be wiped on reboot and silently remove everyone's second
   factor, so without it the feature stays unavailable.
3. Back up `data_dir` and `secret_key` together. Changing or losing `secret_key` makes every
   record unreadable, and those logins are then refused until reset.

**User setup.** Open the connection switcher and choose **Account security** (page title
**Two-factor authentication**). Choose **Set up two-factor authentication**, scan the QR code with
an authenticator app (or use **Copy key** to enter the key manually), enter the 6-digit code and
click **Activate**. Then save the **Recovery codes**: **Copy codes** or **Download**, and confirm
with **I have saved them**. They are shown only once. The page also offers **New recovery codes**
(replaces the old ones) and **Disable two-factor authentication**; both need a current code.
Without `secret_key` the page says **Two-factor authentication is not configured on this
server.**

**Enforcement.** Set `twofa_required` to `true` (all logins) or a list of preset ids, for example
`['prod']` (a listed preset also covers free host entry to the same host and port). Logins
without two-factor authentication are sent to **Account security** right after logging in and
can do nothing else until they finish the setup (**Your administrator requires two-factor
authentication for this login. Set it up to continue.**). Users cannot disable a required second
factor (**Required by the administrator**). Read-only connections are not exempt.

If `twofa_required` is set but `secret_key` or `data_dir` is missing, or a record cannot be read or
decrypted, the login is refused rather than skipping the second factor (error
`twofa_unavailable`; the reason is in the PHP error log).

Wrong codes are limited per IP, per login (`twofa_max_failures` per `twofa_window`) and per
pending sign-in. A code cannot be used twice.

Keep free host entry off where two-factor authentication matters: two different names for the
same server (an IP address and a host name) are different identities for LDB, so a user could
reach the same account through the other name without its second factor.

**Lost device: `bin/twofa`.** Run it as the PHP user, since the store is private to that user:

```sh
sudo -u www-data php bin/twofa list
sudo -u www-data php bin/twofa reset --preset=mariadb --user=root
sudo -u www-data php bin/twofa reset --driver=mysql --host=db.internal --port=3306 --user=app
sudo -u www-data php bin/twofa reset --sqlite=/var/lib/ldb/sqlite/app.sqlite
```

`list` shows the enrolled logins with creation time, last use and remaining recovery codes.
`reset` deletes the record of one login; the user logs in with the password alone and can enrol
again (or must, if `twofa_required` applies). Add `--config=FILE` to use another configuration
file. The `--preset` option uses the preset's server address, not its id alone.

Two-factor authentication protects against stolen or guessed passwords used through LDB. It does
not protect the database itself: the same credentials still work with any other client that can
reach the server. WebAuthn and passkeys are not supported.

### 12.3 Single sign-on links

Single sign-on lets a hosting or control panel send a user into LDB already logged in, without the
login form. The panel encrypts the credentials into a short-lived, one-time token with a secret
shared with LDB; the browser then opens `sso.php` with that token.

**Setup**

```php
'sso_secret' => '…',                  // at least 32 bytes
'sso_ttl' => 60,                      // maximum token lifetime in seconds (at most 300)
'sso_allowed_presets' => ['mariadb'], // [] = all presets
```

Generate the secret with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. SSO is off while
`sso_secret` is empty or shorter than 32 bytes.

**The secret is a master key.** Whoever has it can create logins for any credentials on every
server SSO may target, without passing the login form or its rate limit. Store it like a database
root password: only in `config/config.php` (outside the web root, mode 0600, not in version
control) and in the panel's secret storage. Rotate it on both sides when it may have leaked or a
panel administrator leaves.

**Creating a link with `bin/sso-link`**

```sh
php bin/sso-link --preset=mariadb --user=shop --database=shop --url=https://ldb.example.com/
printf '%s\n' "$DB_PASSWORD" | php bin/sso-link --preset=pg --user=app --password-stdin --token-only
```

The password is prompted for (without echo) or read with `--password-stdin`; it is never taken from
an argument. Options: `--preset=ID` or `--driver=` with `--host=` and `--port=`, `--user=`,
`--database=`, `--schema=`, `--table=`, `--redirect='#/c/{conn}/sql'`, `--read-only`, `--label=`,
`--ttl=SECONDS`, `--url=BASE_URL`, `--config=FILE`, `--token-only`. Without `--token-only` the tool
prints a complete link.

**POST or GET.** Prefer **POST**: send the token in the form field `token` to
`https://ldb.example.com/sso.php` (an auto-submitting form or a button). The token then stays out of
browser history, proxy logs and web-server access logs. A **GET** link (`sso.php?token=…`) is
convenient but the token ends up in access logs, which Apache and nginx write by default. It is
one-time and short-lived, yet it still contains the password encrypted with your secret. If you
use GET, turn off query-string logging for `sso.php` and keep `sso_ttl` short.

On success LDB adds the connection to the browser's session, rotates the session id and redirects
to an in-app page. The same checks as the login form apply: presets, host validation, the SQLite
password and `sqlite_paths`, and forced read-only. A token can add read-only mode but never remove
it. By default tokens may only target presets. Logins that have two-factor authentication enabled
are refused unless `sso_bypass_2fa` is set; enable that only when the panel authenticates its
users strongly itself. Failures show a generic "invalid or expired" page; the reason is in the PHP
error log (token expired, replay, preset not allowed, unusable nonce directory, clock skew).

The token format, claims and code samples for PHP and Python are in [sso.md](sso.md).

### 12.4 Rate limiting

Failed logins are counted per client IP (per /64 for IPv6): `login_max_attempts` (default 5) per
`login_window` seconds (default 300). The counters live in `rate_limit_dir`, which must be private
(mode 0700, owned by the PHP user); otherwise the limiter is disabled and a message is logged.
Wrong two-factor codes count toward the same limit, and failed SSO attempts have a separate
counter with the same limits. LDB uses only the connection's `REMOTE_ADDR` and ignores proxy headers such as `X-Forwarded-For`, because clients can forge them. Behind a reverse proxy all users therefore share the proxy's address, and the limit applies to them together, unless the web server rewrites `REMOTE_ADDR` (for example `mod_remoteip` or nginx `realip`).

### 12.5 Production checklist

1. Document root is `public/`.
2. HTTPS only. Behind a TLS proxy, pass `HTTPS=on` to PHP or set `'cookie_secure' => true`.
3. Keep `allow_arbitrary_server` off and list your `servers`, or set `only_presets`.
4. SQLite: `sqlite_paths` outside the web root and a strong `sqlite_password_hash`.
5. Keep `debug` off.
6. Use database accounts with the least privileges that fit the task; use read-only presets where
   people only need to look.
7. On shared hosting, use a private session save path and set `rate_limit_dir` (and
   `run_registry_dir`, `sso_nonce_dir`) to directories of your own.
8. Enable two-factor authentication and `twofa_required` for sensitive servers; protect
   `secret_key` and `data_dir`.
9. Add another layer in front when possible: VPN, IP allow-list or HTTP authentication. An
   internet-facing database admin tool is always an attractive target.
10. Keep PHP and LDB up to date.

---

## 13. Troubleshooting

| Symptom or message | Cause and fix |
|---|---|
| **Your session has expired. Please log in again.** | The session was idle longer than `session_idle_timeout`, the browser cookies were cleared, or PHP's session files were purged. Log in again. If it happens immediately after login, check that cookies are accepted and that the `Secure` flag matches your scheme (set `cookie_secure` behind a TLS proxy). |
| `Invalid or missing CSRF token` (error `csrf`) | The page was loaded before the session changed or expired. Reload the page and retry. Also check that `session_name` is not shared with another application and that a proxy does not strip cookies. |
| `Cross-site request rejected` | The browser marked the request as cross-site. Use LDB from its own URL rather than from an embedded frame or another origin. |
| `Only preconfigured servers may be used` | A free host was sent but `allow_arbitrary_server` is off (or `only_presets` is on). Pick a preset or add one to `servers`. |
| SQLite is not offered, or `SQLite is disabled (no sqlite_paths configured)` | Set `sqlite_paths`. With the default settings the login form shows only presets, so add a preset with `'driver' => 'sqlite'` or enable `allow_arbitrary_server` (see [3.3](#33-sqlite)). |
| `SQLite login is disabled: set sqlite_password_hash in config/config.php` | Set `sqlite_password_hash`, or `sqlite_allow_no_password` on a trusted network. |
| `SQLite file is outside the allowed directories` / `SQLite file path does not exist` | The path must be absolute and inside an entry of `sqlite_paths`. A new file is created only if its directory exists. |
| `Login failed: wrong password` on SQLite | Enter the SQLite password that belongs to `sqlite_password_hash`, not a database password. |
| Login shows **PHP extension missing** for a database type | The PDO driver is not installed or enabled in PHP. Install `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite` or `pdo_sqlsrv` / `pdo_dblib` and restart PHP. |
| **Two-factor authentication is not configured on this server.** / `twofa_unavailable` | Set `secret_key` (at least 32 bytes) and an explicit, persistent, private `data_dir`. If you changed `secret_key`, existing records are unreadable: reset them with `bin/twofa reset`. Check the PHP error log for the reason. |
| **Lost your device and your recovery codes?** | An administrator runs `bin/twofa reset` for that login (see [12.2](#122-two-factor-authentication)). |
| **The file exceeds the upload limit of …** or the upload fails silently | The limit is the smallest of `upload_max`, `upload_max_filesize` and `post_max_size`. Raise all of them, and `client_max_body_size` in nginx. Alternatively compress the file or split the dump. |
| Large imports or exports stop after some time | Raise PHP's `max_execution_time` (imports) and the web server and FastCGI timeouts (`fastcgi_read_timeout`, `ProxyTimeout`). Exports set their own time limit via `export_time_limit`. On nginx, turn `fastcgi_buffering` off for `api.php`. |
| A downloaded dump ends with `LDB EXPORT FAILED` | The export failed after streaming started. See the PHP error log, fix the cause and export again. |
| A query ends with a timeout error | The statement hit `query_timeout` (default 30 s). Raise it in `config/config.php` or run the statement from a client without a timeout. On MySQL the timeout applies to `SELECT` only. |
| **Stop** is missing | SQLite cannot cancel queries. For other engines the registry directory must be private; check the log for a message about `run_registry_dir`. |
| The query could not be stopped | It may have finished already, or the database user lacks the privilege to cancel it (on SQL Server `ALTER ANY CONNECTION`). |
| SQL Server login fails with a driver message | Install the Microsoft ODBC Driver 17/18 and `pdo_sqlsrv`, or `pdo_dblib`. See [2.6](#26-sql-server-drivers). Certificate errors with ODBC Driver 18: LDB trusts the server certificate by default; check the preset option `trust_server_certificate`. |
| **The statement is not available** on a read-only connection (`403 read_only`) | The connection is read-only. Log in again without **Connect read-only**, unless the preset or `read_only` forces it. |
| Monitor: **Neither the slow query log … nor the performance schema is enabled** | See the prerequisites in [11.2](#112-server-monitor). |
| Monitor: **The pg_stat_statements extension is not installed** (or **its library is not loaded**) | Add `pg_stat_statements` to `shared_preload_libraries`, restart PostgreSQL, then `CREATE EXTENSION pg_stat_statements;`. |
| Monitor shows **Monitoring is not available for this database engine.** | SQLite has no monitor. |
| **Editing needs a primary key** | Tables without a primary key can be browsed, not edited. Add a primary key in the **Structure** tab. |
| Event does not run | The event scheduler is off. Run `SET GLOBAL event_scheduler = ON;` (the event editor shows a notice). |
| Blank page, 403 or 404 for assets | The web root is wrong or `.htaccess` is not honoured. Make `public/` the document root, and enable `AllowOverride` and `mod_rewrite` for drop-in installs. |
| After an upgrade the page looks broken | Browser cache or leftover old files in `public/assets/dist/`. Delete that directory, unpack the release again, and hard-reload the page. |

Server errors are logged with `error_log()`; check the PHP error log first. Temporarily setting
`'debug' => true` adds details to API responses. Never leave it on in production.

---

## 14. Getting help

- **Bugs, questions and feature requests**: open an issue at <https://github.com/Pluxx/ldb>.
  Include the LDB version (shown below the login form), the database engine and version, PHP
  version, what you did and what you expected.
- **Security problems**: do not open a public issue. E-mail **dev@phongluutran.de** or use GitHub's
  **Report a vulnerability** button on the repository. Include the version, the database engine
  and steps to reproduce. You can expect an answer within 7 days; reporters are credited unless
  they ask not to be.

Related documents in the repository: [README.md](../README.md), [SECURITY.md](../SECURITY.md),
[sso.md](sso.md), [nginx.conf.example](nginx.conf.example), [CHANGELOG.md](../CHANGELOG.md).
