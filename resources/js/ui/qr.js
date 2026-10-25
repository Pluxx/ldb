/**
 * Compact QR Code encoder (ISO/IEC 18004): byte mode, versions 1-10, ECC L/M/Q/H.
 * Dependency-free; `document` is only touched inside qrSvg().
 */

const ECC_BITS = { L: 1, M: 0, Q: 3, H: 2 }; // format-info bits per level
const ECC_PER_BLOCK = {
  L: [7, 10, 15, 20, 26, 18, 20, 24, 30, 18],
  M: [10, 16, 26, 18, 24, 16, 18, 22, 22, 26],
  Q: [13, 22, 18, 26, 18, 24, 18, 22, 20, 24],
  H: [17, 28, 22, 16, 22, 28, 26, 26, 24, 28],
};
const NUM_BLOCKS = {
  L: [1, 1, 1, 1, 1, 2, 2, 2, 2, 4],
  M: [1, 1, 1, 2, 2, 4, 4, 4, 5, 5],
  Q: [1, 1, 2, 2, 4, 4, 6, 6, 8, 8],
  H: [1, 1, 2, 4, 4, 4, 5, 6, 8, 8],
};

// GF(256) with primitive polynomial 0x11d.
const EXP = new Array(512);
const LOG = new Array(256);
for (let i = 0, x = 1; i < 255; i++) {
  EXP[i] = EXP[i + 255] = x;
  LOG[x] = i;
  x <<= 1;
  if (x & 0x100) x ^= 0x11d;
}
const gmul = (a, b) => (a && b ? EXP[LOG[a] + LOG[b]] : 0);

/** Reed-Solomon remainder of `data` for `n` error-correction codewords. */
function rsEncode(data, n) {
  const gen = [1];
  for (let i = 0; i < n; i++) {
    const next = new Array(gen.length + 1).fill(0);
    gen.forEach((c, j) => {
      next[j] ^= c;
      next[j + 1] ^= gmul(c, EXP[i]);
    });
    gen.splice(0, gen.length, ...next);
  }
  const rem = new Array(n).fill(0);
  for (const b of data) {
    const f = b ^ rem.shift();
    rem.push(0);
    for (let i = 0; i < n; i++) rem[i] ^= gmul(gen[i + 1], f);
  }
  return rem;
}

/** Alignment pattern centre coordinates for a version. */
function alignPositions(ver) {
  if (ver === 1) return [];
  const n = Math.floor(ver / 7) + 2;
  const size = ver * 4 + 17;
  const step = Math.ceil((ver * 4 + 4) / (n * 2 - 2)) * 2;
  const pos = [6];
  for (let p = size - 7, i = n - 1; i > 0; i--, p -= step) pos.splice(1, 0, p);
  return pos;
}

/** Number of data+ECC modules (all modules minus function patterns). */
function rawModules(ver) {
  let r = (16 * ver + 128) * ver + 64;
  if (ver >= 2) {
    const n = Math.floor(ver / 7) + 2;
    r -= (25 * n - 10) * n - 55;
    if (ver >= 7) r -= 36;
  }
  return r;
}

const MASKS = [
  (x, y) => (x + y) % 2 === 0,
  (x, y) => y % 2 === 0,
  (x, y) => x % 3 === 0,
  (x, y) => (x + y) % 3 === 0,
  (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
  (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
  (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0,
  (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
];

/** 15-bit format information (BCH(15,5), XOR 0x5412). */
function formatBits(ecl, mask) {
  const data = (ECC_BITS[ecl] << 3) | mask;
  let rem = data;
  for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >> 9) * 0x537);
  return ((data << 10) | rem) ^ 0x5412;
}

/** 18-bit version information (BCH(18,6)), versions >= 7. */
function versionBits(ver) {
  let rem = ver;
  for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >> 11) * 0x1f25);
  return (ver << 12) | rem;
}

