/**
 * DEV ONLY — in-browser mock of api.php so the UI is demoable without PHP.
 * Activated by `?mock=1` in the page URL (see app.js); loaded via dynamic import() so it is a
 * separate chunk that production never downloads. Data is in-memory (reset on reload);
 * the login state survives reloads through sessionStorage.
 *
 * Login tricks: password "wrong" → db_error, user "limit" → rate_limited.
 */

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const ok = (data) => ({ status: 200, body: { ok: true, data } });
const fail = (status, code, message, sql) => ({ status, body: { ok: false, error: { code, message, sql } } });

/* ------------------------------------------------------------------ sample data */
let seed = 42;
const rnd = () => { seed = (seed * 16807) % 2147483647; return (seed - 1) / 2147483646; };
const pick = (a) => a[Math.floor(rnd() * a.length)];

const NAMES = ['山田 太郎', '佐藤 花子', '鈴木 一郎', '高橋 美咲', 'محمد العلي', 'فاطمة الزهراء', 'أحمد حسن', 'Иван Петров', 'Анна Смирнова', 'Дмитрий Козлов',
  'Nguyễn Văn An', 'Trần Thị Bích', 'Lukas Müller', 'Sophie Schäfer', 'Emma Johnson', 'Liam O’Brien', '김민준', '이서연', '王伟', '李娜', 'שרה כהן', 'דוד לוי'];
const COUNTRIES = ['JP', 'SA', 'EG', 'RU', 'VN', 'DE', 'US', 'IE', 'KR', 'CN', 'IL'];
const PRODUCTS = ['抹茶ラテ', 'Espresso Beans 1kg', 'تمر مجدول', 'Пряник тульский', 'Phở kit', 'Brezel', 'Kimchi Jar', '绿茶', 'Halva', 'Yuzu Soda', 'Matcha Whisk', 'Olive Oil'];

function iso(daysAgo) {
  const d = new Date(Date.UTC(2026, 9, 20) - daysAgo * 86400000 - Math.floor(rnd() * 86400000));
  return d.toISOString().slice(0, 19).replace('T', ' ');
}

