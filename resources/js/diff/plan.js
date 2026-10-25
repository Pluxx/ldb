/**
 * Schema diff helpers without DOM: grouping of diff.compare changes for the tree list and the
 * .sql script text of a diff.plan result. Labels come in as functions so the module stays pure.
 * @module diff/plan
 */

/** Order of change kinds inside a table group. */
const KIND_ORDER = [
  'table_added', 'table_removed', 'column_added', 'column_renamed', 'column_changed', 'column_removed',
  'index_added', 'index_changed', 'index_removed', 'fk_added', 'fk_changed', 'fk_removed',
  'check_added', 'check_changed', 'check_removed', 'option_changed',
];
const OBJECT_TYPES = ['view', 'routine', 'trigger', 'event'];

/** "added" | "removed" | "changed" | "renamed" of a change kind. */
export const actionOf = (kind) => String(kind).slice(String(kind).lastIndexOf('_') + 1);

/**
 * Group changes: one group per table (sorted by name; the table_added/removed change is the
 * group's own change), then one group per object type (views, routines, triggers, events).
 * @param {object[]} changes
 * @returns {{key: string, type: string, name: string, action: string, own: object|null, changes: object[]}[]}
 */
export function groupChanges(changes) {
  const tables = new Map();
  const objects = new Map(OBJECT_TYPES.map((t) => [t, []]));
  for (const c of changes) {
    if (c.object_type === 'table') {
      const name = String(c.table);
      if (!tables.has(name)) tables.set(name, { key: `table:${name}`, type: 'table', name, action: 'changed', own: null, changes: [] });
      const g = tables.get(name);
      if (c.kind === 'table_added' || c.kind === 'table_removed') {
        g.own = c;
        g.action = actionOf(c.kind);
      }
      g.changes.push(c);
    } else if (objects.has(c.object_type)) {
      objects.get(c.object_type).push(c);
    }
  }
  const coll = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
  const rank = (k) => { const i = KIND_ORDER.indexOf(k); return i < 0 ? 99 : i; };
  const out = [...tables.values()].sort((a, b) => coll.compare(a.name, b.name));
  out.forEach((g) => g.changes.sort((a, b) => rank(a.kind) - rank(b.kind) || coll.compare(String(a.object), String(b.object))));
  for (const [type, list] of objects) {
    if (!list.length) continue;
    list.sort((a, b) => coll.compare(String(a.object), String(b.object)));
    out.push({ key: `objects:${type}`, type, name: type, action: 'changed', own: null, changes: list });
  }
  return out;
}

/** MySQL compound statements (routine/trigger/event bodies with ";") need DELIMITER in a .sql file. */
const isCompound = (sql) => /^\s*CREATE\b[\s\S]*?\b(PROCEDURE|FUNCTION|TRIGGER|EVENT)\b/i.test(sql) && sql.includes(';');

/**
 * Script text for download / the SQL editor.
 * @param {{statements: {sql: string, phase: string}[]}} plan
 * @param {object} o
 * @param {string} o.driver
 * @param {string[]} [o.header]  comment lines (without "-- ")
 * @param {(phase: string) => string} [o.phaseLabel]
 * @param {boolean} [o.includeTxControl=true]  keep SQLite rebuild BEGIN/COMMIT + PRAGMA lines
 * @param {string|null} [o.use]  MySQL: database for a leading USE (objects created unqualified land there)
 * @returns {string}
 */
export function buildScript(plan, o) {
  const lines = [];
  for (const l of o.header || []) lines.push(`-- ${String(l).replace(/[\r\n]+/g, ' ')}`);
  if (lines.length) lines.push('');
  if (o.use) lines.push(`USE \`${String(o.use).replace(/`/g, '``')}\`;`, '');
  let phase = null;
  for (const st of plan.statements || []) {
    if (o.includeTxControl === false && st.tx_control) continue;
    if (st.phase !== phase && o.phaseLabel) {
      if (phase !== null) lines.push('');
      lines.push(`-- ${o.phaseLabel(st.phase)}`);
    }
    phase = st.phase;
    const sql = String(st.sql).replace(/;\s*$/, '');
    if (o.driver === 'mysql' && isCompound(sql)) {
      lines.push('DELIMITER $$', `${sql}$$`, 'DELIMITER ;');
    } else {
      lines.push(`${sql};`);
    }
  }
  return `${lines.join('\n')}\n`;
}

/** File name for a downloaded plan: diff-<source>-to-<target>-<yyyymmdd-hhmm>.sql (safe characters only). */
export function scriptFilename(source, target, date = new Date()) {
  const safe = (s) => String(s || 'db').replace(/[^\p{L}\p{N}_.-]+/gu, '_').slice(0, 60) || 'db';
  const p = (n) => String(n).padStart(2, '0');
  const stamp = `${date.getFullYear()}${p(date.getMonth() + 1)}${p(date.getDate())}-${p(date.getHours())}${p(date.getMinutes())}`;
  return `diff-${safe(source)}-to-${safe(target)}-${stamp}.sql`;
}
