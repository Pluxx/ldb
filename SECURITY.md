# Security

LDB gives whoever logs in the full permissions of the database account they log in with,
including an SQL editor. Its task is to make sure that nobody gets **more** than that: no access
without valid database credentials, no reach beyond the configured servers and SQLite
directories, and no way for other websites or hostile data to act on behalf of a logged-in user.

## Reporting a vulnerability

Please report security problems privately, either by e-mail to dev@phongluutran.de or with GitHub's
"Report a vulnerability" button, and not in public issues. Please include the version
(`src/Version.php`), the database engine and the steps to reproduce the problem. I aim to answer within
7 days and will credit reporters unless they ask me not to.

## Threat model

| Attacker | What protects the installation |
|---|---|
| Anonymous visitor | Every API action except `auth.status`/`auth.login` requires a session holding a working DB login. `sso.php` (off by default) only accepts tokens encrypted with `sso_secret`. Login is rate-limited per IP (per /64 for IPv6). Please note that the client IP is currently taken from `REMOTE_ADDR` only (see "Behind a reverse proxy" below). Only preset servers can be used unless `allow_arbitrary_server` is enabled (default off), so LDB cannot be used to scan the internal network. Connection values are quoted or rejected before they go into the PDO DSN, so a database name like `x host=10.0.0.1` cannot change which server is used. SQLite requires `sqlite_password_hash`. |
| Low-privilege DB user | Can only do what their DB account allows. The application adds nothing beyond that, except for SQLite: there `ATTACH DATABASE` and `VACUUM INTO` are blocked because they could read or write files outside `sqlite_paths` (for example a `.php` file in the web root). `load_extension()` is disabled by PHP. MySQL `LOAD DATA LOCAL INFILE` is disabled, so a hostile server cannot read files from the web server. |
| Hostile data in the database (names, cells, comments, routine bodies, error messages) | The UI builds the DOM only through text nodes and attributes. It does not use `innerHTML`, `eval` or inline handlers, and the CSP blocks inline scripts. Identifiers are always quoted with the driver's `quoteIdentifier()`; values are bound. CSV exports neutralise formula cells (`excel_safe`, default on). |
| Malicious import file | Uploads must be real `is_uploaded_file()` uploads within `upload_max`, and are stored under a random temp name that is deleted afterwards. Archives (gz/bz2/zip) are decompressed as a stream into the database and never extracted to disk (no zip-slip). Statements run with the user's own DB privileges. |
| Other websites (CSRF, clickjacking) | Cookies are `HttpOnly; SameSite=Strict` (and `Secure` over HTTPS or with `cookie_secure`). Every POST needs the `X-CSRF-Token` header. The export form uses a `csrf` field instead. POSTs marked by the browser as `Sec-Fetch-Site: cross-site` or `same-site` are rejected. Login only accepts `application/json`, which blocks login-CSRF. `frame-ancestors 'none'` and `X-Frame-Options: DENY` are sent, except on export downloads, which use `'self'`/`SAMEORIGIN` so the UI's hidden frame can read errors. Also sent: `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy: same-origin`. |
| Stolen session file / shared hosting | DB passwords in the session are encrypted with AES-256-GCM. The key lives only in a separate `ldb_k` cookie and is rotated on logout. The session ID is regenerated on login and logout (`use_strict_mode`). There is an idle timeout (`session_idle_timeout`). The rate-limiter directory must be private (0700, owned by the PHP user), or the limiter is disabled and a message is logged. |
| Attacker who knows a DB password | Optional two-factor authentication per login (TOTP app + single-use recovery codes, `twofa_required` to enforce it): the password alone no longer opens LDB. Code attempts are limited per IP, per login and per pending login. See *Two-factor authentication*. |

The following is out of scope: anything the logged-in DB account is allowed to do (including running
any SQL, `GRANT` and reading server variables and processes), and the security of the database
servers themselves.

### Behind a reverse proxy

The rate limiter identifies clients by `REMOTE_ADDR` only. LDB does not yet support trusted reverse
proxies (see [TODO.md](TODO.md)). Please note that all users behind a reverse proxy therefore share one
rate-limit bucket, and that `X-Forwarded-For` is deliberately ignored, because it can be forged.

## Deployment checklist

1. **Document root = `public/`.** If that is not possible, the root `.htaccess` (and the
   `Require all denied` `.htaccess` files in `src/ config/ tests/ bin/ docker/ resources/ docs/`)
   deny access to internal files on Apache. On nginx, please copy the rules from `docs/nginx.conf.example`. Without
   mod_rewrite, `vendor/*.php` stays reachable, but it only defines classes and prints nothing.
