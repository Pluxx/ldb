"""Compare locale key sets and {placeholders} against en.js. Usage: python3 check_i18n.py [--strict] <locales_dir> [code...]
--strict: exit 1 if any locale has missing keys or placeholder mismatches (extra keys allowed)."""
import re, sys, pathlib
args = [a for a in sys.argv[1:] if a != '--strict']
strict = len(args) != len(sys.argv) - 1
d = pathlib.Path(args[0])
pat = re.compile(r"""^\s*['"]([\w.\-]+)['"]\s*:\s*(['"`])((?:\\.|(?!\2).)*)\2""", re.M)
def load(p): return {k: v for k, _, v in pat.findall(p.read_text(encoding='utf-8'))}
en = load(d / 'en.js')
codes = args[1:] or [p.stem for p in d.glob('*.js') if p.stem != 'en']
print(f"en: {len(en)} keys")
bad = 0
for c in codes:
    loc = load(d / f'{c}.js')
    missing = [k for k in en if k not in loc]
    extra = [k for k in loc if k not in en]
    ph = [k for k in en if k in loc and set(re.findall(r'\{\w+\}', en[k])) != set(re.findall(r'\{\w+\}', loc[k]))]
    print(f"{c}: {len(loc)} keys, missing {len(missing)}, extra {len(extra)}, placeholder mismatches {len(ph)}")
    if missing or ph: bad += 1
    for k in (missing[:10] + ph[:10]): print("   ", k)
if strict and bad:
    print(f"FAIL: {bad} locale(s) with missing keys or placeholder mismatches", file=sys.stderr)
    sys.exit(1)
