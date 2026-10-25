/**
 * Client-side statement splitting (used to find the statement under the cursor and by the
 * risky-SQL guard). Mirrors src/Sql/SqlSplitter.php: quotes, quoted identifiers, comments,
 * PostgreSQL dollar quotes, the MySQL DELIMITER command, SQL Server GO lines and compound
 * statements — a ";" inside the body of a routine definition (CREATE PROCEDURE … BEGIN …; …; END,
 * triggers, events, PostgreSQL BEGIN ATOMIC, SQL Server BEGIN … END / TRY / CATCH blocks) does not
 * end the statement. The server does the authoritative split.
 * @module sql/split
 */

const IDENT = /[A-Za-z0-9_$\u0080-￿]/;
const SPACE = /\s/;

/** Per-dialect keyword tables ('' = unknown dialect: union of all). */
const KINDS = {
  mysql: ['PROCEDURE', 'FUNCTION', 'TRIGGER', 'EVENT'],
  pgsql: ['FUNCTION', 'PROCEDURE'],
  sqlite: ['TRIGGER'],
  sqlsrv: ['PROC', 'PROCEDURE', 'FUNCTION', 'TRIGGER'],
  '': ['PROC', 'PROCEDURE', 'FUNCTION', 'TRIGGER', 'EVENT'],
};
const HEAD_SKIP = {
  mysql: ['OR', 'REPLACE', 'AGGREGATE'],
  pgsql: ['OR', 'REPLACE'],
  sqlite: ['TEMP', 'TEMPORARY'],
  sqlsrv: ['OR', 'ALTER'],
  '': ['OR', 'REPLACE', 'ALTER', 'AGGREGATE', 'TEMP', 'TEMPORARY'],
};
const BEGIN_TX = {
  mysql: ['WORK'],
  pgsql: [],
  sqlite: ['TRANSACTION', 'DEFERRED', 'IMMEDIATE', 'EXCLUSIVE'],
  sqlsrv: ['TRAN', 'TRANSACTION', 'DISTRIBUTED', 'DIALOG', 'CONVERSATION'],
  '': ['WORK', 'TRAN', 'TRANSACTION', 'DISTRIBUTED', 'DIALOG', 'CONVERSATION', 'DEFERRED', 'IMMEDIATE', 'EXCLUSIVE'],
};
const END_KINDS = {
  mysql: ['IF', 'LOOP', 'WHILE', 'REPEAT', 'FOR', 'CASE'],
  pgsql: [],
  sqlite: [],
  sqlsrv: ['TRY', 'CATCH'],
  '': ['IF', 'LOOP', 'WHILE', 'REPEAT', 'FOR', 'CASE', 'TRY', 'CATCH'],
};
const FLOW = ['IF', 'LOOP', 'WHILE', 'REPEAT', 'FOR'];
const STATEMENT_POSITION = [';', 'BEGIN', 'THEN', 'ELSE', 'DO', 'LOOP', 'REPEAT', ':', 'ROW'];

const H_FIRST = 0, H_CREATE = 1, H_DEFINER = 2, H_SECURITY = 3, H_BEGIN = 4, H_NONE = 7;

/** @param {string|undefined} d */
function normDialect(d) {
  d = String(d || '').toLowerCase();
  if (d === 'mariadb' || d === 'mysql') return 'mysql';
  if (d === 'pgsql' || d === 'postgres' || d === 'postgresql') return 'pgsql';
  if (d === 'sqlite' || d === 'sqlsrv') return d;
  if (d === 'mssql') return 'sqlsrv';
  return '';
}

/**
 * Block tracker for one statement (port of SqlSplitter::token()).
 * Words are upper-cased; punctuation is passed as the character, quoted tokens as 'Q'.
 */
class Blocks {
  constructor(d) {
    this.d = d;
    this.mysqlish = d === 'mysql' || d === '';
    this.reset();
  }

  reset() {
    this.head = H_FIRST;
    this.routine = false;
    this.stack = [];
    this.pending = '';
    this.last = '';
  }

  get depth() { return this.stack.length; }

