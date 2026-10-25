# LDB: Architecture and Contracts

LDB is a modern replacement for phpMyAdmin. Composer project (PSR-4, no runtime
dependencies beyond PHP extensions), web root is `public/`. Frontend is Tailwind CSS v4 +
Lucide icons + vanilla ES modules, bundled by esbuild into `public/assets/dist/`. The built
assets are committed, so a deployment only needs PHP (`composer install --no-dev`, or not even that:
`public/index.php` falls back to `src/autoload.php` when `vendor/` is missing).

Supported engines (via PDO): **MySQL / MariaDB / Percona** (primary, full feature set),
**PostgreSQL**, **SQLite 3**, **Microsoft SQL Server** (pdo_sqlsrv or pdo_dblib).
Features that a driver cannot support are switched off through `capabilities()`, and the UI hides them.
Supported server versions: every release of 2018 or later (MySQL 8.0.11, MariaDB 10.3, PostgreSQL 11,
SQL Server 2019, SQLite 3.22). `Ldb\Db\ServerVersion` parses and compares versions and holds this policy;
each driver exposes `serverVersion()` (normalized) and decides version-dependent capability keys from it
(list in DriverInterface::capabilities()). Older servers still connect: `serverInfo()` and the connection
metadata report `version_supported:false` + `min_version`, and the UI shows a dismissible warning banner.
`simulateServerVersion()` lets unit tests exercise the version paths without such a server.

## Hard rules (all code)

- PHP **7.4 compatible**: typed properties and arrow functions are allowed. Please do NOT use union types, `match`,
  nullsafe `?->`, named arguments, constructor promotion, `mixed`, `static` return type,
  enums, readonly, `str_contains`/`str_starts_with` (use `strpos`), attributes.
- `declare(strict_types=1);` in every PHP file. Namespace `Ldb\…`, PSR-4 from `src/`
  (`Ldb\Db\MySqlDriver` → `src/Db/MySqlDriver.php`) via composer.json; `src/autoload.php`
  is a tiny PSR-4 fallback used only when `vendor/autoload.php` is absent.
- All values go through prepared statements. Identifiers only through
  `$driver->quoteIdentifier()`. Please never interpolate user input into SQL in any other way.
  (The SQL editor executes user SQL verbatim; that is its purpose.)