const col = (name, type, extra = {}) => ({
  name, type: type.replace(/\(.*$/, '').toLowerCase(), full_type: type, length: (/\((\d+)/.exec(type) || [])[1] || null,
  unsigned: /unsigned/i.test(type), nullable: false, default: null, default_kind: 'none', auto_increment: false,
  primary: false, comment: null, collation: /char|text/i.test(type) ? 'utf8mb4_unicode_ci' : null, extra: null, generated: null, ...extra,
});

function makeDb() {
  const customers = {
    columns: [
      col('id', 'int(10) unsigned', { primary: true, auto_increment: true, extra: 'auto_increment' }),
      col('name', 'varchar(120)'),
      col('email', 'varchar(190)', { nullable: true, default_kind: 'null' }),
      col('country', 'char(2)', { default: 'JP', default_kind: 'value' }),
      col('vip', 'tinyint(1)', { default: '0', default_kind: 'value' }),
      col('profile', 'json', { nullable: true, default_kind: 'null', comment: 'Free-form profile data' }),
      col('avatar', 'blob', { nullable: true, default_kind: 'null' }),
      col('notes', 'text', { nullable: true, default_kind: 'null' }),
      col('created_at', 'datetime', { default: 'CURRENT_TIMESTAMP', default_kind: 'expression' }),
    ],
    rows: [],
    comment: '顧客 / العملاء / клиенты',
  };
  for (let i = 1; i <= 237; i++) {
    const name = pick(NAMES);
    customers.rows.push([
      i, name,
      rnd() < 0.15 ? null : `user${i}@example.${pick(['jp', 'com', 'ru', 'sa', 'de'])}`,
      pick(COUNTRIES), rnd() < 0.2 ? 1 : 0,
      rnd() < 0.3 ? null : JSON.stringify({ lang: pick(['ja', 'ar', 'ru', 'en', 'vi']), tags: [pick(['early', 'b2b', 'promo'])], score: Math.round(rnd() * 100) }),
      rnd() < 0.5 ? null : { $binary: 'iVBORw0KGgoAAAANSUhEUg==', length: 1024 + Math.floor(rnd() * 90000) },
      rnd() < 0.7 ? null : (rnd() < 0.5 ? 'Prefers delivery after 18:00.\nCall before arrival.' : 'いつもご利用ありがとうございます。次回の注文で10%割引を適用してください。配送は午前中を希望されています。'),
      iso(Math.floor(rnd() * 700)),
    ]);
  }
  const products = {
    columns: [
      col('id', 'int(11)', { primary: true, auto_increment: true }),
      col('sku', 'varchar(32)'),
      col('name', 'varchar(200)'),
      col('price', 'decimal(10,2)'),
      col('stock', 'int(11)', { default: '0', default_kind: 'value' }),
      col('status', "enum('active','hidden','discontinued')", { default: 'active', default_kind: 'value' }),
      col('description', 'mediumtext', { nullable: true, default_kind: 'null' }),
    ],
    rows: PRODUCTS.map((p, i) => [i + 1, `SKU-${1000 + i}`, p, (rnd() * 80 + 2).toFixed(2), Math.floor(rnd() * 500), pick(['active', 'active', 'hidden', 'discontinued']), rnd() < 0.4 ? null : `${p} — premium quality, imported.`]),
  };
  const orders = {
    columns: [
      col('id', 'bigint(20) unsigned', { primary: true, auto_increment: true }),
      col('customer_id', 'int(10) unsigned'),
      col('status', "enum('new','paid','shipped','cancelled')"),
      col('total', 'decimal(12,2)'),
      col('ordered_at', 'timestamp', { default: 'CURRENT_TIMESTAMP', default_kind: 'expression' }),
    ],
    rows: [],
    fks: [{ name: 'fk_orders_customer', columns: ['customer_id'], ref_schema: null, ref_table: 'customers', ref_columns: ['id'], on_delete: 'CASCADE', on_update: 'RESTRICT' }],
  };
  for (let i = 1; i <= 1520; i++) orders.rows.push([i, 1 + Math.floor(rnd() * 237), pick(['new', 'paid', 'paid', 'shipped', 'cancelled']), (rnd() * 300).toFixed(2), iso(Math.floor(rnd() * 400))]);
  const items = {
    columns: [col('order_id', 'bigint(20) unsigned', { primary: true }), col('product_id', 'int(11)', { primary: true }), col('qty', 'smallint(6)'), col('unit_price', 'decimal(10,2)')],
    rows: [],
    fks: [
      { name: 'fk_items_order', columns: ['order_id'], ref_schema: null, ref_table: 'orders', ref_columns: ['id'], on_delete: 'CASCADE', on_update: null },
      { name: 'fk_items_product', columns: ['product_id'], ref_schema: null, ref_table: 'products', ref_columns: ['id'], on_delete: null, on_update: null },
    ],
  };
  for (let i = 1; i <= 400; i++) items.rows.push([1 + Math.floor(i / 2), 1 + (i % 12), 1 + Math.floor(rnd() * 5), (rnd() * 40).toFixed(2)]);
  const logs = { columns: [col('ts', 'datetime'), col('level', 'varchar(10)'), col('message', 'text')], rows: Array.from({ length: 40 }, (_, i) => [iso(i), pick(['info', 'warn', 'error']), pick(['Cache warmed', 'Слишком много запросов', 'تمت المزامنة', '同期が完了しました'])]) };

  return {
    shop: { collation: 'utf8mb4_unicode_ci', tables: { customers, products, orders, order_items: items, audit_log: logs }, views: { v_order_totals: { base: 'orders' } }, routines: [{ name: 'recalc_totals', type: 'PROCEDURE' }, { name: 'fn_tax', type: 'FUNCTION', returns: 'decimal(10,2)' }], triggers: [{ name: 'trg_orders_bi', table: 'orders', timing: 'BEFORE', event: 'INSERT', statement: 'SET NEW.total = IFNULL(NEW.total, 0)' }] },
    'ブログ': {
      collation: 'utf8mb4_ja_0900_as_cs',
      tables: {
        '記事': { columns: [col('id', 'int(11)', { primary: true, auto_increment: true }), col('タイトル', 'varchar(200)'), col('本文', 'text'), col('公開日', 'date', { nullable: true, default_kind: 'null' })], rows: Array.from({ length: 64 }, (_, i) => [i + 1, pick(['春の京都散歩', 'ラーメン探訪', 'TypeScript入門', '富士山に登った話', '猫と暮らす']), '本文のサンプルテキストです。日本語の長い文章も正しく表示されるべきです。', rnd() < 0.2 ? null : iso(i * 3).slice(0, 10)]) },
        'コメント': { columns: [col('id', 'int(11)', { primary: true, auto_increment: true }), col('記事_id', 'int(11)'), col('名前', 'varchar(60)'), col('内容', 'text')], rows: Array.from({ length: 150 }, (_, i) => [i + 1, 1 + (i % 64), pick(NAMES), pick(['素晴らしい記事!', 'ありがとうございます', 'Great post', 'رائع جدا'])]), fks: [{ name: 'fk_c_k', columns: ['記事_id'], ref_schema: null, ref_table: '記事', ref_columns: ['id'], on_delete: 'CASCADE', on_update: null }] },
      },
      views: {}, routines: [], triggers: [],
    },
    'متجر': {
      collation: 'utf8mb4_unicode_ci',
      tables: {
        'المنتجات': { columns: [col('id', 'int(11)', { primary: true, auto_increment: true }), col('الاسم', 'varchar(120)'), col('السعر', 'decimal(10,2)'), col('الوصف', 'text', { nullable: true, default_kind: 'null' })], rows: Array.from({ length: 30 }, (_, i) => [i + 1, pick(['تمر سكري', 'قهوة عربية', 'زيت زيتون', 'عسل سدر', 'هيل']), (rnd() * 90).toFixed(2), rnd() < 0.3 ? null : 'منتج عالي الجودة من المملكة']) },
        'الطلبات': { columns: [col('id', 'int(11)', { primary: true }), col('العميل', 'varchar(120)'), col('التاريخ', 'datetime')], rows: Array.from({ length: 12 }, (_, i) => [i + 1, pick(NAMES), iso(i)]) },
      },
      views: {}, routines: [], triggers: [],
    },
    'аналитика': {
      collation: 'utf8mb4_general_ci',
      tables: {
        'пользователи': { columns: [col('id', 'int(11)', { primary: true, auto_increment: true }), col('имя', 'varchar(100)'), col('город', 'varchar(100)', { nullable: true, default_kind: 'null' }), col('зарегистрирован', 'datetime')], rows: Array.from({ length: 90 }, (_, i) => [i + 1, pick(['Иван', 'Ольга', 'Сергей', 'Наталья', 'Алексей']), rnd() < 0.2 ? null : pick(['Москва', 'Санкт-Петербург', 'Казань', 'Новосибирск']), iso(i * 2)]) },
        'события': { columns: [col('id', 'bigint(20)', { primary: true }), col('тип', 'varchar(40)'), col('данные', 'json', { nullable: true, default_kind: 'null' })], rows: Array.from({ length: 25 }, (_, i) => [i + 1, pick(['вход', 'покупка', 'выход']), JSON.stringify({ страница: '/каталог', время: i * 13 })]) },
      },
      views: {}, routines: [], triggers: [],
    },
    information_schema: { collation: 'utf8_general_ci', tables: { TABLES: { columns: [col('TABLE_SCHEMA', 'varchar(64)'), col('TABLE_NAME', 'varchar(64)')], rows: [['shop', 'customers'], ['shop', 'orders']] } }, views: {}, routines: [], triggers: [] },
  };
}

const DB = makeDb();

/* ------------------------------------------------------------------ auth */
const SS_KEY = 'ldb.mock.auth';
const loadAuth = () => { try { return JSON.parse(sessionStorage.getItem(SS_KEY)) || []; } catch { return []; } };
let connections = loadAuth();
const persist = () => { try { sessionStorage.setItem(SS_KEY, JSON.stringify(connections)); } catch { /* ignore */ } };

function status(extra = {}) {
  return {
    authenticated: connections.length > 0,
    csrf: 'mock-csrf-token',
    connections,
    presets: [
      { id: 'local-mysql', label: 'Local MySQL', driver: 'mysql', host: '127.0.0.1', port: 3306 },
      { id: 'local-pgsql', label: 'Local PostgreSQL', driver: 'pgsql', host: '127.0.0.1', port: 5432, database: 'postgres' },
    ],
    drivers: [
      { id: 'mysql', label: 'MySQL / MariaDB', available: true },
      { id: 'pgsql', label: 'PostgreSQL', available: true },
      { id: 'sqlite', label: 'SQLite 3', available: true },
      { id: 'sqlsrv', label: 'SQL Server', available: false },
    ],
    settings: { only_presets: false, allow_arbitrary_server: !location.search.includes('presets=1'), sqlite_enabled: true, theme_default: 'system', max_rows_browse: 1000, upload_max: 67108864 },
    version: '0.1.0-mock',
    ...extra,
  };
}

/* ------------------------------------------------------------------ helpers */
const getDb = (name) => DB[name];
const getTable = (db, table) => DB[db]?.tables[table] || (DB[db]?.views[table] ? DB[db].tables[DB[db].views[table].base] : null);
const sizeOf = (tb) => tb.rows.length * (tb.columns.length * 24 + 40);

function summary(name, tb, type = 'table') {
  return { name, type, rows: tb.rows.length, data_size: type === 'view' ? null : sizeOf(tb), index_size: type === 'view' ? null : Math.round(sizeOf(tb) * 0.3), engine: type === 'view' ? null : 'InnoDB', collation: type === 'view' ? null : 'utf8mb4_unicode_ci', comment: tb.comment || (type === 'view' ? 'VIEW' : ''), auto_increment: type === 'view' ? null : tb.rows.length + 1, updated: null };
}

function browseColumns(tb) {
  const fkMap = {};
  (tb.fks || []).forEach((f) => { fkMap[f.columns[0]] = { table: f.ref_table, column: f.ref_columns[0] }; });
  return tb.columns.map((c) => ({ name: c.name, type: c.full_type, nullable: c.nullable, primary: c.primary, fk: fkMap[c.name] || null }));
}

const qi = (n) => `\`${String(n).replace(/`/g, '``')}\``;

function matches(v, op, val) {
  const s = v === null || v === undefined ? null : (typeof v === 'object' ? '' : String(v));
  const n = Number(v);
  switch (op) {
    case '=': return s !== null && s === String(val);
    case '!=': return s !== String(val);
    case '<': return n < Number(val);
    case '>': return n > Number(val);
    case '<=': return n <= Number(val);
    case '>=': return n >= Number(val);
    case 'contains': return s !== null && s.toLowerCase().includes(String(val).toLowerCase());
    case 'starts': return s !== null && s.toLowerCase().startsWith(String(val).toLowerCase());
    case 'ends': return s !== null && s.toLowerCase().endsWith(String(val).toLowerCase());
    case 'LIKE': case 'NOT LIKE': {
      const re = new RegExp(`^${String(val).replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/%/g, '.*').replace(/_/g, '.')}$`, 'i');
      const m = s !== null && re.test(s);
      return op === 'LIKE' ? m : !m;
    }
    case 'IN': return (val || []).map(String).includes(s);
    case 'NOT IN': return !(val || []).map(String).includes(s);
    case 'IS NULL': return s === null;
    case 'IS NOT NULL': return s !== null;
    case 'BETWEEN': return n >= Number(val[0]) && n <= Number(val[1]);
    default: return true;
  }
}