2. **HTTPS only.** Behind a TLS-terminating proxy, please pass `HTTPS=on` to PHP or set
   `'cookie_secure' => true`. HSTS is sent automatically on HTTPS.
3. **Restrict servers.** Keep `allow_arbitrary_server` off and list `servers`, or set
   `only_presets`. Please note that the host and port of the presets are shown to anonymous visitors on the login page.
4. **SQLite:** set `sqlite_paths` to directories **outside the web root**, and set
   `sqlite_password_hash` (`php -r 'echo password_hash("…", PASSWORD_DEFAULT);'`). Anyone who
   knows this password can read and write every database in those directories. Setting
   `open_basedir` adds a second barrier (pdo_sqlite then also checks attached files).
5. **Keep `debug` off** (the default). Error messages from the database are still shown to
   logged-in users, because they need them. Login errors show the driver's message, which can
   reveal the server's internal address.
6. **Shared hosting:** please use a private session save path and set `rate_limit_dir` to a
   directory of your own if `/tmp` is shared.
7. Optionally, restrict the access further (VPN, IP allow-list, HTTP authentication in front). A database administration tool
   that is reachable from the internet is always an attractive target.

## Hardening options (config/config.php)

| Key | Default | Purpose |
|---|---|---|
| `allow_arbitrary_server` | `false` | Free entry of host and port on the login page |
| `only_presets` | `false` | Only the `servers` presets, no free entry of a host |
| `sqlite_paths` | `[]` | SQLite allow-list (empty = SQLite disabled) |
| `sqlite_password_hash` | `''` | Password required for SQLite logins |
| `sqlite_allow_no_password` | `false` | Allow password-less SQLite logins (trusted LAN only) |
| `cookie_secure` | `null` | Force `Secure` cookies (`null` = auto-detect HTTPS) |
| `session_idle_timeout` | `1800` | Seconds of inactivity before logout |
| `login_max_attempts` / `login_window` | `5` / `300` | Failed logins per IP (IPv6: /64) per window; the IP is taken from `REMOTE_ADDR` only |
| `rate_limit_dir` | `null` | Private directory for rate-limit counters |
| `run_registry_dir` | `null` | Private directory for the running-query registry (SQL editor *Stop*) |
| `upload_max` | 64 MiB | Import size limit (php.ini limits also apply) |
| `query_timeout` | `30` | Statement timeout where the engine supports it |
| `read_only` | `false` | Force every connection to be read-only (see below) |
| `servers[].read_only` | `false` | Force read-only for one preset (users cannot turn it off) |
| `sso_secret` | `''` | Single sign-on links (at least 32 bytes; empty = disabled), see below |
| `sso_ttl` | `60` | Maximum SSO token lifetime in seconds (capped at 300) |
| `sso_allowed_presets` | `[]` | Presets SSO may target (`[]` = all) |
| `sso_allow_arbitrary_server` | `false` | SSO tokens may name a free driver/host/port (never with `only_presets`) |
| `sso_nonce_dir` | `null` | Private directory for used SSO nonces |
| `secret_key` | `''` | Key (at least 32 bytes) for two-factor records; empty = 2FA unavailable, see below |
| `data_dir` | `null` | Persistent private directory for 2FA records (`<data_dir>/twofa`); required for 2FA |
| `twofa_required` | `false` | `true` or a list of preset ids: logins must set up 2FA before anything else |
| `twofa_max_failures` / `twofa_window` | `10` / `900` | Wrong second-factor codes per login (identity) per window |
| `sso_bypass_2fa` | `false` | SSO links may log in to logins that have 2FA enabled |

## Single sign-on links

`public/sso.php` logs a browser in from a token that a control panel creates with the shared
`sso_secret` (format and examples: [docs/sso.md](docs/sso.md)).

- **The secret is a master key.** Whoever has it can create logins for any credentials on
  every server that SSO may target, without passing the login form or its rate limit. Please store it like a
  database root password: only in `config/config.php` (outside the web root, mode 0600, not in
  version control) and in the secret storage of the panel. Please use at least 32 random bytes
  (`bin2hex(random_bytes(32))`), because HKDF does not strengthen a weak secret. **Rotate the secret** (change
  it on both sides) when it may have leaked or when a panel administrator leaves. Old tokens stop working immediately.