  headStep(word, t) {
    const d = this.d;
    switch (this.head) {
      case H_FIRST:
        if (word && (t === 'CREATE' || (t === 'ALTER' && (d === 'sqlsrv' || d === '')))) return H_CREATE;
        return word && t === 'BEGIN' && this.mysqlish ? H_BEGIN : H_NONE;
      case H_BEGIN:
        return word && t === 'NOT' ? 'block' : H_NONE;
      case H_CREATE:
        if (!word) return H_NONE;
        if (KINDS[d].includes(t)) return 'routine';
        if (this.mysqlish && t === 'DEFINER') return H_DEFINER;
        if (this.mysqlish && t === 'SQL') return H_SECURITY;
        return HEAD_SKIP[d].includes(t) ? H_CREATE : H_NONE;
      case H_DEFINER:
        if (word && KINDS[d].includes(t)) return 'routine';
        if (word && t === 'SQL') return H_SECURITY;
        return word && t === 'AGGREGATE' ? H_CREATE : H_DEFINER;
      case H_SECURITY:
        if (word && t === 'SECURITY') return H_SECURITY;
        return word && (t === 'DEFINER' || t === 'INVOKER') ? H_CREATE : H_NONE;
      default:
        return H_NONE;
    }
  }

  token(word, t) {
    const d = this.d;
    if (this.pending) {
      const p = this.pending;
      this.pending = '';
      if (p === 'END') {
        if (word && END_KINDS[d].includes(t)) { this.closeNamed(t); this.last = t; return; }
        if (word && t === 'CONVERSATION' && (d === 'sqlsrv' || d === '')) { this.last = t; return; }
        this.closeBare();
      } else {
        const isBlock = d === 'pgsql' ? word && t === 'ATOMIC' : !(word ? BEGIN_TX[d].includes(t) : t === ';');
        if (isBlock) {
          this.stack.push('BEGIN');
          if (word && (t === 'TRY' || t === 'CATCH' || t === 'ATOMIC')) return;
        } else if (word) { this.last = t; return; }
      }
    }
    if (this.head !== H_NONE) {
      const h = this.headStep(word, t);
      if (h === 'routine' || h === 'block') {
        this.routine = true;
        if (h === 'block') this.stack.push('BEGIN');
        this.head = H_NONE;
        this.last = t;
        return;
      }
      this.head = h;
      if (h !== H_NONE || d !== 'sqlsrv') { this.last = t; return; }
    }
    if (!this.routine && d !== 'sqlsrv' && !this.stack.length) { this.last = t; return; }
    const prev = this.last;
    this.last = t;
    if (!word || prev === '.' || prev === '@') return;
    if (t === 'BEGIN' || t === 'END') this.pending = t;
    else if (t === 'CASE') { if (this.routine || this.stack.length) this.stack.push('CASE'); }
    else if (this.mysqlish && this.routine && FLOW.includes(t) && STATEMENT_POSITION.includes(prev)) this.stack.push(t);
  }

  closeNamed(kind) {
    if (kind === 'TRY' || kind === 'CATCH') kind = 'BEGIN';
    for (let i = this.stack.length - 1; i >= 0; i--) {
      if (this.stack[i] === kind) { this.stack.length = i; return; }
      if (this.stack[i] === 'BEGIN') return;
    }
  }

  closeBare() {
    for (let i = this.stack.length - 1; i >= 0; i--) {
      if (this.stack[i] === 'BEGIN' || this.stack[i] === 'CASE') { this.stack.length = i; return; }
    }
  }
}

/**
 * Split SQL into statements.
 * @param {string} sql
 * @param {string|{dialect?: string}} [opts]  dialect: mysql | mariadb | pgsql | sqlite | sqlsrv;
 *        omitted = unknown (tolerant union of the rules)
 * @returns {{start: number, end: number, text: string}[]}  end is exclusive, excludes the delimiter.
 *          SQL Server scripts with GO lines: one entry per batch.
 */
