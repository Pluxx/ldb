/**
 * Risky-SQL guard: client-side analysis of a script before it runs (UPDATE/DELETE without a
 * top-level WHERE, DROP, TRUNCATE, ALTER TABLE … DROP) plus the confirmation dialog.
 * `analyzeRisk` is pure (no DOM, no i18n) so it can be tested with plain Node.
 * @module ui/sqlguard
 */
import { splitStatements } from '../sql/split.js';

const WORD_RE = /[\p{L}\p{N}_$\u0080-￿]/u;
const isWord = (c) => c !== undefined && WORD_RE.test(c);

/**
 * @typedef {{type: 'word'|'quoted'|'string'|'punct', text: string, up: string, depth: number, start: number, end: number}} Tok
 */

/**
 * Tiny SQL tokenizer: words, quoted identifiers (` " []), strings, punctuation.
 * Comments are dropped. `depth` is the parenthesis nesting level at the token.
 * @param {string} sql
 * @param {string} [dialect='mysql']  'mysql' | 'pgsql' | …
 * @returns {Tok[]}
 */
export function tokenizeSql(sql, dialect = 'mysql') {
  const mysql = dialect === 'mysql' || dialect === 'mariadb';
  const out = [];
  const n = sql.length;
  let i = 0;
  let depth = 0;
  const push = (type, text, start, end, d = depth) => out.push({ type, text, up: type === 'word' ? text.toUpperCase() : text, depth: d, start, end });
  while (i < n) {
    const c = sql[i];
    const c2 = sql[i + 1];
    if (/\s/.test(c)) { i++; continue; }
    if ((c === '-' && c2 === '-') || (c === '#' && mysql)) {
      const j = sql.indexOf('\n', i);
      i = j < 0 ? n : j + 1;
    } else if (c === '/' && c2 === '*') {
      const j = sql.indexOf('*/', i + 2);
      i = j < 0 ? n : j + 2;
    } else if (c === "'" || c === '"' || c === '`' || c === '[') {
      const close = c === '[' ? ']' : c;
      const backslash = (c === "'" || c === '"') && (mysql || /[eE]/.test(sql[i - 1] || '') && c === "'");
      const start = i++;
      let name = '';
      while (i < n) {
        if (backslash && sql[i] === '\\') { name += sql[i + 1] ?? ''; i += 2; continue; }
        if (sql[i] === close) {
          if (sql[i + 1] === close && c !== '[') { name += close; i += 2; continue; }
          break;
        }
        name += sql[i++];
      }
      i++;
      // in MySQL "…" is a string; elsewhere it is an identifier
      push(c === "'" || (c === '"' && mysql) ? 'string' : 'quoted', name, start, Math.min(i, n));
    } else if (c === '$' && !isWord(sql[i - 1])) {
      const m = /^\$([\p{L}_][\p{L}\p{N}_]*)?\$/u.exec(sql.slice(i, i + 80));
      if (m) {
        const j = sql.indexOf(m[0], i + m[0].length);
        const end = j < 0 ? n : j + m[0].length;
        push('string', '', i, end);
        i = end;
      } else { push('punct', c, i, ++i); }
    } else if (isWord(c)) {
      const start = i;
      while (i < n && isWord(sql[i])) i++;
      push('word', sql.slice(start, i), start, i);
    } else if (c === '(') {
      push('punct', c, i, i + 1);
      depth++;
      i++;
    } else if (c === ')') {
      depth = Math.max(0, depth - 1);
      push('punct', c, i, i + 1);
      i++;
    } else {
      push('punct', c, i, i + 1);
      i++;
    }
  }
  return out;
}

/** Split into statements with the dialect-aware splitter (DELIMITER, $$, GO, routine bodies). */
function statementsOf(sql, dialect) {
  return splitStatements(sql, { dialect }).map((s) => s.text);
}

const name = (tk) => (tk && (tk.type === 'word' || tk.type === 'quoted') ? tk.text : undefined);
const isKw = (tk, kw) => tk?.type === 'word' && tk.up === kw;

/**
 * @typedef {object} Risk
 * @property {string} statement  trimmed statement text
 * @property {string} reason     i18n key (`sqlGuard.reason.<kind>`)
 * @property {'delete-no-where'|'update-no-where'|'drop'|'truncate'|'alter-drop'} kind
 * @property {string} [target]   table name, or database name for DROP DATABASE/SCHEMA
 * @property {boolean} [database] true for DROP DATABASE/SCHEMA
 */

/**
 * Find risky statements in a script.
 * @param {string} sql
 * @param {string} [dialect='mysql']
 * @returns {Risk[]}
 */
