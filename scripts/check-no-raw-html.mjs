import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const root = fileURLToPath(new URL('../docs-site/', import.meta.url));
const violations = [];

function walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(path);
      continue;
    }
    if (!entry.name.endsWith('.md')) {
      continue;
    }
    checkFile(path);
  }
}

function checkFile(path) {
  const lines = readFileSync(path, 'utf8').split(/\r?\n/);
  let fenced = false;
  lines.forEach((line, index) => {
    if (line.trim().startsWith('```')) {
      fenced = !fenced;
      return;
    }
    if (fenced) {
      return;
    }
    if (/:::\s*button\b/i.test(line)) {
      violations.push(`${path}:${index + 1}: ::: button is not allowed`);
    }
    if (/<\/?[a-z][^>]*>/i.test(line)) {
      violations.push(`${path}:${index + 1}: raw HTML is not allowed`);
    }
  });
}

walk(root);

if (violations.length > 0) {
  console.error(violations.join('\n'));
  process.exit(1);
}
