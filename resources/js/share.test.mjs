import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  b64urlEncode, b64urlDecode, encodeSqlParam, decodeSqlParam, MAX_LINK_SQL_BYTES, serverHint,
  withParams, sanitizeNext, swapConn, absoluteUrl, utf8Length,
} from './share.js';

const SAMPLES = [
  "SELECT 'こんにちは世界' AS greeting;",
  "SELECT 'مرحبا بالعالم' AS t, 'שלום' AS h;",
  "SELECT '😀 emoji \u{1F9D1}‍\u{1F4BB}', 'ünïcödé' FROM `täble` WHERE a > 1 AND b ~ '??>>'",
  'SELECT 1',
  '',
];

test('base64url round trip for Unicode SQL', () => {
  for (const s of SAMPLES) {
    const enc = b64urlEncode(s);
    assert.match(enc, /^[A-Za-z0-9_-]*$/);
    assert.equal(b64urlDecode(enc), s);
  }
});

test('b64urlDecode rejects garbage and invalid UTF-8', () => {
  assert.equal(b64urlDecode('a b'), null);
  assert.equal(b64urlDecode('A'), null);
  assert.equal(b64urlDecode('+/=='), null);
  assert.equal(b64urlDecode('_w'), null); // 0xFF is not valid UTF-8
});

test('encodeSqlParam / decodeSqlParam', () => {
  const r = encodeSqlParam(SAMPLES[0]);
  assert.equal(r.ok, true);
  assert.equal(decodeSqlParam(r.value), SAMPLES[0]);
  assert.deepEqual(encodeSqlParam('   '), { ok: false, reason: 'empty' });
  assert.equal(decodeSqlParam(''), null);
  assert.equal(decodeSqlParam(undefined), null);
  assert.equal(decodeSqlParam(b64urlEncode('   ')), null);
});

test('size cap counts UTF-8 bytes, not characters', () => {
  assert.equal(encodeSqlParam('a'.repeat(MAX_LINK_SQL_BYTES)).ok, true);
  const over = encodeSqlParam('a'.repeat(MAX_LINK_SQL_BYTES + 1));
  assert.equal(over.ok, false);
  assert.equal(over.reason, 'too_large');
  const jp = 'あ'.repeat(Math.floor(MAX_LINK_SQL_BYTES / 3) + 1); // 3 bytes each
  assert.ok(jp.length < MAX_LINK_SQL_BYTES && utf8Length(jp) > MAX_LINK_SQL_BYTES);
  assert.equal(encodeSqlParam(jp).ok, false);
  assert.equal(decodeSqlParam(b64urlEncode('a'.repeat(MAX_LINK_SQL_BYTES + 1))), null);
  assert.equal(decodeSqlParam(b64urlEncode('a'.repeat(MAX_LINK_SQL_BYTES))).length, MAX_LINK_SQL_BYTES);
});

test('serverHint is preset id, else driver, never host/user', () => {
  assert.equal(serverHint({ preset: 'mariadb', driver: 'mysql', host: 'db', user: 'root' }), 'mariadb');
  assert.equal(serverHint({ preset: null, driver: 'pgsql', host: 'secret.internal' }), 'pgsql');
  assert.equal(serverHint(null), '');
});

test('withParams / sanitizeNext / swapConn', () => {
  assert.equal(withParams('#/c/1/sql', { db: 'a b', q: 'xyz' }), '#/c/1/sql?db=a+b&q=xyz');
  assert.equal(withParams('#/c/1/sql?db=x&srv=m', { srv: null }), '#/c/1/sql?db=x');
  assert.equal(sanitizeNext('/c/abc/db/x'), '/c/abc/db/x');
  assert.equal(sanitizeNext('#/c/abc?x=http://a'), '/c/abc?x=http://a');
  assert.equal(sanitizeNext('//evil.com'), null);
  assert.equal(sanitizeNext('/login'), null);
  assert.equal(sanitizeNext('javascript:alert(1)'), null);
  assert.equal(swapConn('/c/old/db/x/t/y/browse?where=%5B%5D&srv=mariadb', 'new 1'), '#/c/new%201/db/x/t/y/browse?where=%5B%5D');
  assert.equal(swapConn('/nope', 'x'), null);
});

test('absoluteUrl', () => {
  assert.equal(absoluteUrl('#/c/1', { origin: 'http://h', pathname: '/', search: '' }), 'http://h/#/c/1');
  assert.equal(absoluteUrl('/c/1', { origin: 'http://h', pathname: '/o/', search: '?a=1' }), 'http://h/o/?a=1#/c/1');
});