export function analyzeRisk(sql, dialect = 'mysql') {
  const risks = [];
  for (const text of statementsOf(sql || '', dialect)) {
    const toks = tokenizeSql(text, dialect);
    if (!toks.length) continue;
    let i = 0;
    // WITH … (CTEs live in parentheses): the main verb is the first depth-0 DML keyword after them
    if (isKw(toks[0], 'WITH')) {
      i = toks.findIndex((tk, k) => k > 0 && tk.depth === 0 && tk.type === 'word' && ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'MERGE'].includes(tk.up));
      if (i < 0) continue;
    }
    const verb = toks[i];
    if (verb.type !== 'word') continue;
    const rest = toks.slice(i + 1);
    const statement = text.trim();
    const hasWhere = rest.some((tk) => tk.depth === 0 && isKw(tk, 'WHERE'));
    const add = (kind, extra = {}) => risks.push({ statement, reason: `sqlGuard.reason.${kind}`, kind, ...extra });

    if (verb.up === 'DELETE') {
      if (hasWhere) continue;
      // DELETE FROM t … | DELETE t1 FROM t1 JOIN … | PG: DELETE FROM ONLY t
      const from = rest.findIndex((tk) => tk.depth === 0 && isKw(tk, 'FROM'));
      let target = name(rest[from + 1]);
      if (target && target.toUpperCase() === 'ONLY' && rest[from + 1].type === 'word') target = name(rest[from + 2]);
      add('delete-no-where', { target });
    } else if (verb.up === 'UPDATE') {
      if (hasWhere) continue;
      let k = 0;
      while (isKw(rest[k], 'LOW_PRIORITY') || isKw(rest[k], 'IGNORE') || isKw(rest[k], 'ONLY')) k++;
      add('update-no-where', { target: name(rest[k]) });
    } else if (verb.up === 'TRUNCATE') {
      let k = 0;
      if (isKw(rest[k], 'TABLE')) k++;
      add('truncate', { target: name(rest[k]) });
    } else if (verb.up === 'DROP') {
      const objType = rest[0]?.up;
      const database = objType === 'DATABASE' || objType === 'SCHEMA';
      let k = 1;
      if (isKw(rest[k], 'IF')) k += 2; // IF EXISTS
      add('drop', { target: name(rest[k]), ...(database ? { database: true } : {}) });
    } else if (verb.up === 'ALTER' && isKw(rest[0], 'TABLE') && rest.some((tk) => tk.depth === 0 && isKw(tk, 'DROP'))) {
      let k = 1;
      if (isKw(rest[k], 'IF')) k += 2;
      if (isKw(rest[k], 'ONLY')) k++;
      add('alter-drop', { target: name(rest[k]) });
    }
  }
  return risks;
}

/**
 * Risks that still need a confirmation in the editor's transaction mode. A dry run always
 * rolls back, so nothing persists: no dialog. (MySQL/MariaDB DDL would commit implicitly, but
 * the server refuses it in dry-run mode before anything runs.)
 * @param {Risk[]} risks
 * @param {'none'|'atomic'|'dryrun'} [tx='none']
 * @returns {Risk[]}
 */
export function risksForTx(risks, tx = 'none') {
  return tx === 'dryrun' ? [] : risks;
}

const MAX_SHOWN = 200;

/**
 * Show one danger confirmation listing every risky statement.
 * DOM/i18n modules are imported lazily so `analyzeRisk` stays loadable in plain Node.
 * @param {Risk[]} risks
 * @returns {Promise<boolean>}  true → run anyway
 */
export async function confirmRisks(risks) {
  const [{ confirmDialog }, { h }, { t, plural }, { highlightSql }] = await Promise.all([
    import('./modal.js'), import('./dom.js'), import('../i18n/index.js'), import('../sql/highlight.js'),
  ]);
  const items = risks.map((r) => {
    const sql = r.statement.length > MAX_SHOWN ? `${r.statement.slice(0, MAX_SHOWN)}…` : r.statement;
    return h('li', { class: 'rounded-lg border border-border bg-surface-2 p-2.5' },
      h('div', { class: 'mb-1 text-xs font-medium text-danger', dir: 'auto' }, t(r.reason, { target: r.target || '' })),
      h('pre', { class: 'whitespace-pre-wrap break-all font-mono text-xs', dir: 'ltr' }, highlightSql(sql)));
  });
  const message = h('div', {},
    h('p', { class: 'mb-3 text-[13.5px] leading-relaxed' }, plural('sqlGuard.intro', risks.length)),
    h('ul', { class: 'max-h-72 space-y-2 overflow-auto' }, items));
  const dbs = risks.filter((r) => r.database && r.target);
  return confirmDialog({
    title: t('sqlGuard.title'),
    message,
    confirmLabel: t('sqlGuard.runAnyway'),
    danger: true,
    requireText: dbs.length ? dbs[0].target : undefined,
  });
}
