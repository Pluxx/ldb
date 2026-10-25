# Single sign-on links (control panels)

A hosting or control panel that already knows the database credentials of a user can send the user
directly into LDB, already logged in, without showing the login form. The panel encrypts
the credentials into a short-lived **token** that can be used only once, with a secret that it shares with LDB.
The browser then opens `sso.php` with that token.

```
panel ──(token)──▶ browser ──POST/GET sso.php──▶ LDB: verify → log in → 303 index.php#/c/{conn}/…
```

## Enable it

In `config/config.php`:

```php
'sso_secret' => '…',               // at least 32 bytes; php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
'sso_ttl' => 60,                   // max token lifetime (exp - iat) in seconds, at most 300
'sso_allowed_presets' => [],       // preset ids SSO may target ([] = all presets)
'sso_allow_arbitrary_server' => false, // allow driver/host/port tokens instead of presets
'sso_nonce_dir' => null,           // private dir for used nonces (default: system temp dir)
```

SSO is switched off while `sso_secret` is empty or shorter than 32 bytes. Please read the SSO section in
[SECURITY.md](../SECURITY.md) before you enable it. **Anyone who has the secret can log in to
every server that SSO may target.**

## Opening a link

- **POST (recommended):** an auto-submitting form, or a button, that sends the field `token`
  to `https://ldb.example.com/sso.php` (`application/x-www-form-urlencoded`). The token is
  then not written to browser history, proxy logs or web-server access logs.
- **GET (convenience):** `https://ldb.example.com/sso.php?token=…`. Web servers usually log
  query strings, so the token ends up in access logs. It can be used only once and expires quickly, but
  it still contains the password, encrypted with your secret. Please keep `sso_ttl` short.

On success, LDB adds the connection to the session of the browser, regenerates the session ID
and answers with `303` with a redirect to `index.php#…` (always this application). On failure, it
shows a generic "invalid or expired" page and logs the reason with `error_log()`. Failures are
rate-limited per client IP with `login_max_attempts`/`login_window`, in a counter separate from
the login form. A cross-site link starts a new browser session, because the session cookies are
`SameSite=Strict`.

The new connection goes through **exactly the same checks as the login form**: preset lookup,
driver availability, host validation, the SQLite password and `sqlite_paths`, and forced
read-only from the preset or the global config. A token can add read-only mode but can never
remove it. Logins with two-factor authentication enabled are refused unless `sso_bypass_2fa`
is set (the panel then vouches for the user; see SECURITY.md). Without a `preset`, a token is refused unless `sso_allow_arbitrary_server` is on
(and `only_presets` is off). SQLite tokens also require that option, plus the SQLite password in
`password`.

## Token format (version 1)

```
key   = HKDF-SHA256(ikm = sso_secret (raw bytes), salt = "" (i.e. 32 zero bytes), info = "ldb-sso v1 aes-256-gcm", L = 32)
iv    = 12 random bytes
ct, tag = AES-256-GCM(key, iv, plaintext = UTF-8 JSON claims, aad = 0x01)       (tag: 16 bytes)
token = base64url_nopad( 0x01 || iv || ct || tag )
```

Claims (JSON object):

| claim | type | |
|---|---|---|
| `preset` | string | id of a `servers` preset. **Or** `driver` (`mysql`/`pgsql`/`sqlite`/`sqlsrv`) + `host` (+ `port` int) when `sso_allow_arbitrary_server` is on. A token may not contain both |
| `user`, `password` | string | database credentials. For SQLite, `password` is the SQLite login password |
| `database` | string, optional | default database (SQLite: the file path) |
| `schema` | string, optional | used with `database` for the start page (`db@schema`) |
| `read_only` | bool, optional | connect read-only |
| `label` | string, optional | name shown in the connection switcher |
| `redirect` | string or object, optional | start page: an app route such as `"#/c/{conn}/sql"` (`{conn}` is replaced by the new connection id), or `{"db": "shop", "schema": "public", "table": "orders"}`. Only `#/…` routes made of URL-fragment characters are accepted. Default: `database`, or else the server overview |
| `iat`, `exp` | int (Unix time) | required. `exp - iat` must not exceed `sso_ttl`; `iat` may be up to 30 s in the future (clock skew) |
| `nonce` | string | required, base64url of 16 random bytes (16 to 128 characters `A-Za-z0-9_-`); every token can be used only once |

Tokens are limited to 8192 characters. Unknown claims are ignored.

## Generating links

**From the command line** (reads `sso_secret` from `config/config.php`):

```sh
php bin/sso-link --preset=mariadb --user=shop --database=shop --url=https://ldb.example.com/
printf '%s\n' "$DB_PASSWORD" | php bin/sso-link --preset=pg --user=app --password-stdin --token-only
```

The password is requested interactively (without echo) or read with `--password-stdin`. It is never taken from
an argument. Inside the Docker dev stack, use `docker compose exec app php bin/sso-link …`.

**From PHP inside the LDB code base:** `Ldb\Auth\Sso::createUrl($baseUrl, $claims, $secret)`
or `Sso::createToken($claims, $secret)`. Missing `iat`/`exp`/`nonce` are filled in.

**Standalone PHP (7.1.2 or newer, with ext-openssl)**, for example in a panel:

```php
function ldb_sso_token(array $claims, string $secret): string {
    $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $claims += ['iat' => time(), 'exp' => time() + 60, 'nonce' => $b64(random_bytes(16))];
    $key = hash_hkdf('sha256', $secret, 32, 'ldb-sso v1 aes-256-gcm', '');
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt(json_encode($claims), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "\x01", 16);
    return $b64("\x01" . $iv . $ct . $tag);
}
$token = ldb_sso_token(['preset' => 'mariadb', 'user' => $user, 'password' => $pw, 'database' => $db], $secret);
// <form method="post" action="https://ldb.example.com/sso.php"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><button>Open phpMyAdmin</button></form>
```

**Python 3** (`pip install cryptography`):

```python
import base64, json, os, time
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

def ldb_sso_token(claims: dict, secret: bytes) -> str:
    b64 = lambda b: base64.urlsafe_b64encode(b).rstrip(b"=").decode()
    now = int(time.time())
    claims = {"iat": now, "exp": now + 60, "nonce": b64(os.urandom(16)), **claims}
    key = HKDF(algorithm=hashes.SHA256(), length=32, salt=None, info=b"ldb-sso v1 aes-256-gcm").derive(secret)
    iv = os.urandom(12)
    ct_and_tag = AESGCM(key).encrypt(iv, json.dumps(claims).encode(), b"\x01")  # returns ct || tag
    return b64(b"\x01" + iv + ct_and_tag)

token = ldb_sso_token({"preset": "mariadb", "user": "shop", "password": "…"}, b"<sso_secret>")
```

## Troubleshooting

The browser only ever shows "invalid or expired". The PHP error log contains the reason, for example
`[ldb] SSO rejected: token expired 4s ago`, `… token issued in the future` (check NTP on
both machines), `… token already used (replay)`, `… preset "x" is not in sso_allowed_presets`,
`… replay protection store unavailable` (the nonce directory must be private: 0700 and owned by
the PHP user), `… login failed (twofa_required): …` (2FA is enabled for that login; see
`sso_bypass_2fa`), or `… login failed (db_error): …`.
