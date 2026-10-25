# Changelog

All notable changes to LDB are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-25

This is the initial public release (beta).

### Added
- **Engines:** MySQL / MariaDB / Percona, PostgreSQL, SQLite and Microsoft SQL Server (`pdo_sqlsrv` or `pdo_dblib`); features that a driver cannot support are hidden.
- **Supported database versions:** every release of 2018 or later (MySQL 8.0.11, MariaDB 10.3, PostgreSQL 11, SQL Server 2019, SQLite 3.22); features are switched on per server version, and older servers can still connect with a warning.
- **Browse & edit:** data grid with sorting, filter builder, search, pagination, foreign-key dropdowns and links; batched edits (cell edits, inserts, deletes) reviewed as SQL and applied in one transaction; "Copy link" to the current view.
- **SQL editor:** syntax highlighting, dialect-aware autocomplete and formatter, multiple statements and result sets, `EXPLAIN`, history, saved queries (JSON export/import), share links, **Stop** for running queries, *Atomic* and *Dry run* transaction modes, risky-SQL confirmation; procedure and trigger bodies with `BEGIN … END` do not need `DELIMITER`.
- **Structure:** create/alter tables with SQL preview, table operations, DDL view; editor for routines, events and triggers (parameters, characteristics, execute with OUT values).
- **Databases:** settings page (charset/collation with optional table conversion, copy, rename, PostgreSQL owner/comment/limit, SQLite pragmas, SQL Server collation, drop); editable ER diagram with routed relation lines; whole-database and per-table search; schema diff with reviewed, applicable migration scripts.
- **Import/export:** streamed import (SQL, CSV in many encodings; plain, gzip, bzip2, zip) and export (SQL, CSV, JSON, XML, Markdown, Excel XML; none, gzip, zip); failed exports end with an explicit marker.
- **Server:** variables, status, processes, live monitor with charts, top/slow queries and tuning hints; users with a privileges editor (global/database/table/column, roles, limits) for MySQL/MariaDB, PostgreSQL and SQL Server.
- **Security:** credentials encrypted in the session (AES-256-GCM), CSRF protection, `HttpOnly; SameSite=Strict` cookies, strict CSP, login rate limiting, preset-only servers by default, read-only connections (enforced server-side), two-factor authentication (TOTP + recovery codes), single sign-on links for control panels. See [SECURITY.md](SECURITY.md).
- **UX:** command palette, keyboard shortcuts, light/dark/system theme, 1600px or full-width layout, mobile-friendly layout, accessibility (WCAG 2.2 AA target, 0 axe violations on core screens), several simultaneous connections, no CDN (works offline).
- **Languages:** 20 languages: English, Deutsch, Español, Français, Italiano, Português (Brasil), Polski, Türkçe, Русский, العربية, עברית, اردو, हिन्दी, বাংলা, Bahasa Indonesia, Tiếng Việt, 日本語, 한국어, 简体中文 and 繁體中文, with right-to-left layout for Arabic, Hebrew and Urdu.
- **Project:** plain PHP 7.4+ backend without runtime Composer dependencies; prebuilt frontend in `public/assets/dist/`; Docker dev stack; release packaging (`bin/package`); CI on PHP 7.4 to 8.4 with integration tests against MariaDB, MySQL, PostgreSQL, SQLite and SQL Server.
