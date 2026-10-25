/**
 * JS build for LDB: esbuild bundle with code splitting.
 *   node resources/build.mjs          production build (minified)
 *   node resources/build.mjs --watch  rebuild on change
 * Output (public/assets/dist): app.js (entry, stable name), chunks/*.js (lazy views, locales,
 * dev mock), theme.js (pre-paint bootstrap, copied verbatim), favicon.svg.
 */
import * as esbuild from 'esbuild';
import { copyFileSync, mkdirSync, rmSync } from 'node:fs';

const watch = process.argv.includes('--watch');
const outdir = 'public/assets/dist';

rmSync(`${outdir}/chunks`, { recursive: true, force: true });
mkdirSync(outdir, { recursive: true });

const copyStatic = {
  name: 'copy-static',
  setup(build) {
    build.onEnd(() => {
      copyFileSync('resources/js/theme-boot.js', `${outdir}/theme.js`);
      copyFileSync('resources/favicon.svg', `${outdir}/favicon.svg`);
    });
  },
};

const options = {
  entryPoints: { app: 'resources/js/app.js' },
  bundle: true,
  format: 'esm',
  splitting: true,
  outdir,
  entryNames: '[name]',
  chunkNames: 'chunks/[name]-[hash]',
  minify: !watch,
  charset: 'utf8', // keep CJK/Cyrillic/Arabic text as UTF-8 instead of \uXXXX escapes (~2x smaller locale chunks)
  sourcemap: false,
  target: ['es2020', 'chrome100', 'firefox100', 'safari15'],
  legalComments: 'none',
  metafile: true,
  logLevel: 'info',
  plugins: [copyStatic],
};

if (watch) {
  const ctx = await esbuild.context(options);
  await ctx.watch();
} else {
  const result = await esbuild.build(options);
  const text = await esbuild.analyzeMetafile(result.metafile, { verbose: false });
  if (process.env.ANALYZE) console.log(text);
}