function formatSql(sql) {
  const kw = /\b(select|from|where|and|or|order by|group by|having|limit|left join|right join|inner join|join|on|insert into|values|update|set|delete from)\b/gi;
  return sql.replace(/\s+/g, ' ').trim()
    .replace(kw, (m) => m.toUpperCase())
    .replace(/\s(FROM|WHERE|ORDER BY|GROUP BY|HAVING|LIMIT|LEFT JOIN|RIGHT JOIN|INNER JOIN|JOIN|VALUES|SET)\b/g, '\n$1')
    .replace(/\s(AND|OR)\b/g, '\n  $1')
    .replace(/;\s*/g, ';\n\n').trim();
}

function splitSql(sql) {
  return sql.split(/;\s*(?=(?:[^']*'[^']*')*[^']*$)/).map((s) => s.trim()).filter(Boolean);
}

function execOne(db, stmt, limit) {
  const t0 = performance.now();
  const base = { sql: stmt, is_result_set: false, columns: [], rows: [], affected: null, insert_id: null, truncated: false, error: null, editable: null };
  if (/\berror\b|\bsyntax\b/i.test(stmt) && !/^\s*select\s+'/i.test(stmt)) {
    return { ...base, error: "You have an error in your SQL syntax; check the manual near 'error' at line 1", time_ms: 0.8 };
  }
  if (/^\s*call\s+demo\s*\(\s*\)/i.test(stmt)) {
    const set = (name, rows) => ({ is_result_set: true, columns: [{ name, type: 'int' }], rows, affected: null, truncated: false });
    return { ...base, ...set('a', [[1]]), more_results: [set('b', [[2], [3]]), { ...set('c', []), is_result_set: false, columns: [], affected: 4 }], time_ms: 1.2 };
  }
  const sel = /^\s*select\s+([\s\S]+?)\s+from\s+[`"[]?([^`"\]\s;]+)[`"\]]?(?:\s+where\s+([\s\S]+?))?(?:\s+limit\s+(\d+))?\s*;?\s*$/i.exec(stmt);
  if (sel) {
    const tb = getTable(db, sel[2]);
    if (!tb) return { ...base, error: `Table '${db}.${sel[2]}' doesn't exist`, time_ms: 0.5 };
    const lim = Math.min(Number(sel[4] || limit), limit);
    let rows = tb.rows;
    const cols = tb.columns;
    let idx = cols.map((_, i) => i);
    if (sel[1].trim() !== '*') {
      const names = sel[1].split(',').map((s) => s.trim().replace(/[`"]/g, ''));
      idx = names.map((n) => cols.findIndex((c) => c.name === n)).filter((i) => i >= 0);
      if (!idx.length) idx = cols.map((_, i) => i);
    }
    rows = rows.slice(0, lim).map((r) => idx.map((i) => r[i]));
    const pk = cols.filter((c) => c.primary).map((c) => c.name);
    const editable = pk.length && pk.every((p) => idx.some((i) => cols[i].name === p)) ? { table: sel[2], primary_key: pk } : null;
    return { ...base, is_result_set: true, columns: idx.map((i) => ({ name: cols[i].name, type: cols[i].full_type, table: sel[2] })), rows, truncated: tb.rows.length > lim, editable, time_ms: performance.now() - t0 + 2.3 };
  }
  if (/^\s*(select|show|describe|explain)\b/i.test(stmt)) {
    const isNow = /now\(\)|version\(\)/i.test(stmt);
    return { ...base, is_result_set: true, columns: [{ name: isNow ? 'result' : 'value' }], rows: [[isNow ? '11.8.2-MariaDB' : 1]], time_ms: 0.4 };
  }
  const m = /^\s*(insert|update|delete|create|drop|alter|truncate|set|use)\b/i.exec(stmt);
  return { ...base, affected: m && /insert|update|delete/i.test(m[1]) ? Math.floor(rnd() * 5) + 1 : 0, insert_id: m && /insert/i.test(m[1]) ? String(1000 + Math.floor(rnd() * 100)) : null, time_ms: 1.7 };
}

function ddlFor(db, name, type) {
  if (type && type !== 'table' && type !== 'view') {
    const r = (DB[db].routines || []).find((x) => x.name === name);
    const tr = (DB[db].triggers || []).find((x) => x.name === name);
    if (tr) return `CREATE TRIGGER ${qi(tr.name)} ${tr.timing} ${tr.event} ON ${qi(tr.table)}\nFOR EACH ROW\n${tr.statement};`;
    if (r?.type === 'FUNCTION') return `CREATE FUNCTION ${qi(name)}(amount DECIMAL(10,2))\nRETURNS DECIMAL(10,2)\nDETERMINISTIC\nRETURN amount * 0.10;`;
    return `CREATE PROCEDURE ${qi(name)}()\nBEGIN\n  -- recalculate order totals\n  UPDATE orders o SET total = (\n    SELECT SUM(qty * unit_price) FROM order_items WHERE order_id = o.id\n  );\nEND`;
  }
  if (DB[db].views[name]) return `CREATE VIEW ${qi(name)} AS\nSELECT customer_id, COUNT(*) AS orders, SUM(total) AS revenue\nFROM ${qi(DB[db].views[name].base)}\nGROUP BY customer_id;`;
  const tb = DB[db].tables[name];
  const lines = tb.columns.map((c) => {
    let s = `  ${qi(c.name)} ${c.full_type}${c.nullable ? '' : ' NOT NULL'}`;
    if (c.default_kind === 'value') s += ` DEFAULT '${c.default}'`;
    else if (c.default_kind === 'expression') s += ` DEFAULT ${c.default}`;
    else if (c.default_kind === 'null') s += ' DEFAULT NULL';
    if (c.auto_increment) s += ' AUTO_INCREMENT';
    if (c.comment) s += ` COMMENT '${c.comment.replace(/'/g, "''")}'`;
    return s;
  });
  const pk = tb.columns.filter((c) => c.primary).map((c) => qi(c.name));
  if (pk.length) lines.push(`  PRIMARY KEY (${pk.join(', ')})`);
  (tb.fks || []).forEach((f) => lines.push(`  CONSTRAINT ${qi(f.name)} FOREIGN KEY (${f.columns.map(qi).join(', ')}) REFERENCES ${qi(f.ref_table)} (${f.ref_columns.map(qi).join(', ')})${f.on_delete ? ` ON DELETE ${f.on_delete}` : ''}`));
  return `CREATE TABLE ${qi(name)} (\n${lines.join(',\n')}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci${tb.comment ? ` COMMENT='${tb.comment}'` : ''};`;
}

/* ------------------------------------------------------------------ actions */
const handlers = {
  'auth.status': () => ok(status()),
  'auth.login': (p) => {
    if (p.user === 'limit') return fail(429, 'rate_limited', 'Too many failed login attempts. Try again in 5 minutes.');
    if (p.password === 'wrong') return fail(401, 'db_error', `SQLSTATE[HY000] [1045] Access denied for user '${p.user}'@'localhost' (using password: YES)`);
    const preset = status().presets.find((x) => x.id === p.preset);
    const driver = preset?.driver || p.driver || 'mysql';
    const id = String(connections.length ? Math.max(...connections.map((c) => Number(c.id))) + 1 : 1);
    connections.push({ id, driver, host: driver === 'sqlite' ? null : (preset?.host || p.host || 'localhost'), user: driver === 'sqlite' ? null : (p.user || 'root'), label: p.label || null, server: driver === 'pgsql' ? 'PostgreSQL 16.4' : '11.8.2-MariaDB', version: '11.8.2', database: p.database || p.file || null });
    persist();
    return ok(status({ conn: id }));
  },
  'auth.logout': (p) => {
    connections = p.conn ? connections.filter((c) => String(c.id) !== String(p.conn)) : [];
    persist();
    return ok(status());
  },
  'server.overview': (p) => {
    const c = connections.find((x) => String(x.id) === String(p.conn)) || {};
    return ok({
      info: { name: c.label || 'MariaDB', version: '11.8.2-MariaDB-ubu2404', version_comment: 'mariadb.org binary distribution', host: c.host || 'localhost', user: c.user || 'root', charset: 'utf8mb4', uptime: 386421, driver: c.driver || 'mysql' },
      capabilities: { schemas: false, users: true, processes: true, variables: true, status: true, engines: true, collations: true, charsets: true, routines: true, triggers: true, events: true, foreign_keys: true, alter_column: true, drop_column: true, rename_table: true, copy_table: true, unsigned: true, auto_increment: true, comments: true, maintenance: true, explain: true, create_database: true, kill: true },
      databases: dbList(),
    });
  },
  'db.list': () => ok(dbList()),
  'db.create': (p) => {
    if (!p.name) return fail(400, 'bad_request', 'Name required');
    if (DB[p.name]) return fail(400, 'db_error', `Can't create database '${p.name}'; database exists`);
    DB[p.name] = { collation: p.collation || 'utf8mb4_unicode_ci', tables: {}, views: {}, routines: [], triggers: [] };
    return ok(true);
  },
  'db.drop': (p) => { delete DB[p.name]; return ok(true); },
  'db.schemas': () => ok([]),
  'db.objects': (p) => {
    const d = getDb(p.db);
    if (!d) return fail(404, 'not_found', `Unknown database '${p.db}'`);
    return ok({
      tables: Object.entries(d.tables).map(([n, tb]) => summary(n, tb)),
      views: Object.keys(d.views).map((n) => summary(n, getTable(p.db, n), 'view')),
      routines: d.routines, triggers: d.triggers, events: [],
    });
  },
  'table.info': (p) => {
    const tb = getTable(p.db, p.table);
    if (!tb) return fail(404, 'not_found', `Table '${p.table}' doesn't exist`);
    return ok({
      name: p.table, type: DB[p.db].views[p.table] ? 'view' : 'table', columns: tb.columns,
      indexes: [
        ...(tb.columns.some((c) => c.primary) ? [{ name: 'PRIMARY', columns: tb.columns.filter((c) => c.primary).map((c) => c.name), unique: true, primary: true, type: 'BTREE' }] : []),
        ...(tb.indexes || []),
      ],
      foreign_keys: tb.fks || [],
      referenced_by: Object.entries(DB[p.db].tables).flatMap(([n, x]) => (x.fks || []).filter((f) => f.ref_table === p.table).map((f) => ({ ...f, table: n }))),
      triggers: (DB[p.db].triggers || []).filter((x) => x.table === p.table),
      options: { engine: tb.engine || 'InnoDB', collation: tb.collation || 'utf8mb4_unicode_ci', comment: tb.comment || '', rows: tb.rows.length, auto_increment: tb.rows.length + 1 },
    });
  },
  'table.ddl': (p) => {
    if (!DB[p.db]) return fail(404, 'not_found', 'Unknown database');
    return ok({ sql: ddlFor(p.db, p.table, p.type) });
  },
  'table.browse': (p) => {
    const tb = getTable(p.db, p.table);
    if (!tb) return fail(404, 'not_found', `Table '${p.db}.${p.table}' doesn't exist`);
    const t0 = performance.now();
    const cols = tb.columns;
    let rows = tb.rows.map((r, i) => ({ r, i }));
    for (const f of p.filters || []) {
      const ci = cols.findIndex((c) => c.name === f.column);
      if (ci >= 0) rows = rows.filter((x) => matches(x.r[ci], f.op, f.value));
    }
    if (p.search) {
      const s = String(p.search).toLowerCase();
      rows = rows.filter((x) => x.r.some((v) => v !== null && typeof v !== 'object' && String(v).toLowerCase().includes(s)));
    }
    const order = p.order || [];
    if (order.length) {
      const coll = new Intl.Collator(undefined, { numeric: true });
      rows.sort((a, b) => {
        for (const o of order) {
          const ci = cols.findIndex((c) => c.name === o.column);
          const va = a.r[ci];
          const vb = b.r[ci];
          if (va === vb) continue;
          const r = va === null ? -1 : vb === null ? 1 : typeof va === 'number' ? va - vb : coll.compare(String(va), String(vb));
          if (r) return o.dir === 'DESC' ? -r : r;
        }
        return a.i - b.i;
      });
    }
    const per = Math.min(Number(p.per_page) || 50, 1000);
    const page = Math.max(1, Number(p.page) || 1);
    const slice = rows.slice((page - 1) * per, page * per).map((x) => [...x.r]);
    const where = (p.filters || []).map((f) => `${qi(f.column)} ${f.op}${f.value !== undefined ? ` '${Array.isArray(f.value) ? f.value.join("','") : f.value}'` : ''}`);
    if (p.search) where.push(`CONCAT_WS(' ', …) LIKE '%${p.search}%'`);
    const sql = `SELECT * FROM ${qi(p.table)}${where.length ? ` WHERE ${where.join(' AND ')}` : ''}${order.length ? ` ORDER BY ${order.map((o) => `${qi(o.column)} ${o.dir}`).join(', ')}` : ''} LIMIT ${per} OFFSET ${(page - 1) * per}`;
    const pk = cols.filter((c) => c.primary).map((c) => c.name);
    return ok({ columns: browseColumns(tb), rows: slice, total: rows.length, primary_key: pk, editable: !DB[p.db].views[p.table] && pk.length > 0, sql, time_ms: performance.now() - t0 + 3.1 });
  },
  'table.insert': (p) => {
    const tb = getTable(p.db, p.table);
    const row = tb.columns.map((c) => {
      if (c.name in p.values) return p.values[c.name];
      if (c.auto_increment) return tb.rows.reduce((m, r) => Math.max(m, Number(r[tb.columns.indexOf(c)]) || 0), 0) + 1;
      if (c.default_kind === 'value') return c.default;
      if (c.default_kind === 'expression') return iso(0);
      return null;
    });
    tb.rows.push(row);
    return ok({ affected: 1, insert_id: String(row[0]) });
  },
  'table.update': (p) => {
    const tb = getTable(p.db, p.table);
    const idx = Object.keys(p.key).map((k) => tb.columns.findIndex((c) => c.name === k));
    const row = tb.rows.find((r) => idx.every((i, j) => String(r[i]) === String(Object.values(p.key)[j])));
    if (!row) return fail(404, 'not_found', 'Row not found (it may have been changed or deleted)');
    if (Object.values(p.values).some((v) => v === 'fail')) return fail(400, 'db_error', "Data too long for column 'name' at row 1", `UPDATE ${qi(p.table)} SET …`);
    for (const [k, v] of Object.entries(p.values)) row[tb.columns.findIndex((c) => c.name === k)] = v;
    return ok({ affected: 1 });
  },
  'table.delete': (p) => {
    const tb = getTable(p.db, p.table);
    let n = 0;
    for (const key of p.keys) {
      const idx = Object.keys(key).map((k) => tb.columns.findIndex((c) => c.name === k));
      const at = tb.rows.findIndex((r) => idx.every((i, j) => String(r[i]) === String(Object.values(key)[j])));
      if (at >= 0) { tb.rows.splice(at, 1); n++; }
    }
    return ok({ affected: n });
  },
  'table.fkValues': (p) => {
    const tb = getTable(p.db, p.table);
    const fk = (tb.fks || []).find((f) => f.columns[0] === p.column);
    if (!fk) return ok([]);
    const ref = getTable(p.db, fk.ref_table);
    const ci = ref.columns.findIndex((c) => c.name === fk.ref_columns[0]);
    const li = ref.columns.findIndex((c) => /char|text/i.test(c.type));
    const term = String(p.term || '').toLowerCase();
    return ok(ref.rows.filter((r) => !term || String(r[ci]).includes(term) || String(r[li] ?? '').toLowerCase().includes(term)).slice(0, 100).map((r) => ({ value: r[ci], label: li >= 0 ? String(r[li]) : '' })));
  },
  'table.drop': (p) => { (p.tables || [p.table]).forEach((n) => { delete DB[p.db].tables[n]; delete DB[p.db].views[n]; }); return ok({ affected: 0 }); },
  'table.empty': (p) => { let n = 0; (p.tables || [p.table]).forEach((x) => { const tb = DB[p.db].tables[x]; if (tb) { n += tb.rows.length; tb.rows = []; } }); return ok({ affected: n }); },
  'table.truncate': (p) => handlers['table.empty'](p),
  'table.maintenance': (p) => ok({ columns: [{ name: 'Table' }, { name: 'Op' }, { name: 'Msg_type' }, { name: 'Msg_text' }], rows: (p.tables || []).map((x) => [`${p.db}.${x}`, p.op, 'status', 'OK']) }),
  'sql.execute': (p) => {
    const stmts = splitSql(p.sql || '');
    const results = [];
    for (const s of stmts) {
      const r = execOne(p.db, s, Number(p.limit) || 1000);
      results.push(r);
      if (r.error && p.stop_on_error !== false) break;
    }
    return ok({ results, tx: p.tx || 'none', rolled_back: false, cancelled: false, warnings: [] });
  },
  // The mock runs synchronously: there is never a running query to cancel.
  'sql.cancel': () => ok({ cancelled: false, found: false }),
  'sql.explain': (p) => ok({ sql: `EXPLAIN ${p.sql}`, is_result_set: true, columns: ['id', 'select_type', 'table', 'type', 'possible_keys', 'key', 'rows', 'Extra'].map((name) => ({ name })), rows: [[1, 'SIMPLE', 'customers', 'ALL', null, null, 237, 'Using where']], affected: null, insert_id: null, time_ms: 0.9, truncated: false, error: null, editable: null }),
  'sql.format': (p) => ok({ sql: formatSql(p.sql || '') }),
  'sql.completions': (p) => {
    const d = getDb(p.db);
    const tables = {};
    if (d) for (const [n, tb] of Object.entries(d.tables)) tables[n] = tb.columns.map((c) => c.name);
    return ok({ tables, functions: ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'CONCAT', 'COALESCE', 'IFNULL', 'NOW', 'DATE_FORMAT', 'JSON_EXTRACT', 'LOWER', 'UPPER', 'SUBSTRING', 'ROUND'], keywords: ['SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'NULL', 'IS', 'IN', 'LIKE', 'BETWEEN', 'ORDER', 'BY', 'GROUP', 'HAVING', 'LIMIT', 'OFFSET', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'ON', 'AS', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'CREATE', 'TABLE', 'ALTER', 'DROP', 'DISTINCT', 'UNION', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'ASC', 'DESC'] });
  },
  'server.collations': () => ok(['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_ja_0900_as_cs', 'utf8mb4_0900_ai_ci', 'latin1_swedish_ci'].map((name) => ({ name, charset: name.split('_')[0] }))),
  // ---- structure editor / create table / operations (MySQL-flavoured SQL, see mockDdl* below)
  'server.dataTypes': () => ok({
    Numeric: ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'DOUBLE', 'BIT', 'BOOLEAN'],
    'Date and time': ['DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR'],
    String: ['CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'BINARY', 'VARBINARY', 'BLOB', 'ENUM', 'SET'],
    Spatial: ['GEOMETRY', 'POINT', 'POLYGON'],
    Other: ['JSON', 'UUID', 'INET6'],
  }),
  'server.engines': () => ok([{ name: 'InnoDB', support: 'DEFAULT', comment: 'Transactions, row-level locking, foreign keys' }, { name: 'Aria', support: 'YES', comment: 'Crash-safe MyISAM' }, { name: 'MyISAM', support: 'YES', comment: 'Non-transactional' }, { name: 'MEMORY', support: 'YES', comment: 'Hash based, in memory' }]),
  'table.create': (p) => mockDdlCreate(p),
  'table.alter': (p) => mockDdlAlter(p),
  'table.rename': (p) => {
    const d = DB[p.db];
    if (!d?.tables[p.table]) return fail(404, 'not_found', `Table '${p.table}' doesn't exist`);
    if (d.tables[p.new_name]) return fail(400, 'db_error', `Table '${p.new_name}' already exists`);
    d.tables[p.new_name] = d.tables[p.table];
    delete d.tables[p.table];
    (d.triggers || []).forEach((x) => { if (x.table === p.table) x.table = p.new_name; });
    return ok(true);
  },
  'table.copy': (p) => {
    const src = DB[p.db]?.tables[p.table];
    const dst = DB[p.target_db || p.db];
    if (!src || !dst) return fail(404, 'not_found', 'Unknown table or database');
    if (dst.tables[p.new_name]) return fail(400, 'db_error', `Table '${p.new_name}' already exists`);
    const copy = JSON.parse(JSON.stringify(src));
    if (p.with_data === false) copy.rows = [];
    dst.tables[p.new_name] = copy;
    return ok(true);
  },
};

/* ---- ER diagram / database search (views/erd.js, views/db-search.js) */
{
  const shop = DB.shop.tables;
  const fk = (name, column, ref, refCol = 'id', onDelete = null) => ({ name, columns: [column], ref_schema: null, ref_table: ref, ref_columns: [refCol], on_delete: onDelete, on_update: null });
  shop.addresses = {
    columns: [col('id', 'int(11)', { primary: true, auto_increment: true }), col('customer_id', 'int(10) unsigned'), col('line1', 'varchar(200)'), col('city', 'varchar(100)'), col('postal_code', 'varchar(16)', { nullable: true, default_kind: 'null' }), col('country', 'char(2)')],
    rows: Array.from({ length: 60 }, (_, i) => [i + 1, 1 + (i % 237), `${i + 1} Sakura-dori`, pick(['東京', 'Berlin', 'Riyadh', 'Москва', 'Hà Nội']), rnd() < 0.2 ? null : String(10000 + i * 7), pick(COUNTRIES)]),
    fks: [fk('fk_addr_customer', 'customer_id', 'customers', 'id', 'CASCADE')],
  };
  shop.payments = {
    columns: [col('id', 'bigint(20) unsigned', { primary: true, auto_increment: true }), col('order_id', 'bigint(20) unsigned'), col('method', "enum('card','bank','cod')"), col('amount', 'decimal(12,2)'), col('reference', 'varchar(64)', { nullable: true, default_kind: 'null' }), col('paid_at', 'datetime', { nullable: true, default_kind: 'null' })],
    rows: Array.from({ length: 80 }, (_, i) => [i + 1, 1 + i * 3, pick(['card', 'bank', 'cod']), (rnd() * 300).toFixed(2), rnd() < 0.3 ? null : `TX-${90000 + i}`, rnd() < 0.1 ? null : iso(i)]),
    fks: [fk('fk_pay_order', 'order_id', 'orders', 'id', 'CASCADE')],
  };
  shop['レビュー'] = {
    columns: [col('id', 'int(11)', { primary: true, auto_increment: true }), col('product_id', 'int(11)'), col('customer_id', 'int(10) unsigned', { nullable: true, default_kind: 'null' }), col('評価', 'tinyint(4)'), col('コメント', 'text', { nullable: true, default_kind: 'null' })],
    rows: Array.from({ length: 45 }, (_, i) => [i + 1, 1 + (i % 12), rnd() < 0.2 ? null : 1 + (i * 5) % 237, 1 + (i % 5), rnd() < 0.3 ? null : pick(['とても美味しい！', 'Great value', 'جيد جدا', 'Неплохо', '抹茶の香りが最高'])]),
    fks: [fk('fk_rev_product', 'product_id', 'products'), fk('fk_rev_customer', 'customer_id', 'customers', 'id', 'SET NULL')],
    comment: '商品レビュー',
  };

  handlers['db.erd'] = (p) => {
    const d = getDb(p.db);
    if (!d) return fail(404, 'not_found', `Unknown database '${p.db}'`);
    const tables = [];
    const relations = [];
    for (const [name, tb] of Object.entries(d.tables)) {
      tables.push({ name, columns: tb.columns.map((c) => ({ name: c.name, type: c.full_type, primary: c.primary, nullable: c.nullable })) });
      for (const f of tb.fks || []) relations.push({ from_table: name, from_columns: f.columns, to_table: f.ref_table, to_columns: f.ref_columns, name: f.name });
    }
    return ok({ tables, relations });
  };

  handlers['db.search'] = (p) => {
    const d = getDb(p.db);
    if (!d) return fail(404, 'not_found', `Unknown database '${p.db}'`);
    const term = String(p.term || '');
    if (!term) return fail(400, 'bad_request', 'Missing parameter: term');
    let re = null;
    if (p.mode === 'regex') {
      try { re = new RegExp(term, 'i'); } catch (e) { return fail(400, 'db_error', `Got error 'invalid regex' from regexp: ${e.message}`); }
    }
    const lc = term.toLowerCase();
    const test = (v) => {
      if (v === null || v === undefined || typeof v === 'object') return false;
      const s = String(v);
      return p.mode === 'exact' ? s === term : re ? re.test(s) : s.toLowerCase().includes(lc);
    };
    const out = [];
    for (const name of p.tables && p.tables.length ? p.tables : Object.keys(d.tables)) {
      const tb = d.tables[name];
      if (!tb) continue;
      const cols = tb.columns.map((c, i) => [c, i]).filter(([c]) => /char|text|enum|set|json/i.test(c.full_type));
      if (!cols.length) continue;
      const matches = {};
      let count = 0;
      for (const r of tb.rows) {
        let any = false;
        for (const [c, i] of cols) if (test(r[i])) { matches[c.name] = (matches[c.name] || 0) + 1; any = true; }
        if (any) count++;
      }
      if (count) out.push({ table: name, column_matches: matches, count });
    }
    return ok(out);
  };
}

/* ---- import / export / server administration (views/import.js, export.js, server-admin.js) */
{
  const opt = (name, type, label, def, choices) => ({ name, type, label, default: def, ...(choices ? { choices } : {}) });
  const FORMATS = [
    { id: 'sql', label: 'SQL', extension: 'sql', options: [opt('structure', 'bool', 'Structure (CREATE)', true), opt('data', 'bool', 'Data (INSERT)', true), opt('drop_table', 'bool', 'Add DROP TABLE IF EXISTS', false), opt('insert_style', 'select', 'INSERT style', 'insert', [{ value: 'insert', label: 'INSERT' }, { value: 'insert_ignore', label: 'INSERT IGNORE' }, { value: 'replace', label: 'REPLACE' }]), opt('extended_inserts', 'text', 'Rows per INSERT statement', '100')] },
    { id: 'csv', label: 'CSV', extension: 'csv', options: [opt('delimiter', 'select', 'Delimiter', ',', [{ value: ',', label: 'Comma (,)' }, { value: ';', label: 'Semicolon (;)' }, { value: '\t', label: 'Tab' }, { value: '|', label: 'Pipe (|)' }]), opt('header', 'bool', 'First row contains column names', true), opt('null_as', 'text', 'Write NULL as', '\\N'), opt('bom', 'bool', 'UTF-8 BOM (for Excel)', false), opt('multi', 'bool', 'Allow several tables (sections)', false)] },
    { id: 'json', label: 'JSON', extension: 'json', options: [opt('pretty', 'bool', 'Pretty print', true)] },
    { id: 'markdown', label: 'Markdown', extension: 'md', options: [opt('data', 'bool', 'Include data', true)] },
  ];
  handlers['export.formats'] = () => ok({ formats: FORMATS, compression: ['none', 'gzip', 'zip'] });
  handlers['export.download'] = (p) => {
    const o = typeof p.options === 'string' ? JSON.parse(p.options) : (p.options || {});
    const d = getDb(p.db) || { tables: {} };
    const names = o.query ? ['query'] : (p.tables?.length ? p.tables : Object.keys(d.tables));
    const lines = [`-- LDB mock export (${p.format}) of ${p.db || 'server'}`, o.query ? `-- ${o.query}` : `-- tables: ${names.join(', ')}`];
    for (const n of names) { const tb = getTable(p.db, n); if (tb) lines.push(`-- ${n}: ${tb.rows.length} rows`); }
    const ext = FORMATS.find((f) => f.id === p.format)?.extension || 'txt';
    return ok({ filename: `${p.db || 'export'}_mock.${ext}`, content: `${lines.join('\n')}\n`, mime: 'text/plain;charset=utf-8' });
  };

  handlers['import.options'] = () => ok({
    encodings: [['UTF-8', 'Unicode (UTF-8)'], ['UTF-16LE', 'Unicode (UTF-16LE)'], ['SJIS-win', 'Japanese (Shift_JIS / CP932)'], ['eucJP-win', 'Japanese (EUC-JP)'], ['GB18030', 'Chinese Simplified (GB18030)'], ['BIG-5', 'Chinese Traditional (Big5)'], ['UHC', 'Korean (CP949 / UHC)'], ['Windows-1251', 'Cyrillic (Windows-1251)'], ['KOI8-R', 'Cyrillic (KOI8-R)'], ['Windows-1252', 'Western European (Windows-1252)']].map(([id, label]) => ({ id, label })),
    upload_max: 64 * 1024 * 1024,
  });
  handlers['import.upload'] = (p) => {
    if (!getDb(p.db)) return fail(404, 'not_found', `Unknown database '${p.db}'`);
    const size = p.file?.size || 0;
    if (p.format === 'csv') {
      const rows = Math.max(1, Math.round(size / 60));
      return ok({ rows, statements: rows, errors: rows > 5 ? [{ line: 4, message: "Incorrect integer value: 'abc' for column 'id' at row 1" }] : [], aborted: false, time_ms: 84.2, time: 0.084 });
    }
    const o = typeof p.options === 'string' ? JSON.parse(p.options) : (p.options || {});
    const skipped = Number(o.skip_statements) || 0;
    const fails = skipped === 0;
    return ok({
      statements: fails ? 12 : 30, skipped, position: skipped + (fails ? 12 : 30), aborted: fails && !!o.stop_on_error,
      errors: fails ? [{ statement: "INSERT INTO `orders` (`id`, `customer_id`) VALUES (1, 9999);", message: 'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails' }] : [],
      time_ms: 312.5, time: 0.313,
    });
  };

  const VARS = [['autocommit', 'ON'], ['character_set_server', 'utf8mb4'], ['collation_server', 'utf8mb4_unicode_ci'], ['innodb_buffer_pool_size', '134217728'], ['innodb_log_file_size', '100663296'], ['innodb_flush_log_at_trx_commit', '1'], ['innodb_file_per_table', 'ON'], ['max_connections', '151'], ['max_allowed_packet', '16777216'], ['sql_mode', 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER'], ['time_zone', 'SYSTEM'], ['version', '11.8.2-MariaDB'], ['wait_timeout', '28800'], ['log_bin', 'OFF'], ['log_error', ''], ['log_warnings', '2'], ['query_cache_size', '1048576'], ['query_cache_type', 'OFF'], ['query_cache_limit', '1048576']];
  handlers['server.variables'] = () => ok(VARS.map(([name, value]) => ({ name, value })));
  const started = Date.now();
  handlers['server.status'] = () => {
    const up = 386421 + Math.round((Date.now() - started) / 1000);
    return ok([['Uptime', up], ['Threads_connected', 7], ['Threads_running', 2], ['Max_used_connections', 23], ['Connections', 18234], ['Questions', 9123456 + up], ['Queries', 9234567 + up], ['Bytes_received', 1843234567], ['Bytes_sent', 9876543210], ['Innodb_buffer_pool_reads', 12345], ['Innodb_buffer_pool_read_requests', 98765432], ['Com_select', 512345], ['Com_insert', 23456], ['Com_update', 3456], ['Slow_queries', 3]].map(([name, value]) => ({ name, value: String(value) })));
  };
  let procs = [[5, 'root', 'localhost', null, 'Sleep', 120, '', null], [12, 'app', '10.0.0.4:51234', 'shop', 'Query', 47, 'Sending data', 'SELECT c.*, SUM(o.total) FROM customers c JOIN orders o ON o.customer_id = c.id GROUP BY c.id ORDER BY 2 DESC'], [13, 'app', '10.0.0.4:51240', 'shop', 'Query', 0, 'starting', 'SHOW FULL PROCESSLIST'], [14, 'report', '10.0.0.9:40022', 'shop', 'Sleep', 3, '', null], [1, 'system user', '', null, 'Daemon', 386000, 'InnoDB purge coordinator', null]];
  handlers['server.processes'] = () => ok({ columns: ['id', 'User', 'Host', 'db', 'Command', 'Time', 'State', 'Info'], rows: procs.map((r) => (r[4] === 'Query' && r[5] > 0 ? [...r.slice(0, 5), r[5] + Math.round((Date.now() - started) / 1000), ...r.slice(6)] : r)) });
  handlers['server.kill'] = (p) => { procs = procs.filter((r) => String(r[0]) !== String(p.id)); return ok(true); };
  let users = [{ user: 'root', host: 'localhost', plugin: 'mysql_native_password', account_locked: false, password_expired: false, global_privileges: 'ALL' }, { user: 'app', host: '%', plugin: 'mysql_native_password', account_locked: false, password_expired: false, global_privileges: 'USAGE' }, { user: 'report', host: '10.0.0.%', plugin: 'mysql_native_password', account_locked: true, password_expired: false, global_privileges: 'USAGE' }];
  const grants = { 'app@%': ["GRANT USAGE ON *.* TO `app`@`%` IDENTIFIED BY PASSWORD '*2470C0C06DEE42FD1618BB99005ADCA2EC9D1E19'", 'GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.* TO `app`@`%`'] };
  handlers['server.users'] = () => ok(users);
  handlers['server.userGrants'] = (p) => ok(grants[`${p.user}@${p.host}`] || [`GRANT ALL PRIVILEGES ON *.* TO \`${p.user}\`@\`${p.host}\` WITH GRANT OPTION`]);
  handlers['server.userSave'] = (p) => {
    if (p.is_new) {
      if (users.some((u) => u.user === p.user && u.host === (p.host || '%'))) return fail(400, 'db_error', `Operation CREATE USER failed for '${p.user}'@'${p.host}'`);
      users.push({ user: p.user, host: p.host || '%', plugin: 'mysql_native_password', account_locked: false, password_expired: false, global_privileges: 'USAGE' });
      grants[`${p.user}@${p.host || '%'}`] = [`GRANT USAGE ON *.* TO \`${p.user}\`@\`${p.host || '%'}\``, ...(p.grants || [])];
    }
    return ok(true);
  };
  handlers['server.userDrop'] = (p) => { users = users.filter((u) => !(u.user === p.user && u.host === p.host)); return ok(true); };
  handlers['server.charsets'] = () => ok([['utf8mb4', 'UTF-8 Unicode', 'utf8mb4_general_ci', 4], ['utf8mb3', 'UTF-8 Unicode', 'utf8mb3_general_ci', 3], ['latin1', 'cp1252 West European', 'latin1_swedish_ci', 1], ['sjis', 'Shift-JIS Japanese', 'sjis_japanese_ci', 2], ['cp932', 'SJIS for Windows Japanese', 'cp932_japanese_ci', 2], ['gbk', 'GBK Simplified Chinese', 'gbk_chinese_ci', 2], ['big5', 'Big5 Traditional Chinese', 'big5_chinese_ci', 2], ['cp1251', 'Windows Cyrillic', 'cp1251_general_ci', 1]].map(([name, description, default_collation, maxlen]) => ({ name, description, default_collation, maxlen })));
  handlers['server.engines'] = () => ok([['InnoDB', 'DEFAULT', 'Supports transactions, row-level locking, foreign keys and encryption for tables'], ['Aria', 'YES', 'Crash-safe tables with MyISAM heritage'], ['MyISAM', 'YES', 'Non-transactional engine with good performance and small data footprint'], ['MEMORY', 'YES', 'Hash based, stored in memory, useful for temporary tables'], ['CSV', 'YES', 'Stores tables as CSV files'], ['FEDERATED', 'NO', 'Allows to access tables on other MariaDB servers']].map(([name, support, comment]) => ({ name, support, comment })));
}

function dbList() {
  return Object.entries(DB).map(([name, d]) => ({ name, collation: d.collation, tables: Object.keys(d.tables).length + Object.keys(d.views).length, size: Object.values(d.tables).reduce((s, tb) => s + sizeOf(tb), 0) }));
}

/* ------------------------------------------------------------------ DDL (table.create / table.alter) */
const sqlStr = (v) => `'${String(v ?? '').replace(/'/g, "''")}'`;
function mockColSql(c) {
  if (!c.name || !c.type || !/^[A-Za-z][A-Za-z0-9_ ]*$/.test(c.type)) throw Object.assign(new Error(`Invalid column type: ${c.type || ''}`), { mock: true });
  let s = `${qi(c.name)} ${String(c.type).toUpperCase()}${c.length ? `(${c.length})` : ''}${c.unsigned ? ' UNSIGNED' : ''}`;
  if (c.collation) s += ` COLLATE ${c.collation}`;
  s += c.nullable ? ' NULL' : ' NOT NULL';
  if (c.default_kind === 'null') s += ' DEFAULT NULL';
  else if (c.default_kind === 'value') s += ` DEFAULT ${sqlStr(c.default)}`;
  else if (c.default_kind === 'expression' && c.default) s += ` DEFAULT ${c.default}`;
  if (c.on_update) s += ` ON UPDATE ${String(c.on_update).toUpperCase()}`;
  if (c.auto_increment) s += ' AUTO_INCREMENT';
  if (c.comment) s += ` COMMENT ${sqlStr(c.comment)}`;
  return s;
}
const mockPos = (d) => (d.first ? ' FIRST' : d.after ? ` AFTER ${qi(d.after)}` : '');
const mockIdxCols = (cols) => cols.map((c) => { const m = /^(.+)\((\d+)\)$/.exec(c); return m ? `${qi(m[1])}(${m[2]})` : qi(c); }).join(', ');
const mockIdxSql = (ix) => (ix.primary ? `PRIMARY KEY (${mockIdxCols(ix.columns)})` : `${ix.type ? `${ix.type} INDEX` : ix.unique ? 'UNIQUE INDEX' : 'INDEX'}${ix.name ? ` ${qi(ix.name)}` : ''} (${mockIdxCols(ix.columns)})`);
const mockFkSql = (f) => `${f.name ? `CONSTRAINT ${qi(f.name)} ` : ''}FOREIGN KEY (${f.columns.map(qi).join(', ')}) REFERENCES ${qi(f.ref_table)} (${f.ref_columns.map(qi).join(', ')})${f.on_delete ? ` ON DELETE ${f.on_delete}` : ''}${f.on_update ? ` ON UPDATE ${f.on_update}` : ''}`;
function mockOpts(o = {}) {
  const out = [];
  if (o.engine) out.push(`ENGINE=${o.engine}`);
  if (o.collation) out.push(`DEFAULT CHARSET=${o.collation.split('_')[0]} COLLATE=${o.collation}`);
  if (o.comment !== undefined && o.comment !== null) out.push(`COMMENT=${sqlStr(o.comment)}`);
  if (o.auto_increment) out.push(`AUTO_INCREMENT=${Number(o.auto_increment)}`);
  return out;
}
const mockColumn = (c, primary) => ({
  name: c.name, type: String(c.type).toLowerCase(), full_type: `${String(c.type).toLowerCase()}${c.length ? `(${c.length})` : ''}${c.unsigned ? ' unsigned' : ''}`,
  length: c.length || null, unsigned: !!c.unsigned, nullable: !!c.nullable, default: c.default ?? null, default_kind: c.default_kind || 'none',
  auto_increment: !!c.auto_increment, primary: !!primary, comment: c.comment || null,
  collation: c.collation || (/char|text|enum|set/i.test(c.type) ? 'utf8mb4_unicode_ci' : null),
  extra: [c.auto_increment ? 'auto_increment' : '', c.on_update ? `on update ${c.on_update}` : ''].filter(Boolean).join(' ') || null, generated: c.generated || null,
});
function mockDdlCreate(p) {
  const d = p.definition || {};
  if (!d.name) return fail(400, 'bad_request', 'Table name is required');
  if (!(d.columns || []).length) return fail(400, 'bad_request', 'A table needs at least one column');
  if (!DB[p.db]) return fail(404, 'not_found', `Unknown database '${p.db}'`);
  if (DB[p.db].tables[d.name]) return fail(400, 'db_error', `Table '${d.name}' already exists`, `CREATE TABLE ${qi(d.name)} …`);
  let lines;
  try { lines = d.columns.map((c) => `  ${mockColSql(c)}`); } catch (e) { return fail(400, 'bad_request', e.message); }
  if (d.primary_key?.length) lines.push(`  PRIMARY KEY (${d.primary_key.map(qi).join(', ')})`);
  (d.indexes || []).forEach((ix) => lines.push(`  ${mockIdxSql(ix)}`));
  (d.foreign_keys || []).forEach((f) => lines.push(`  ${mockFkSql(f)}`));
  const o = mockOpts(d.options);
  const sql = [`CREATE TABLE ${qi(d.name)} (\n${lines.join(',\n')}\n)${o.length ? ` ${o.join(' ')}` : ''}`];
  if (!p.preview) {
    DB[p.db].tables[d.name] = {
      columns: d.columns.map((c) => mockColumn(c, (d.primary_key || []).includes(c.name) || (d.indexes || []).some((ix) => ix.primary && ix.columns.includes(c.name)))),
      rows: [], fks: d.foreign_keys || [], comment: d.options?.comment || '', engine: d.options?.engine, collation: d.options?.collation,
      indexes: (d.indexes || []).filter((ix) => !ix.primary).map((ix) => ({ name: ix.name || ix.columns[0], columns: ix.columns, unique: !!ix.unique, primary: false, type: ix.type || 'BTREE' })),
    };
  }
  return ok({ sql, executed: !p.preview });
}
function mockDdlAlter(p) {
  const tb = DB[p.db]?.tables[p.table];
  if (!tb) return fail(404, 'not_found', `Table '${p.table}' doesn't exist`);
  const s = p.changes || {};
  const qt = qi(p.table);
  const out = [];
  const c = [];
  try {
    if (s.drop_fk?.length) out.push(`ALTER TABLE ${qt}\n  ${s.drop_fk.map((n) => `DROP FOREIGN KEY ${qi(n)}`).join(',\n  ')}`);
    (s.drop_index || []).forEach((n) => c.push(n === 'PRIMARY' ? 'DROP PRIMARY KEY' : `DROP INDEX ${qi(n)}`));
    (s.drop || []).forEach((n) => c.push(`DROP COLUMN ${qi(n)}`));
    (s.modify || []).forEach((m) => c.push(`CHANGE COLUMN ${qi(m.column)} ${mockColSql({ name: m.column, ...m.definition })}${mockPos(m.definition)}`));
    (s.add || []).forEach((d) => c.push(`ADD COLUMN ${mockColSql(d)}${mockPos(d)}`));
    (s.add_index || []).forEach((ix) => c.push(`ADD ${mockIdxSql(ix)}`));
    c.push(...mockOpts(s.options));
  } catch (e) { return fail(400, 'bad_request', e.message); }
  if (c.length) out.push(`ALTER TABLE ${qt}\n  ${c.join(',\n  ')}`);
  if (s.add_fk?.length) out.push(`ALTER TABLE ${qt}\n  ${s.add_fk.map((f) => `ADD ${mockFkSql(f)}`).join(',\n  ')}`);
  if (!p.preview) {
    if ((s.add || []).some((d) => d.name === 'fail')) return fail(400, 'db_error', "Duplicate column name 'fail'", out.join(';\n'));
    mockApplyAlter(tb, s, p.table);
  }
  return ok({ sql: out, executed: !p.preview });
}
function mockApplyAlter(tb, s, table) {
  const idxOf = (n) => tb.columns.findIndex((x) => x.name === n);
  const place = (i, d) => {
    if (!d.first && !d.after) return;
    const [colObj] = tb.columns.splice(i, 1);
    const vals = tb.rows.map((r) => r.splice(i, 1)[0]);
    const at = d.first ? 0 : idxOf(d.after) + 1;
    tb.columns.splice(at, 0, colObj);
    tb.rows.forEach((r, k) => r.splice(at, 0, vals[k]));
  };
  tb.fks = (tb.fks || []).filter((f) => !(s.drop_fk || []).includes(f.name));
  tb.indexes = (tb.indexes || []).filter((ix) => !(s.drop_index || []).includes(ix.name));
  if ((s.drop_index || []).includes('PRIMARY')) tb.columns.forEach((x) => { x.primary = false; });
  for (const n of s.drop || []) { const i = idxOf(n); if (i >= 0) { tb.columns.splice(i, 1); tb.rows.forEach((r) => r.splice(i, 1)); } }
  for (const m of s.modify || []) { const i = idxOf(m.column); if (i >= 0) { tb.columns[i] = mockColumn({ name: m.column, ...m.definition }, tb.columns[i].primary); place(i, m.definition); } }
  for (const d of s.add || []) { tb.columns.push(mockColumn(d, false)); tb.rows.forEach((r) => r.push(d.default_kind === 'value' ? d.default : null)); place(tb.columns.length - 1, d); }
  for (const ix of s.add_index || []) {
    if (ix.primary) ix.columns.forEach((n) => { const x = tb.columns[idxOf(n.replace(/\(\d+\)$/, ''))]; if (x) x.primary = true; });
    else tb.indexes.push({ name: ix.name || ix.columns[0].replace(/\(\d+\)$/, ''), columns: ix.columns, unique: !!ix.unique, primary: false, type: ix.type || 'BTREE' });
  }
  (s.add_fk || []).forEach((f) => tb.fks.push({ ...f, name: f.name || `${table}_ibfk_${tb.fks.length + 1}` }));
  if (s.options) {
    if ('comment' in s.options) tb.comment = s.options.comment;
    if (s.options.engine) tb.engine = s.options.engine;
    if (s.options.collation) tb.collation = s.options.collation;
  }
}

/**
 * Transport compatible with api.js setTransport().
 * @param {string} action
 * @param {object} params
 * @param {AbortSignal} [signal]
 */
export async function transport(action, params, signal) {
  await sleep(90 + Math.floor(Math.random() * 220));
  if (signal?.aborted) { const e = new Error('Aborted'); e.name = 'AbortError'; throw e; }
  const fn = handlers[action];
  if (action !== 'auth.status' && action !== 'auth.login' && !connections.length) return fail(401, 'auth_required', 'Please log in');
  if (!fn) return fail(400, 'unsupported', `Mock: action "${action}" is not implemented`);
  try {
    // deep-clone so the UI never mutates mock state by reference
    const res = fn(JSON.parse(JSON.stringify(params || {})));
    return JSON.parse(JSON.stringify(res));
  } catch (e) {
    console.error(e);
    return fail(500, 'internal', String(e?.message || e));
  }
}