- Tokens are AES-256-GCM encrypted and authenticated (key from HKDF-SHA256, version byte as
  AAD). The DB password inside stays confidential, and any change to the token is rejected.
  Each token can be used only once (a nonce marker is created atomically in a private 0700 directory;
  without a usable directory **every** token is rejected, so storage problems fail closed) and
  short-lived (`exp - iat` at most `sso_ttl`, which is at most 300 s, and `iat` at most 30 s in the future).
- The login goes through the same code as the login form (`Auth\LoginService` →
  `DriverFactory::resolveLogin()`). Presets, host validation, the SQLite password and
  `sqlite_paths`, and forced read-only all apply; a token can add read-only but never remove
  it. By default tokens may only target presets (`sso_allow_arbitrary_server`;
  `sso_allowed_presets` narrows the list). The session ID is regenerated on login.
- The redirect target can only be an `#/…` route inside the application, made of URL-fragment characters and
  sent as `Location: index.php#…`. Absolute URLs, `//host`, `javascript:` and CR/LF are rejected.
- Responses send `Cache-Control: no-store` and `Referrer-Policy: no-referrer`, so the token
  cannot leak through `Referer`. LDB never logs the token. Failures show one generic page
  (reason only in the PHP error log) and are rate-limited per IP (`login_max_attempts`/
  `login_window`, separate counter from the login form).
- **Please use HTTPS and prefer POST** (form field `token`). GET links put the token in the browser
  history and in the **web server access logs**, because Apache and nginx log query strings by default. Please turn
  that off for `sso.php` if you use GET links, and keep `sso_ttl` short.
- As with any link-based SSO, a user who forwards a fresh link gives the recipient that
  connection. A cross-site link starts a new browser session (the `SameSite=Strict` cookies
  are not sent), so it does not merge into a session the recipient already has.

## Two-factor authentication

LDB has no user accounts. The identity is the database login: the server address (driver,
host and port, also for presets, or the SQLite file) plus the database user name. A preset and
free host entry to the same host and port are therefore the same login. Different names for one
server (an IP address instead of the host name, or `localhost` and `127.0.0.1`) are not recognised.
With `allow_arbitrary_server` switched on, a user could therefore reach the same account without its 2FA.
Please keep free host entry off (the default) where 2FA matters. Any user can protect
the login under *Account security* (connection menu, `#/c/{conn}/security`) with a TOTP app
(RFC 6238: SHA-1, 6 digits, 30 s, ±1 step) and receives 10 single-use recovery codes.

- **Setup:** `secret_key` must be set (`php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`).
  Records are stored one file per identity in `<data_dir>/twofa/<sha256(identity)>.json`,
  encrypted and authenticated with AES-256-GCM (key = HKDF-SHA256 of `secret_key`; the file
  name is authenticated data, so a record cannot be copied onto another login). Recovery codes
  are stored only as `password_hash()` values. Writes are atomic (temp file + rename, 0600)
  under a per-identity lock. **2FA needs an explicit `data_dir`: a persistent, private (0700,
  owned by the PHP user) directory outside the web root.** Without it, 2FA stays unavailable.
  A default in the temp directory could be wiped on reboot and would silently remove the 2FA of all users.
- **Fail closed:** a record that cannot be read or decrypted (tampered, `secret_key` changed or
  removed), an unsafe store directory, or `twofa_required` without `secret_key` or `data_dir` make the login
  fail (`twofa_unavailable`, reason in the PHP error log) instead of skipping the second factor.
  Please treat `secret_key` like `sso_secret`: changing it locks out every enrolled login until it is reset.
- **Login flow:** after the password has been verified, nothing is stored yet. The verified
  parameters are kept as a pending login (encrypted with the session key cookie, like stored
  credentials) for 5 minutes and for at most 5 codes. After that, it is discarded. `auth.verify2fa` takes
  a TOTP code or a recovery code (CSRF-protected) and only then stores the connection and
  regenerates the session ID. A code's time step must be newer than the last accepted one, so
  a code cannot be replayed. Wrong codes count towards the per-IP login limiter (which is only
  cleared after the second factor) and a per-identity lockout (`twofa_max_failures` per
  `twofa_window`, also used for disabling and regenerating recovery codes).
- **Policy:** `twofa_required` (`true` or a list of preset ids; a listed preset also covers
  free host entry to its host:port) forces logins without 2FA into
  the setup right after the login. The API answers every other action with `403 twofa_enroll_required`
  (only `auth.*` and `twofa.status/enrollStart/enrollConfirm` remain available). Users cannot disable a
  required 2FA. Read-only connections are not exempt, because 2FA concerns the login.
