// Fails the build when the JavaScript needed for the first screen exceeds the budget
// (Sprint 1 spec §4: initial JS < 160 KB compressed). Counts the entry chunk and its
// static imports; lazy routes are excluded.
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET_KB = 160;
const manifest = JSON.parse(readFileSync(new URL('../dist/.vite/manifest.json', import.meta.url)));
const seen = new Set();
const visit = (key) => {
  if (seen.has(key)) return;
  seen.add(key);
  for (const dep of manifest[key].imports ?? []) visit(dep);
};
visit('index.html');

let total = 0;
for (const key of seen) {
  const file = manifest[key].file;
  if (!file.endsWith('.js')) continue;
  const size = gzipSync(readFileSync(new URL(`../dist/${file}`, import.meta.url))).length / 1024;
  total += size;
  console.log(`${size.toFixed(1).padStart(7)} KB  ${file}`);
}
console.log(`${total.toFixed(1).padStart(7)} KB  initial JavaScript (gzip), budget ${BUDGET_KB} KB`);
if (total > BUDGET_KB) {
  console.error('Bundle budget exceeded.');
  process.exit(1);
}