export function splitStatements(sql, opts = {}) {
  const d = normDialect(typeof opts === 'string' ? opts : opts?.dialect);
  const mysqlish = d === 'mysql' || d === '';
  const dollar = d === 'pgsql' || d === '';
  const brackets = d === 'sqlsrv' || d === 'sqlite';
  const backtick = mysqlish || d === 'sqlite';
  const goLines = d === 'sqlsrv' || d === '';
  const out = [];
  const batches = [];
  const n = sql.length;
  const blk = new Blocks(d);
  let start = 0;
  let batchStart = 0;
  let sawGo = false;
  let content = false;
  let delim = ';';
  let i = 0;
  const push = (list, s, e) => { if (sql.slice(s, e).trim() !== '') list.push({ start: s, end: e, text: sql.slice(s, e) }); };
  const track = () => delim === ';';
  while (i < n) {
    const c = sql[i];
    const c2 = sql[i + 1];
    // GO line (SQL Server batch separator)
    if (goLines && (i === 0 || sql[i - 1] === '\n')) {
      const m = /^[ \t]*GO(?:[ \t]+\d+)?[ \t]*(?:\r?\n|$)/i.exec(sql.slice(i, i + 64));
      if (m) {
        push(out, start, i);
        push(batches, batchStart, i);
        sawGo = true;
        i += m[0].length;
        start = batchStart = i;
        content = false;
        blk.reset();
        continue;
      }
    }
    if (!content && SPACE.test(c)) { i++; continue; }
    // MySQL client DELIMITER command at statement start
    if (!content && mysqlish && (c === 'd' || c === 'D')) {
      const j = sql.indexOf('\n', i);
      const m = /^DELIMITER[ \t]+(\S+)/i.exec(sql.slice(i, j < 0 ? n : j));
      if (m) {
        delim = m[1];
        i = j < 0 ? n : j + 1;
        start = i;
        blk.reset();
        continue;
      }
    }
    // comments
    if (c === '-' && c2 === '-' && (d !== 'mysql' || i + 2 >= n || /\s/.test(sql[i + 2]))) {
      const j = sql.indexOf('\n', i);
      i = j < 0 ? n : j; // the newline is handled normally (GO detection)
      continue;
    }
    if (c === '#' && mysqlish) {
      const j = sql.indexOf('\n', i);
      i = j < 0 ? n : j;
      continue;
    }
    if (c === '/' && c2 === '*') {
      let depth = 1;
      i += 2;
      while (i < n && depth > 0) {
        if (sql[i] === '*' && sql[i + 1] === '/') { depth--; i += 2; }
        else if (d === 'pgsql' && sql[i] === '/' && sql[i + 1] === '*') { depth++; i += 2; }
        else i++;
      }
      continue;
    }
    // delimiter
    if (sql.startsWith(delim, i)) {
      if (delim === ';' && track()) {
        blk.token(false, ';');
        if (blk.depth > 0) { i++; continue; }
      }
      push(out, start, i);
      i += delim.length;
      start = i;
      content = false;
      blk.reset();
      continue;
    }
    content = true;
    // quotes
    if (c === "'" || c === '"' || (c === '`' && backtick) || (c === '[' && brackets)) {
      const close = c === '[' ? ']' : c;
      const bs = (mysqlish && c !== '`') || (d === 'pgsql' && c === "'" && /[eE]/.test(sql[i - 1] || '') && !IDENT.test(sql[i - 2] || ''));
      i++;
      while (i < n) {
        if (bs && sql[i] === '\\') { i += 2; continue; }
        if (sql[i] === close) { if (sql[i + 1] === close) { i += 2; continue; } break; }
        i++;
      }
      i++;
      if (track()) blk.token(false, 'Q');
      continue;
    }
    if (c === '$' && dollar && !IDENT.test(sql[i - 1] || '')) {
      const m = /^\$([A-Za-z_\u0080-￿][A-Za-z0-9_\u0080-￿]*)?\$/.exec(sql.slice(i, i + 80));
      if (m) {
        const j = sql.indexOf(m[0], i + m[0].length);
        i = j < 0 ? n : j + m[0].length;
        if (track()) blk.token(false, 'Q');
        continue;
      }
    }
    if (SPACE.test(c)) { i++; continue; }
    if (track() && IDENT.test(c)) { // (a custom DELIMITER may consist of word characters such as "$$")
      let j = i + 1;
      while (j < n && IDENT.test(sql[j])) j++;
      blk.token(true, sql.slice(i, j).toUpperCase());
      i = j;
      continue;
    }
    if (track()) blk.token(false, c);
    i++;
  }
  push(out, start, n);
  if (d === 'sqlsrv' && sawGo) {
    push(batches, batchStart, n);
    return batches;
  }
  return out;
}

/**
 * The statement containing (or immediately preceding) a cursor offset.
 * @param {string} sql
 * @param {number} pos  caret offset
 * @param {string|{dialect?: string}} [opts]  see splitStatements
 * @returns {{start: number, end: number, text: string}|null}
 */
export function statementAt(sql, pos, opts) {
  const all = splitStatements(sql, opts);
  if (!all.length) return null;
  let best = all[0];
  for (const s of all) {
    if (pos >= s.start && pos <= s.end + 1) return s; // +1: caret right after ";"
    if (s.start <= pos) best = s;
  }
  return best;
}