- **SSO:** an SSO token for a login with 2FA enabled is rejected (logged: `… two-factor
  authentication is enabled for it`) unless `sso_bypass_2fa` is set. Please enable that option only when the
  panel authenticates its users strongly itself, because the token then replaces both factors.
- **Lost device:** `php bin/twofa list` and `php bin/twofa reset --preset=ID --user=NAME`
  (or `--driver=… --host=… [--port=…] --user=…`, `--sqlite=FILE`). Run it as the PHP user
  (for example `sudo -u www-data php bin/twofa list`), because the store is private to that user.
- **Limits:** TOTP protects against stolen or guessed passwords used through LDB. It does not
  protect the database itself: the same credentials still work with any other client that can
  reach the server. LDB does not currently support WebAuthn or passkeys.

## Read-only connections

A connection is read-only when the user ticks *Connect read-only* on the login form, when its
preset has `'read_only' => true`, or when the global `read_only` option is set. Users can only
add the restriction and can never remove one imposed by the configuration (preset/global flags are
re-checked on every request). The enforcement happens on the server and has several layers:

1. **API routes:** every mutating action (row insert/update/delete, create/alter/drop/rename/
   copy/truncate/empty tables, maintenance except `check`, create/drop/alter/copy/rename database, user admin,
   killing processes, import) answers `403` with code `read_only`. Browse, search and export work.
2. **SQL editor and EXPLAIN:** statements must start with `SELECT`, `WITH`, `SHOW`, `EXPLAIN`,
   `DESCRIBE`/`DESC`, `VALUES` or `TABLE` (`USE` on MySQL). Any data-modifying or session-changing
   keyword outside literals/comments (`INSERT`, `UPDATE`, `DELETE`, `SET`, `PRAGMA`, …),
   `SELECT … INTO`, locking reads (`FOR UPDATE/SHARE`), `EXPLAIN ANALYZE` and a list of functions
   with side effects (`nextval`, `set_config`, `pg_terminate_backend`, `lo_import`, `dblink`,
   `get_lock`, `sleep`, `load_file`, …) reject the whole batch (`Sql\ReadOnlyGuard`). Ambiguous
   lexing (backslash escapes, `--` rules, executable comments) is resolved conservatively.
3. **Database session:** MySQL/MariaDB `SET SESSION TRANSACTION READ ONLY`, PostgreSQL
   `default_transaction_read_only = on`, SQLite `PRAGMA query_only = ON` (and, on PHP >= 8.1,
   the file is opened with `SQLITE_OPEN_READONLY`). This also stops writes hidden in functions or
   procedures the statement filter cannot know about. **SQL Server has no session read-only
   mode**: only layers 1 and 2 apply there.

Read-only mode is a strong safeguard, but **the real security boundary is the database
account**. For production, please connect with a login that only has `SELECT` privileges (and `SHOW VIEW` or
`PROCESS` as needed). This applies especially to SQL Server and to every server where functions
with side effects exist (for example `dblink`, untrusted PL languages or user-defined functions with
`SECURITY DEFINER`). Read-only connections still allow long-running or heavy
queries without locks; `query_timeout` limits those.

## Known limitations

- The privileges editor never accepts SQL from the browser: it sends a change description, and the
  server validates privilege names against per-driver whitelists, quotes every identifier and
  re-generates the statements on apply. Changes that could lock the logged-in account out (revoking
  admin privileges or CONNECT, locking or renaming the account itself) need an explicit confirmation (`confirm_self`).
  This is a safety net against mistakes and not a permission boundary: the database decides what the
  account may grant.
- The SQL editor runs whatever the user types. The `GRANT`-only filter in the user editor and
  the read-only checks are usability helpers and not security boundaries. Query history and snippets
  (including any passwords typed into SQL) are kept in the browser's `localStorage`.
- `style-src 'unsafe-inline'` is required for the ERD's embedded SVG stylesheet. Scripts are
  strictly limited to `'self'`.
- **Stopping queries** (`sql.cancel`): running editor queries are registered in a private
  directory (same 0700 and owner checks as for the rate limiter; if the directory is unsafe, Stop is disabled and a message is logged) under
  sha256(session id | client run id), without credentials. A cancel only finds runs of its own
  session and connection and is allowed on read-only connections (it stops the user's own query).
  On SQL Server, Stop ends the whole session (`KILL`), which needs `ALTER ANY CONNECTION`.
- Exports that fail after streaming has started produce a truncated file (the error is logged).
- The import of a single huge statement is limited only by PHP's `memory_limit`.