- Frontend sources in `resources/js/**` (vanilla ES modules, no framework) and
  `resources/css/app.css` (Tailwind v4, `@import "tailwindcss"`, `@theme` tokens, class-based
  dark mode: `@custom-variant dark (&:where([data-theme=dark], [data-theme=dark] *));`).
  Icons: `import { createIcons, Table, Database, … } from 'lucide'` or the `icon(name)` helper
  in `resources/js/ui/icons.js` (wraps lucide's `createElement`). No CDN; must work offline.
  Build: `npm run build` (esbuild bundle → `public/assets/dist/app.js`, `@tailwindcss/cli` →
  `public/assets/dist/app.css`); `npm run dev` watches. No Node locally: run via
  `docker run --rm -v "$PWD":/app -w /app node:22-alpine npm run build`.
- No inline `<script>`/`style=""` attributes in HTML templates (strict CSP:
  `default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'`). JS may set `el.style.x`.

## Layout

```
composer.json              name ldb/ldb, php >=7.4, ext-pdo/json/openssl/mbstring, PSR-4,
                           require-dev phpunit/phpunit ^9.6, scripts: test, lint
package.json               devDeps: tailwindcss, @tailwindcss/cli, esbuild, lucide
public/index.php           SPA shell (HTML, loads assets/dist/app.js + app.css)
public/api.php             JSON API front controller
public/sso.php             single sign-on entry point (docs/sso.md; Auth\SsoEndpoint)
public/assets/dist/        BUILT, committed
config/config.sample.php   copy to config/config.php (optional)
resources/js/, resources/css/app.css   frontend sources
src/autoload.php           PSR-4 fallback when vendor/ missing
src/bootstrap.php          error handling, config loading, security headers
src/Config.php
src/Http/{Request,Response,ApiException}.php
src/Auth/{SessionManager,Csrf,Crypto,RateLimiter}.php
src/Auth/LoginService.php       the one login path (auth.login + SSO): rate limit, resolveLogin, connect, store
src/Auth/{Sso,SsoEndpoint,SsoNonceStore,SsoException}.php   SSO tokens (AES-256-GCM/HKDF), one-time nonces
src/Auth/{Totp,TwoFactor,TwoFactorStore}.php   2FA: RFC 6238 TOTP, policy/recovery codes, encrypted per-identity records
src/Controller/TwoFactorController.php   twofa.* actions (self-service setup/disable)
bin/twofa                  CLI: list / reset 2FA records (lost devices)
src/Db/DriverInterface.php      ← CONTRACT (written, do not change signatures silently)
src/Db/AbstractPdoDriver.php    shared PDO plumbing (+ serverVersion()/versionSupport())
src/Db/ServerVersion.php        version parsing/comparison, supported minimum per engine
src/Db/{MySqlDriver,PgSqlDriver,SqliteDriver,SqlSrvDriver}.php
src/Db/DriverFactory.php
src/Sql/{SqlSplitter,SqlFormatter}.php
src/Sql/{SqlLexer,ReadOnlyGuard,TransactionPolicy,ScriptRunner,RunRegistry}.php   editor guards, tx modes, cancel
src/Support/PrivateDir.php   private (0700, owner-checked) state dirs (rate limiter, run registry)
src/Controller/*Controller.php
src/Export/{ExporterInterface,SqlExporter,CsvExporter,JsonExporter,XmlExporter,MarkdownExporter,ExcelXmlExporter}.php
src/Import/{SqlImporter,CsvImporter}.php
resources/js/app.js, api.js, router.js, store.js, ui/*.js, views/*.js, sql/*.js
tests/Unit, tests/Integration   PHPUnit 9 (php 7.4 compatible); integration uses docker DBs
docker-compose.yml          dev stack: php 7.4 built-in server + mariadb + mysql + postgres
```

## Session / auth

- Login posts credentials; on success creds are stored in `$_SESSION` **encrypted** with
  AES-256-GCM (key = random 32 bytes kept in a cookie `ldb_k`, HttpOnly, SameSite=Strict;
  session alone cannot decrypt). Session cookie HttpOnly + SameSite=Strict + Secure when HTTPS.
- Multiple connections per session are allowed (`connections[]`, each with `id`); every API
  call carries `conn` (connection id). The UI shows a connection switcher.
- CSRF: token returned by `auth.status`/`auth.login`, sent as header `X-CSRF-Token` on every
  mutating call (all POSTs). Login itself is exempt but rate-limited (5 fails / 5 min per IP,
  file-based in `sys_get_temp_dir()`).
- `config/config.php` may define: `servers` (preset list, optionally locked: `only_presets`),
  `sqlite_paths` (allowed directories/glob for SQLite; SQLite disabled if empty),
  `max_rows_browse` (default 1000), `query_timeout`, `upload_max`, `theme_default`,
  `allow_arbitrary_server` (default false), `sqlite_password_hash` (required for SQLite logins unless
  `sqlite_allow_no_password`), `cookie_secure`, `rate_limit_dir`. See SECURITY.md.
- POSTs with `Sec-Fetch-Site: cross-site|same-site` are rejected; `auth.login` only accepts JSON bodies.
  SQLite connections block `ATTACH`/`VACUUM INTO` (`Db\SqlitePdo`).

## HTTP API

`api.php?action=<name>`; GET for pure reads is allowed but the frontend always POSTs JSON.
Body: JSON object. Common params: `conn` (connection id), `db`, `schema` (PostgreSQL /
SQL Server; optional elsewhere), `table`.

Success: `{"ok":true,"data":…}`  Error: `{"ok":false,"error":{"message":"…","code":"…","sql":"…?"}}`
with HTTP 4xx/5xx. Codes: `auth_required`, `csrf`, `bad_request`, `not_found`, `twofa_invalid` (details.remaining
on login), `twofa_expired`, `twofa_unavailable`, `twofa_enroll_required`, `twofa_policy`, `twofa_required` (SSO), `conflict`,
`db_error`, `unsupported`, `rate_limited`, `read_only`, `self_lockout` (privileges editor: the change could lock the login account out), `in_use` (database busy: PG rename/template copy, SQL Server collation/rename), `cancelled` (query stopped via `sql.cancel`), `stale` (`diff.apply`: the schemas changed since `diff.plan`), `internal`.
Read-only connections (`read_only` in connection meta / `server.overview`): routes flagged
`WRITE` in `Kernel` answer 403 `read_only`; `sql.execute`/`sql.explain` go through
`Sql\ReadOnlyGuard`; drivers switch the session via `DriverInterface::setReadOnly()`. See SECURITY.md.
SQL editor transactions & cancel: one request = one connection, so `tx` wraps exactly one
script (`Sql\ScriptRunner`): `atomic` = BEGIN … COMMIT, ROLLBACK on the first error (always stops
there); `dryrun` = always ROLLBACK (`rolled_back:true`). `Sql\TransactionPolicy` refuses, before
anything runs, transaction control in the script (BEGIN/COMMIT/SAVEPOINT/SET autocommit …),
statements that cannot run in a transaction (PG VACUUM/CREATE DATABASE/… CONCURRENTLY, SQLite
VACUUM, SQL Server CREATE/ALTER DATABASE/BACKUP …) and MySQL implicit-commit DDL in dry runs
(warning in atomic mode). `run_id` (client random, `[A-Za-z0-9_-]{8,64}`) registers
`{driver, backend id, conn, started}` in `Sql\RunRegistry` under sha256(session id | run id);
`sql.cancel` (not a WRITE route) finds it for the same session only, flags it and calls
`DriverInterface::cancelBackend()` on a second connection (MySQL `KILL QUERY`, PG
`pg_cancel_backend`, SQL Server `KILL` = whole session; SQLite: capability `cancel` false).
Downloads (export) stream a file instead of JSON (`action=export.download`, form POST with
`csrf` field because it is a navigation and not a fetch request).

| action | params | data |
|---|---|---|
| `auth.status` | none | `{authenticated, csrf, connections:[{id,driver,host,user,label,server,read_only,version,version_number,version_supported,min_version,version_flavor}], presets:[…+read_only], drivers:[{id,label,available}], version}` |
| `auth.login` | `driver,host,port,user,password,database?,label?,preset?,file?(sqlite),read_only?` | same as status + `conn` (+`twofa_enroll`), or + `twofa_required:true` (nothing stored yet → `auth.verify2fa`) |
| `auth.logout` | `conn?` (all if omitted) | status |
| `auth.verify2fa` | `code` or `recovery_code` (second login step; CSRF) | same as status + `conn` |
| `twofa.status` | `conn` | `{available,enabled,required,enroll_required,recovery_remaining,account}` |
| `twofa.enrollStart` | `conn` | `{secret,uri(otpauth://),account,issuer,expires_in}` (secret kept encrypted in the session 10 min) |
| `twofa.enrollConfirm` | `conn,code` | `{recovery_codes:[10]}` (shown once) |
| `twofa.disable` / `twofa.recoveryRegenerate` | `conn, code or recovery_code` | `true` / `{recovery_codes}` |
| `server.overview` | `conn` | `{info:serverInfo(), capabilities, databases:[...], read_only}` |
| `server.variables` | `conn, filter?` | `[{name,value}]` |
| `server.status` | `conn` | `[{name,value}]` |
| `server.processes` | `conn` | `{columns, rows}` |
| `server.kill` | `conn, id` | `true` |
| `server.users` | `conn` | `[{user,host,...}]` |
| `server.userGrants` | `conn,user,host` | `[sql…]` |
| `server.userSave` | `conn,user,host,password?,grants:[sql]` (create/alter) | `true` |
| `server.userDrop` | `conn,user,host` | `true` |
| `server.privileges` | `conn,user,host?,db?` | `PrivilegeState` (db: object-level privileges of that database, for PostgreSQL and SQL Server) |
| `server.privilegeObjects` | `conn,db` | `{db,schemas:[name],tables:[{schema,name,kind:'table'|'view'|'sequence',columns:[name]}]}` |
| `server.privilegesPlan` | `conn,user,host?,changes:PrivilegeChanges` (preview, nothing runs) | `{statements:[{sql,db}],warnings:[code],self_lockout:[code],self}` |
| `server.privilegesApply` | same + `confirm_self?,stop_on_error?` (WRITE; re-plans on the server) | `{results:[{sql,db,ok,error}],executed,failed,warnings}`; 409 `self_lockout` (details.reasons) unless `confirm_self` |
| `server.roleCreate` / `server.roleDrop` | `conn,name,host?(MySQL 8),db?(SQL Server),preview?` (WRITE) | `{statements,results,executed,failed}` |
| `server.collations`/`server.charsets`/`server.engines` | `conn` | lists |
| `db.list` | `conn` | `[{name,collation,tables,size}]` |
| `db.create` | `conn,name,collation?` | `true` |
| `db.drop` | `conn,name` | `true` |
| `db.info` | `conn,db` | `DatabaseInfo` + `login_database:bool` (the connection logs in to it → no rename/move) |
| `db.alter` | `conn,db,changes:DatabaseChanges,preview?` (WRITE, caps `db_alter`; `rename` also `db_rename`) | `{sql:[…],warnings:[code],executed}` |
| `db.copy` | `conn,db,target,drop_source?,preview?` (WRITE, caps `db_copy`; `drop_source` = MySQL rename/move, `db_rename`) | `{sql,warnings,executed,copied:{tables,views,routines,triggers,events},skipped:[{type,name,error}],dropped}` |
| `db.schemas` | `conn,db` | `[name]` (only if capability `schemas`) |
| `db.objects` | `conn,db,schema?` | `{tables:[TableSummary], views:[…], routines:[…], triggers:[…], events:[…]}` |
| `db.search` | `conn,db,schema?,term,tables?,mode(contains/exact/regex)` | `[{table,column_matches:{col:count},count}]` |
| `db.erd` | `conn,db,schema?` | `{tables:[{name,columns:[{name,type,primary,nullable}]}], relations:[{from_table,from_columns,to_table,to_columns,name,on_delete,on_update}]}` |
| `table.info` | `conn,db,schema?,table` | `TableInfo` (see interface) |
| `table.ddl` | `…table,type?(table/view/routine/trigger)` | `{sql}` |
| `table.browse` | `…table,page,per_page,order:[{column,dir}],filters:[{column,op,value}],search?` | `BrowseResult` |
| `table.insert` | `…table,values:{col:val}` | `{affected,insert_id}` |
| `table.update` | `…table,key:{pkcol:val},values:{col:val}` | `{affected}` |
| `table.delete` | `…table,keys:[{pkcol:val}]` | `{affected}` |
| `table.create` | `…,definition:TableDefinition,preview?` | `{sql:[…]}` (+executed if !preview) |
| `table.alter` | `…table,changes:AlterSpec,preview?` | `{sql:[…]}` |
| `table.drop`/`table.truncate`/`table.empty` | `…,tables:[names]` | `{affected}` |
| `table.rename` | `…table,new_name` | `true` |
| `table.copy` | `…table,new_name,target_db?,with_data` | `true` |
| `table.maintenance` | `…tables,op(optimize/analyze/check/repair/vacuum)` | `{columns,rows}` |
| `table.fkValues` | `…table,column,term?` | `[{value,label}]` (for FK dropdowns, max 100) |
| `routine.info` | `conn,db,schema?,name,type?(procedure/function),signature?(PG identity args)` | `RoutineDefinition+{sql}` (caps `routines`) |
| `routine.save` | `…,definition:RoutineDefinition,original?:{name,type,signature?},preview?` (WRITE) | `{sql:[…],warnings:[code],executed,script}` |
| `routine.drop` | `…,name,type?,signature?` (WRITE) | same |
| `routine.execute` | `…,name,type?,signature?,args:[{value,null?,default?}],limit?` (not WRITE: procedures refused on read-only connections, functions run via ReadOnlyGuard + read-only session) | `{results:[QueryResult(+more_results)],out:[{name,value}],sql}` |
| `trigger.info` / `trigger.save` / `trigger.drop` | `…,name,table?` / `…,definition:TriggerDefinition,original?:{name,table},preview?` | `TriggerDefinition+{sql}` / plan as above (caps `triggers`) |
| `event.info` / `event.save` / `event.drop` | `…,name` / `…,definition:EventDefinition,original?:{name},preview?` | `EventDefinition+{sql}` / plan (MySQL only, caps `events`) |
| `sql.execute` | `conn,db?,schema?,sql,limit?(default 1000),stop_on_error?,tx?(none/atomic/dryrun),run_id?` | `{results:[QueryResult+{error_code?:'cancelled'}],tx,rolled_back,cancelled,warnings:[string]}` |
| `sql.explain` | `conn,db?,sql,run_id?` | `QueryResult` (error code `cancelled` when stopped) |
| `sql.cancel` | `conn,run_id` | `{cancelled,found}`; stops the running `sql.execute` or `sql.explain` of this session with that `run_id` |
| `sql.format` | `sql` | `{sql}` |
| `sql.completions` | `conn,db,schema?` | `{tables:{name:[cols]}, functions:[…], keywords:[…]}` |
| `diff.compare` | `source:{conn,db,schema?},target:{conn,db,schema?},options:{include:{tables,views,routines,triggers,events},ignore:{comments,auto_increment,collation,column_order,definer},detect_renames}` (both connections of this session, same driver, else 400) | `{changes:[Change],summary,driver,options,source,target}` |
| `diff.plan` | same + `selected?:[change id]`, `exclude_destructive?` | `{statements:[{sql,phase,changes,tx_control,destructive}],sql,warnings:[{code,params,change}],included,excluded,hash}` |
| `diff.apply` | same + `conn` (= target.conn; WRITE), `hash` (from diff.plan), `tx` (atomic/none) | ScriptRunner result + `{failed,statements,plan_warnings}`; 409 `stale` when the recomputed plan differs |
| `server.dataTypes` | `conn` | grouped type list for the column editor |
| `server.metrics` | `conn` | monitor snapshot `{driver, ts(ms), uptime, mix_unit, counters:{…totals}, gauges:{…}, settings:{…}, long:[{id,user,db,time,state,query}]}` (capability `monitor`; rates are computed in the client from counter deltas, see `Monitor\MetricsNormalizer`) |
| `server.slowLog` | `conn, limit?(≤100), source?, order?(total\|avg\|count)` | `{source: slow_log\|digest\|pg_stat_statements\|query_stats\|none, sources, hint?, rows:[{text, sql?, db, user, count, total_ms, avg_ms, max_ms, rows_examined, rows_sent, no_index, last_seen}]}` |
| `server.advice` | `conn` | `{hints:[{id, severity, metric, value, params}], ts}`; the rule codes are translated by the UI (`monitor.advice.<id>.*`, `Monitor\Advisor`) |
| `export.formats` | `conn` | `{formats:[…], compression:[…]}` |
| `import.options` | `conn` | `{encodings:[{id,label}], upload_max:int(bytes, effective min of config/php.ini)}` |
| `import.upload` | multipart: `conn,db,schema?,file,format(sql/csv),table?,csv_options` | `{statements,errors:[],time}` |
| `export.download` | form POST: `conn,db,schema?,tables[],format,options(json)` | file stream |

### Shapes

```
TableSummary = {name,type:'table'|'view',rows:int|null,data_size:int|null,index_size:int|null,
                engine:?string,collation:?string,comment:?string,auto_increment:?int,updated:?string}
Column = {name,type,full_type,length:?string,unsigned:bool,nullable:bool,default:?string,
          default_kind:'none'|'null'|'value'|'expression',auto_increment:bool,primary:bool,
          comment:?string,collation:?string,extra:?string,generated:?string}
Index = {name,columns:[string],unique:bool,primary:bool,type:?string}
ForeignKey = {name,columns:[string],ref_schema:?string,ref_table,ref_columns:[string],
              on_delete:?string,on_update:?string}
Trigger = {name,timing,event,statement}
TableInfo = {name,type,columns:[Column],indexes:[Index],foreign_keys:[ForeignKey],
             referenced_by:[ForeignKey+{table}],triggers:[Trigger],options:{engine,collation,comment,rows,auto_increment}}
BrowseResult = {columns:[{name,type,nullable,primary,fk:?{table,column}}],rows:[[…]],
                total:int|null,primary_key:[string],editable:bool,sql:string,time_ms:float}
QueryResult = {sql,is_result_set:bool,columns:[{name,type?,table?}],rows:[[…]],
               affected:?int,insert_id:?string,time_ms:float,truncated:bool,error:?string,
               editable:?{table,primary_key:[string]},
               more_results?:[{is_result_set,columns,rows,affected:?int,truncated}]}  // further result sets of a batch/CALL
ColumnDefinition = {name,type,length:?string,unsigned:bool,nullable:bool,
                    default_kind:'none'|'null'|'value'|'expression',default:?string,
                    auto_increment:bool,comment:?string,collation:?string}
DatabaseInfo = {driver,name,settings:{…},size,…}  // per driver:
  mysql:  settings{charset,collation}, table_count, view_count, objects{routines,triggers,events},
          tables:[{name,type,engine,collation,rows,size,columns_differing,differs}], differing_tables, differing_columns, system
  pgsql:  settings{owner,encoding,lc_collate,lc_ctype,connection_limit,tablespace,comment,allow_connections,is_template},
          table_count (only the connected db), other_connections, roles:[name], current_user
  sqlite: file, writable, sqlite_version, settings{page_size,page_count,freelist_count,journal_mode,foreign_keys,
          auto_vacuum,user_version,application_id,encoding}, table_count, view_count
  sqlsrv: settings{collation,recovery_model,compatibility_level,state,user_access,read_only,created}, other_connections
DatabaseChanges (db.alter; unknown keys → bad_request):
  mysql:  {charset?,collation?,convert_tables?:bool,tables?:[name]}  validated against SHOW COLLATION/CHARACTER SET;
          ALTER DATABASE … CHARACTER SET … COLLATE … + ALTER TABLE … CONVERT TO … per base table (views skipped)
  pgsql:  {owner?,comment?(''→NULL),connection_limit?(-1…),rename?}  rename runs from the "postgres"/template1 db
  sqlite: {pragmas:{journal_mode:delete|truncate|persist|wal, foreign_keys:bool, auto_vacuum:none|full|incremental,
          user_version:int32}, vacuum?:bool}
  sqlsrv: {collation?,rename?}  from master; refused (in_use) while other sessions use the database
TableDefinition = {name,columns:[ColumnDefinition],primary_key:[string],
                   indexes:[{name,columns,unique}],foreign_keys:[ForeignKey],
                   options:{engine?,collation?,comment?}}
AlterSpec = {add:[ColumnDefinition+{after?:string|null,first?:bool}],
             modify:[{column:string,definition:ColumnDefinition}],   // rename via definition.name
             drop:[string],
             add_index:[{name,columns,unique,primary?}], drop_index:[string],
             add_fk:[ForeignKey], drop_fk:[string],
             options?:{engine?,collation?,comment?,auto_increment?}}
```

Privileges editor (`src/Privileges/`: `PrivilegeManager::for($driver)` reads state / runs plans; pure
`*PrivilegePlanner` classes generate SQL from whitelisted privilege names and quote every identifier
(MySQL accounts as `` `user`@`host` ``, PG `"…"`, SQL Server `[…]`); no-op changes are dropped against the
current state; statements that must run in a database carry `db`):
```
PrivilegeState = {driver,flavor,version,self,current_user,current_database?,
  account:{user,host,exists,is_role,plugin?,locked?,password_expired?,password_lifetime?,require_ssl?,limits?,
           attributes?(pg: superuser,createdb,createrole,login,replication,bypassrls,inherit,connection_limit,valid_until),
           type?,default_database?(sqlsrv)},
  global:[priv], databases:[{db,privileges,public?,is_owner?}], schemas:[{db,schema,privileges}],
  tables:[{db,schema?,table,kind?,privileges,denied?}], columns:[{db,schema?,table,column,privileges,denied?}],
  routines:[…](read only), default_privileges:[…](pg, read only), server_permissions:[…](sqlsrv, read only),
  roles:{granted:[{name,host,admin?,default?}],available:[{name,host,predefined?}],default:[…],members?:[name]},
  db_users:{db:name|null}, db_roles:{db:{granted,available}} (sqlsrv), grants:[sql] (SHOW GRANTS / reconstructed),
  catalog:{global|database|schema|table|sequence|column:[{name,group:'data'|'structure'|'admin'}]},
  descriptions:{priv:text}, features:{global,databases,schemas,tables,columns,roles,default_roles:'multi'|'single'|false,
  limits:[name],lock,password_expire,require_ssl,attributes:[name],deny,server_roles,db_roles,create_role,rename,clone,host,object_db}}
PrivilegeChanges (unknown keys / privileges → bad_request) = {
  global?:{add,remove}, databases?:[{db,add,remove,deny?}], schemas?:[{db,schema,add,remove,deny?}],
  tables?:[{db,schema?,table,kind?:'sequence',add,remove,deny?}], columns?:[{db,schema?,table,column,add,remove,deny?}],
  roles?:{add:[{name,host?}],remove,default?:[…]}, server_roles?/db_roles?:[{db,add,remove}] (sqlsrv), create_db_user?:{db} (sqlsrv),
  limits?:{max_queries_per_hour,max_updates_per_hour,max_connections_per_hour,max_user_connections,max_statement_time(MariaDB)},
  account?:{lock?,unlock?,password_expire?(true|'never'|'default'|days),require_ssl?('NONE'|'SSL'|'X509')},
  attributes?:{…pg role attributes}, rename?:{user,host?}, clone_from?:{user,host?,db?} (adds what the source has)}
```
Self-lockout guard (target = login account): revoking admin privileges (SUPER, CREATE USER, GRANT OPTION,
SYSTEM_USER…/sysadmin/securityadmin/db_owner), all global privileges, CONNECT/USAGE, removing own
SUPERUSER/CREATEROLE/LOGIN, locking, expiring, renaming or requiring TLS needs `confirm_self:true`.

Row cell values in JSON: strings / numbers / null. Binary (non-UTF-8) values are returned as
`{"$binary":"<base64 of first 256 bytes>","length":N}` and are not inline-editable.
Filter ops: `= != < > <= >= LIKE NOT LIKE IN NOT IN IS NULL IS NOT NULL BETWEEN contains starts ends`.

### Routines, triggers, events (`Routine\RoutineEditor` + one subclass per engine, `RoutineController`)

```
RoutineDefinition = {name, type:'PROCEDURE'|'FUNCTION', parameters:[{mode:IN|OUT|INOUT|VARIADIC, name, type, default?}],
  returns:?string, body, comment,
  mysql:  deterministic, data_access, security:DEFINER|INVOKER, definer:'user@host', sql_mode
  pgsql:  signature (pg_get_function_identity_arguments), language, volatility, strict, security, leakproof, parallel,
          cost, rows, config:['name=value'], atomic_body (BEGIN ATOMIC / RETURN body), definer (owner), returns 'SETOF x'/'TABLE(...)'
  sqlsrv: function_kind:scalar|inline|multi, execute_as, schemabinding, recompile}
TriggerDefinition = {name, table, timing:BEFORE|AFTER|INSTEAD OF, events:[INSERT|UPDATE|DELETE|TRUNCATE], body,
  pgsql: level:ROW|STATEMENT, columns (UPDATE OF), when, function, function_schema, arguments:[string] (no body)
  sqlite: columns, when;  mysql: definer, order:{kind:FOLLOWS|PRECEDES, trigger}}
EventDefinition (MySQL) = {name, schedule:{type:'at', at} | {type:'every', interval_value, interval_field, starts?, ends?},
  preserve, status:ENABLE|DISABLE|DISABLE ON SLAVE, comment, definer, body}
```
Bodies, defaults and WHEN conditions are user SQL passed verbatim (defaults / WHEN must be a single expression);
names are quoted, modes / types / characteristics validated. Replace strategy: MariaDB CREATE OR REPLACE;
MySQL DROP + CREATE (warnings `grants_lost`, `not_atomic`; the original DDL is captured first and re-run if CREATE
fails), events via ALTER EVENT (incl. RENAME TO); PostgreSQL CREATE OR REPLACE when parameters/return type are
unchanged (+ ALTER … RENAME TO), otherwise DROP + CREATE in one transaction (`grants_lost`); triggers CREATE OR
REPLACE TRIGGER on PG 14+ else DROP + CREATE in a transaction; SQL Server CREATE OR ALTER (2016 SP1+) / ALTER,
rename = DROP + CREATE in a transaction; SQLite DROP + CREATE in a transaction. Execution binds every value:
MySQL `CALL p(?, @ldb_pN)` + `SELECT @ldb_pN` for OUT/INOUT, PG `SELECT * FROM f(CAST(? AS t))` / `CALL`
(named notation when a default is used), SQL Server one batch `DECLARE … EXEC @rc = p … OUTPUT; SELECT …`.

## Frontend

- `public/index.php` renders a minimal shell (+ inline-free theme bootstrap via `assets/dist/theme.js` loaded in <head> to avoid FOUC); JS mounts everything into `#app`.
- Hash router: `#/login`, `#/c/{conn}` (server), `#/c/{conn}/db/{db}[@{schema}]` (database),
  `#/c/{conn}/db/{db}/t/{table}/{tab}` (tab = browse|structure|sql|search|insert|ddl|ops),
  `#/c/{conn}/db/{db}/routine|trigger|event[/{name}]` (routine editor; `?new=procedure|function`, `?type=`, `?sig=`, `?table=`),
  `#/c/{conn}/sql`, `#/c/{conn}/security` (2FA), `#/c/{conn}/db/{db}/erd`, `#/c/{conn}/db/{db}/settings` (database settings), `#/c/{conn}/server/{tab}`, `#/c/{conn}/server/monitor` (server monitor, own view), `#/c/{conn}/import`, `#/c/{conn}/export`.
- `api.js` exports `api(action, params)` → Promise(data), throws `ApiError{message,code,sql}`;
  handles CSRF header, redirect to login on `auth_required`.
- View module contract: `export default { title(ctx), async render(el, ctx) , destroy?() }`
  where `ctx = {conn, db, schema, table, tab, params, caps}`.
- `ui/` contains shared components: `dom.js` (`h()` hyperscript helper), `toast.js`,
  `modal.js` (confirm/prompt/form dialogs), `datagrid.js`, `sqleditor.js`, `tabs.js`,
  `icons.js` (inline SVG), `palette.js` (Ctrl/Cmd+K command palette), `menu.js` (context menus).
- Theming: light/dark/system via `data-theme` on `<html>` (Tailwind `dark:` variant); semantic colors as `@theme` CSS variables (bg, surface, border, fg, muted, accent, danger, success, warning). Visual style: clean, dense-but-airy, Linear/Supabase-studio-like; Inter/system font stack, JetBrains Mono/ui-monospace for data & SQL.
- Persisted per-browser in localStorage (wrapped in try/catch): theme, query history (last 200),
  saved queries (`sql.saved`, max 500, JSON export/import; migrated from the old `sql.snippets`), sidebar width, grid page size.
- Share links: browse `Copy link` (where/order/search/cols/page/per_page) and SQL `?q=<base64url, ≤8 KB>` (opens a new tab, never auto-runs) carry only a non-secret `srv` hint (preset id or driver); a link to a connection not open in this session goes to `#/login?next=…&srv=…` and continues on the new connection id after login (`share.js`, app.js guard, login.js).

## Internationalisation, RTL, mobile, weight (added requirements)

- UI locales: `en` (source), `de`, `es`, `fr`, `it`, `pt-BR`, `pl`, `tr`, `ru`, `ar` (RTL), `he` (RTL), `ur` (RTL), `hi`, `bn`, `id`, `vi`, `ja`, `ko`, `zh-CN`, `zh-TW`.
  Files: `resources/js/i18n/locales/<code>.js` → `export default { "key.path": "text {name}", … }`,
  loaded with dynamic `import()` (esbuild code-splitting) so only the active locale is downloaded.
  `resources/js/i18n/index.js` exports `t(key, params?)`, `plural(key, n)` (Intl.PluralRules,
  keys `key.one`/`key.other`/…), `fmtNumber`, `fmtBytes`, `fmtDate` (Intl), `setLocale`, `locale()`,
  `dir()`. Missing keys fall back to `en`. Locale = localStorage `ldb.locale` → navigator.languages → en.
  Do NOT hard-code user-visible strings in views or components; always use `t()`.
- RTL: set `<html lang dir>`; layout uses ONLY logical utilities (`ms-* me-* ps-* pe-* start-* end-*
  text-start text-end border-s border-e rounded-s rounded-e`), never left/right variants; directional
  icons (chevrons, arrows) flipped with `rtl:-scale-x-100`. SQL editor, code, identifiers and data
  cells are always `dir="ltr"`/`dir="auto"` (cells `dir="auto"` so Arabic/Hebrew data renders right).
- Fonts: system stacks only (no web fonts, lightweight): sans `ui-sans-serif, system-ui, -apple-system,
  "Segoe UI", Roboto, "Noto Sans", "Hiragino Sans", "Yu Gothic UI", "Microsoft YaHei", "PingFang SC",
  "Malgun Gothic", "Apple SD Gothic Neo", "Noto Sans CJK JP", sans-serif`; mono `ui-monospace,
  "JetBrains Mono", "Cascadia Code", Menlo, Consolas, "Noto Sans Mono CJK JP", monospace`. Use `:lang()`
  to pick CJK-first ordering for ja/zh/ko. CJK text must not be clipped (line-height ≥ 1.5 in body text).
- Data side: all connections UTF-8 (MySQL utf8mb4, PG client_encoding UTF8, SQL Server UTF-8); export
  filenames may be Unicode → `Content-Disposition: attachment; filename="ascii-fallback"; filename*=UTF-8''…`;
  CSV import offers encodings UTF-8, Shift_JIS/CP932, EUC-JP, GB18030, Big5, EUC-KR/CP949,
  Windows-1258, Windows-1251, KOI8-R, ISO-8859-x, UTF-16LE/BE; CSV export offers UTF-8 (+BOM).
  String truncation for previews must use `mb_` functions (never cut inside a multibyte char).
- Mobile: fully usable at 360px width. Sidebar becomes an off-canvas drawer (< lg), toolbar actions
  collapse into an overflow menu, data grid scrolls horizontally inside its container with sticky
  header + sticky first (key) column, row edit opens a full-screen sheet on small screens, touch
  targets ≥ 40px, no hover-only affordances. `viewport` meta with `viewport-fit=cover` + safe-area insets.
- Weight budget: built JS ≤ 200 KB min (≈ 60 KB gzip) excluding lazily-loaded locale/view chunks; CSS ≤ 60 KB.
  Lucide icons imported individually (tree-shaken), never the full set. Views lazy-loaded via `import()`.

## Schema diff (`src/Diff/`, `#/c/{conn}/db/{db}/diff`)

`SchemaReader` builds a `SchemaSnapshot` (tables with columns/indexes/FKs/CHECKs/options, view/routine/
trigger/event DDL) through the driver; `SchemaComparer` (pure) lists changes
`{id: kind:table:object | kind:key, kind, object_type, table, object, fields, before (target), after (source), destructive, warnings}`
with normalisation (MySQL int display width, DEFINER, own db/schema qualifiers, event STARTS, generated
constraint names matched by definition; column order only on MySQL/SQLite; renames only with
`detect_renames`). `MigrationPlanner` orders the SQL (drop FKs → triggers/routines/events/views →
checks → drop/create/alter tables via the target driver's `buildCreateTable`/`buildAlterTable` →
indexes/checks/FKs → views (dependency order) → routines/triggers/events); FKs touching changed
columns and PostgreSQL views depending on changed tables are dropped and re-created. `PlanExecutor`
runs it through `ScriptRunner` (SQLite rebuild BEGIN/COMMIT and the foreign_keys switch run outside
the atomic wrapper). The plan is always recomputed server-side; `diff.apply` compares its hash with
the reviewed one. SQL Server and cross-driver comparison are not supported.