/** Build the final codeword sequence (data + ECC, interleaved). */
function codewords(bytes, ver, ecl) {
  const eccLen = ECC_PER_BLOCK[ecl][ver - 1];
  const nb = NUM_BLOCKS[ecl][ver - 1];
  const raw = Math.floor(rawModules(ver) / 8);
  const dataCap = raw - eccLen * nb;
  const bits = [];
  const put = (v, n) => {
    for (let i = n - 1; i >= 0; i--) bits.push((v >>> i) & 1);
  };
  put(0b0100, 4);
  put(bytes.length, ver < 10 ? 8 : 16);
  bytes.forEach((b) => put(b, 8));
  put(0, Math.min(4, dataCap * 8 - bits.length));
  while (bits.length % 8) bits.push(0);
  const data = [];
  for (let i = 0; i < bits.length; i += 8) data.push(parseInt(bits.slice(i, i + 8).join(''), 2));
  for (let pad = 0xec; data.length < dataCap; pad ^= 0xec ^ 0x11) data.push(pad);

  const numShort = nb - (raw % nb);
  const shortLen = Math.floor(raw / nb) - eccLen;
  const blocks = [];
  for (let i = 0, k = 0; i < nb; i++) {
    const d = data.slice(k, (k += shortLen + (i < numShort ? 0 : 1)));
    blocks.push({ d, e: rsEncode(d, eccLen) });
  }
  const out = [];
  for (let i = 0; i <= shortLen; i++) blocks.forEach((b) => i < b.d.length && out.push(b.d[i]));
  for (let i = 0; i < eccLen; i++) blocks.forEach((b) => out.push(b.e[i]));
  return out;
}

/** Penalty score using the four standard rules (N1=3, N2=3, N3=40, N4=10). */
function penalty(m) {
  const n = m.length;
  let score = 0;
  let dark = 0;
  const pat = [1, 0, 1, 1, 1, 0, 1];
  const lines = [];
  for (let i = 0; i < n; i++) lines.push(m[i], m.map((row) => row[i]));
  for (const line of lines) {
    for (let i = 0, run = 1; i < n; i++) {
      if (i > 0 && line[i] === line[i - 1]) {
        run++;
        if (run === 5) score += 3;
        else if (run > 5) score++;
      } else run = 1;
    }
    for (let i = 0; i + 7 <= n; i++) {
      if (!pat.every((p, j) => (line[i + j] ? 1 : 0) === p)) continue;
      const lightAt = (a, b) => {
        for (let j = a; j < b; j++) if (j >= 0 && j < n && line[j]) return false;
        return true;
      };
      if ((i >= 4 && lightAt(i - 4, i)) || (i + 7 + 4 <= n && lightAt(i + 7, i + 11))) score += 40;
    }
  }
  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      if (m[y][x]) dark++;
      if (x + 1 < n && y + 1 < n && m[y][x] === m[y][x + 1] && m[y][x] === m[y + 1][x] && m[y][x] === m[y + 1][x + 1]) score += 3;
    }
  }
  return score + (Math.ceil(Math.abs(dark * 20 - n * n * 10) / (n * n)) - 1) * 10;
}

/**
 * Encode text as a QR Code symbol.
 * @param {string} text UTF-8 text (byte mode)
 * @param {{ecl?: 'L'|'M'|'Q'|'H', mask?: number|null}} [opts] mask 0-7 forces a mask pattern
 * @returns {{version: number, size: number, mask: number, ecl: string, modules: boolean[][]}}
 */
