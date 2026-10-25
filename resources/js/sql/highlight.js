/**
 * SQL tokenizer and DOM highlighter (shared by the SQL editor, DDL view, dialogs).
 * Produces spans with `tok-*` classes; text is inserted as text nodes (escaped by construction).
 * @module sql/highlight
 */

/** Common SQL keywords across MySQL, PostgreSQL, SQLite and SQL Server (upper-case). */
export const KEYWORDS = new Set(`ADD ALL ALTER ANALYZE AND ANY AS ASC AUTO_INCREMENT BEGIN BETWEEN BIGINT BINARY BLOB BOOLEAN BOTH BY
CALL CASCADE CASE CAST CHANGE CHAR CHARACTER CHARSET CHECK COLLATE COLUMN COLUMNS COMMENT COMMIT CONSTRAINT CREATE CROSS CURRENT_DATE
CURRENT_TIME CURRENT_TIMESTAMP DATABASE DATABASES DATE DATETIME DECIMAL DECLARE DEFAULT DELETE DELIMITER DESC DESCRIBE DISTINCT DO DOUBLE DROP
EACH ELSE ELSEIF END ENGINE ENUM ESCAPE EVENT EXCEPT EXISTS EXPLAIN FALSE FETCH FIELDS FIRST FLOAT FOR FOREIGN FROM FULL FULLTEXT FUNCTION
GRANT GROUP HAVING IF IGNORE ILIKE IN INDEX INNER INOUT INSERT INT INTEGER INTERSECT INTERVAL INTO IS JOIN JSON KEY KEYS KILL LAST
LATERAL LEADING LEFT LIKE LIMIT LOCK LONGTEXT MATCH MEDIUMINT MEDIUMTEXT MODIFY NATURAL NOT NULL NULLS NUMERIC OFFSET ON OPTIMIZE
OR ORDER OUT OUTER OVER PARTITION PRIMARY PROCEDURE RECURSIVE REFERENCES REGEXP RENAME REPAIR REPLACE RESTRICT RETURN RETURNING RETURNS
REVOKE RIGHT RLIKE ROLLBACK ROW ROWS SCHEMA SELECT SEQUENCE SERIAL SET SHOW SMALLINT START STATUS TABLE TABLES TEMPORARY TEXT THEN
TIME TIMESTAMP TINYINT TINYTEXT TO TOP TRAILING TRANSACTION TRIGGER TRUE TRUNCATE UNION UNIQUE UNLOCK UNSIGNED UPDATE USE USING
UUID VACUUM VALUES VARCHAR VARIABLES VIEW WHEN WHERE WHILE WINDOW WITH ZEROFILL`.split(/\s+/));

const WORD_RE = /[A-Za-z_\u0080-\uffff][\w$\u0080-\uffff]*/y;

/**
 * Tokenize SQL. Token types: ws, cmt, str, ident (quoted identifier), num, var
 * (@user / :named / ? / $1), kw, fn (word followed by "("), word, op, punct.
 * @param {string} sql
 * @returns {{type: string, text: string}[]}
 */
export function tokenize(sql) {
  const out = [];
  const n = sql.length;
  let i = 0;
  const push = (type, end) => { out.push({ type, text: sql.slice(i, end) }); i = end; };
  while (i < n) {
    const c = sql[i];
    const c2 = sql[i + 1];
    if (c === ' ' || c === '\t' || c === '\n' || c === '\r') {
      let j = i + 1;
      while (j < n && /\s/.test(sql[j])) j++;
      push('ws', j);
    } else if ((c === '-' && c2 === '-') || c === '#') {
      const j = sql.indexOf('\n', i);
      push('cmt', j < 0 ? n : j);
    } else if (c === '/' && c2 === '*') {
      const j = sql.indexOf('*/', i + 2);
      push('cmt', j < 0 ? n : j + 2);
    } else if (c === "'" || c === '"' || c === '`' || (c === '[' && /[A-Za-z_\u0080-\uffff]/.test(c2 || '') && sql.indexOf(']', i) > i)) {
      const close = c === '[' ? ']' : c;
      let j = i + 1;
      while (j < n) {
        if (sql[j] === '\\' && c !== '`' && c !== '[') { j += 2; continue; }
        if (sql[j] === close) {
          if (sql[j + 1] === close && c !== '[') { j += 2; continue; } // doubled quote escape
          j++;
          break;
        }
        j++;
      }
      push(c === "'" ? 'str' : c === '"' ? 'str' : 'ident', Math.min(j, n));
    } else if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(c2 || ''))) {
      const m = /0x[0-9a-f]+|\d*\.?\d+(?:e[+-]?\d+)?/iy;
      m.lastIndex = i;
      const r = m.exec(sql);
      push('num', r ? i + r[0].length : i + 1);
    } else if (c === '@' || (c === ':' && /[A-Za-z_]/.test(c2 || '')) || (c === '$' && /[0-9]/.test(c2 || ''))) {
      let j = i + 1;
      if (sql[j] === '@') j++;
      while (j < n && /[\w$.]/.test(sql[j])) j++;
      push('var', j);
    } else if (c === '?') {
      push('var', i + 1);
    } else {
      WORD_RE.lastIndex = i;
      const r = WORD_RE.exec(sql);
      if (r) {
        const word = r[0];
        const end = i + word.length;
        let k = end;
        while (k < n && (sql[k] === ' ' || sql[k] === '\t')) k++;
        const upper = word.toUpperCase();
        if (sql[k] === '(' && !KEYWORDS.has(upper)) push('fn', end);
        else if (upper === 'TRUE' || upper === 'FALSE' || upper === 'NULL') push('bool', end);
        else push(KEYWORDS.has(upper) ? 'kw' : 'word', end);
      } else if (/[(),;.]/.test(c)) {
        push('punct', i + 1);
      } else {
        push('op', i + 1);
      }
    }
  }
  return out;
}

const CLASS = { kw: 'tok-kw', fn: 'tok-fn', str: 'tok-str', num: 'tok-num', cmt: 'tok-cmt', ident: 'tok-ident', var: 'tok-var', op: 'tok-op', bool: 'tok-bool' };

/**
 * Highlight SQL into a DocumentFragment of text nodes and spans.
 * @param {string} sql
 * @returns {DocumentFragment}
 */
export function highlightSql(sql) {
  const frag = document.createDocumentFragment();
  let plain = '';
  const flush = () => { if (plain) { frag.appendChild(document.createTextNode(plain)); plain = ''; } };
  for (const tok of tokenize(sql)) {
    const c = CLASS[tok.type];
    if (!c) { plain += tok.text; continue; }
    flush();
    const span = document.createElement('span');
    span.className = c;
    span.textContent = tok.text;
    frag.appendChild(span);
  }
  flush();
  return frag;
}

/**
 * Highlight (already pretty-printed) JSON text.
 * @param {string} text
 * @returns {DocumentFragment}
 */
export function highlightJson(text) {
  const frag = document.createDocumentFragment();
  const re = /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g;
  let last = 0;
  let m;
  while ((m = re.exec(text))) {
    if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
    const span = document.createElement('span');
    if (m[1]) {
      span.className = m[2] ? 'tok-kw' : 'tok-str';
      span.textContent = m[1];
      frag.appendChild(span);
      if (m[2]) frag.appendChild(document.createTextNode(m[2]));
    } else {
      span.className = m[3] ? 'tok-bool' : 'tok-num';
      span.textContent = m[0];
      frag.appendChild(span);
    }
    last = re.lastIndex;
  }
  if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
  return frag;
}
