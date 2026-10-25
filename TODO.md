# TODO

This file lists the roadmap and the known limitations. Contributions are welcome. For larger changes, please open an issue first.

## Before 1.0

- [ ] External security review (login, two-factor, single sign-on, read-only guard, import/export)
- [ ] Load tests: multi-GB dumps, tables with 100M+ rows, slow connections; keyset paging for very large tables
- [ ] Native-speaker review of all translations (especially plural forms in ar, he, ru, pl and the right-to-left languages)
- [ ] Run the full SQL Server test matrix in CI and a UI pass against a live SQL Server for every feature
- [ ] WebAuthn / passkeys as a second factor
- [ ] Reduce the CSS bundle (currently about 70 KB raw / 13 KB gzip; the budget is 60 KB raw)
- [ ] Support trusted reverse proxies for the client IP (rate limiting currently uses REMOTE_ADDR only, so all users behind a proxy share one bucket)

## Planned features

- [ ] Data diff: compare a table between two connections and generate sync SQL
- [ ] Visual EXPLAIN (plan tree with hot spots) and index suggestions
- [ ] Partitions: view, add, drop, reorganize, truncate
- [ ] Import ODS/XLSX, JSON and XML
- [ ] Column statistics in browse (distinct values, NULL share, min/max)
- [ ] Optional server-side storage for saved queries, history and preferences
- [ ] Optional audit log (who changed what and when)
- [ ] Stop button in the browse view
- [ ] Export of every result set from the SQL editor (currently the first one)

## Known limitations

- **Exports:** an export that fails after the streaming has started ends with an explicit failure marker. The file is then incomplete.
- **MySQL/MariaDB:**
  - `max_execution_time` (query timeout) applies to SELECT statements only.
  - DDL commits implicitly, so *Atomic* mode cannot roll it back and *Dry run* refuses it.
  - Copying or renaming a database does not move the user privileges (GRANTs).
  - Very large conversions or copies can reach the configured `query_timeout`.
- **PostgreSQL:**
  - A database's collation cannot be changed after creation (shown for information only).
  - The "read-only" privilege preset applies to the connection's current database.
  - Top queries in the monitor need the `pg_stat_statements` extension.
- **SQLite:**
  - A table rebuild does not rewrite renamed columns inside CHECK constraints or trigger bodies (the change fails and is rolled back).
  - Logins require `sqlite_password_hash`.
  - Running queries cannot be stopped.
- **SQL Server:**
  - Stop ends the whole session (`KILL`) and not only the query.
  - Changing `IDENTITY` on an existing column is not supported.
  - Copying a table keeps defaults and the primary key, but not other indexes, foreign keys or triggers.
- **Tables:** "Move table" is a copy followed by a drop. Incoming foreign keys and triggers are not carried over (the UI states this).
- **Schema diff:** works between connections of the same engine only; comparisons between MariaDB and MySQL report views and triggers as changed because the servers format the definitions differently.
- **Two-factor authentication:** protects the access through LDB only. The same database credentials still work in other clients. Host aliases (`localhost` and `127.0.0.1`) count as different servers.
- **ER diagram:** in dense diagrams, relation lines can still cross tables.