export function encodeQR(text, { ecl = 'M', mask = null } = {}) {
  if (!(ecl in ECC_BITS)) throw new Error('qr: bad ecl');
  const bytes = Array.from(new TextEncoder().encode(text));
  let ver = 1;
  const fits = (v) => 4 + (v < 10 ? 8 : 16) + bytes.length * 8 <= (Math.floor(rawModules(v) / 8) - ECC_PER_BLOCK[ecl][v - 1] * NUM_BLOCKS[ecl][v - 1]) * 8;
  while (ver <= 10 && !fits(ver)) ver++;
  if (ver > 10) throw new Error('qr: data too long');

  const size = ver * 4 + 17;
  const grid = Array.from({ length: size }, () => new Array(size).fill(false));
  const fn = Array.from({ length: size }, () => new Array(size).fill(false));
  const set = (x, y, dark) => {
    if (x < 0 || y < 0 || x >= size || y >= size) return;
    grid[y][x] = dark;
    fn[y][x] = true;
  };
  for (let i = 0; i < size; i++) {
    set(6, i, i % 2 === 0);
    set(i, 6, i % 2 === 0);
  }
  for (const [cx, cy] of [[3, 3], [size - 4, 3], [3, size - 4]]) {
    for (let dy = -4; dy <= 4; dy++) {
      for (let dx = -4; dx <= 4; dx++) {
        const d = Math.max(Math.abs(dx), Math.abs(dy));
        set(cx + dx, cy + dy, d !== 2 && d !== 4);
      }
    }
  }
  const ap = alignPositions(ver);
  ap.forEach((cy, i) => ap.forEach((cx, j) => {
    if ((i === 0 && j === 0) || (i === 0 && j === ap.length - 1) || (i === ap.length - 1 && j === 0)) return;
    for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(cx + dx, cy + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
  }));
  const drawFormat = (g, mk) => {
    const bits = formatBits(ecl, mk);
    const b = (i) => ((bits >>> i) & 1) === 1;
    for (let i = 0; i <= 5; i++) g[i][8] = b(i);
    g[7][8] = b(6);
    g[8][8] = b(7);
    g[8][7] = b(8);
    for (let i = 9; i < 15; i++) g[8][14 - i] = b(i);
    for (let i = 0; i < 8; i++) g[8][size - 1 - i] = b(i);
    for (let i = 8; i < 15; i++) g[size - 15 + i][8] = b(i);
    g[size - 8][8] = true; // dark module
  };
  drawFormat(grid, 0); // reserves the format areas (rewritten per mask below)
  for (let i = 0; i < 9; i++) fn[i][8] = fn[8][i] = true;
  for (let i = 0; i < 8; i++) fn[8][size - 1 - i] = fn[size - 1 - i][8] = true;
  fn[size - 8][8] = true;
  if (ver >= 7) {
    const bits = versionBits(ver);
    for (let i = 0; i < 18; i++) {
      const dark = ((bits >>> i) & 1) === 1;
      const a = size - 11 + (i % 3);
      const b = Math.floor(i / 3);
      set(a, b, dark);
      set(b, a, dark);
    }
  }

  const cw = codewords(bytes, ver, ecl);
  let bit = 0;
  for (let right = size - 1; right >= 1; right -= 2) {
    if (right === 6) right = 5;
    for (let v = 0; v < size; v++) {
      for (let j = 0; j < 2; j++) {
        const x = right - j;
        const y = ((right + 1) & 2) === 0 ? size - 1 - v : v;
        if (fn[y][x]) continue;
        grid[y][x] = bit < cw.length * 8 && ((cw[bit >>> 3] >>> (7 - (bit & 7))) & 1) === 1;
        bit++;
      }
    }
  }

  const build = (mk) => {
    const g = grid.map((row, y) => row.map((d, x) => (fn[y][x] ? d : d !== MASKS[mk](x, y))));
    drawFormat(g, mk);
    return g;
  };
  let best = mask;
  if (best === null) {
    let lowest = Infinity;
    for (let mk = 0; mk < 8; mk++) {
      const p = penalty(build(mk));
      if (p < lowest) {
        lowest = p;
        best = mk;
      }
    }
  } else if (!Number.isInteger(best) || best < 0 || best > 7) throw new Error('qr: bad mask');
  return { version: ver, size, mask: best, ecl, modules: build(best) };
}

/**
 * SVG path data for all dark modules (one rect per horizontal run).
 * @param {{size: number, modules: boolean[][]}} qr
 * @param {number} [border] quiet zone in modules
 * @returns {string}
 */
export function qrPath(qr, border = 4) {
  const parts = [];
  qr.modules.forEach((row, y) => {
    for (let x = 0; x < qr.size; x++) {
      if (!row[x]) continue;
      let n = 1;
      while (x + n < qr.size && row[x + n]) n++;
      parts.push(`M${x + border} ${y + border}h${n}v1h-${n}z`);
      x += n;
    }
  });
  return parts.join('');
}

/**
 * Build an accessible dark-on-light SVG element for the text.
 * @param {string} text
 * @param {{ecl?: string, border?: number, label?: string}} [opts]
 * @returns {SVGSVGElement}
 */
export function qrSvg(text, { ecl = 'M', border = 4, label = '' } = {}) {
  const qr = encodeQR(text, { ecl });
  const NS = 'http://www.w3.org/2000/svg';
  const total = qr.size + 2 * border;
  const el = (name, attrs) => {
    const node = document.createElementNS(NS, name);
    for (const k in attrs) node.setAttribute(k, attrs[k]);
    return node;
  };
  const svg = el('svg', { viewBox: `0 0 ${total} ${total}`, 'shape-rendering': 'crispEdges', role: 'img', class: 'block h-auto w-full' });
  if (label) svg.setAttribute('aria-label', label);
  svg.append(el('rect', { width: total, height: total, fill: '#fff' }), el('path', { d: qrPath(qr, border), fill: '#000' }));
  return svg;
}
