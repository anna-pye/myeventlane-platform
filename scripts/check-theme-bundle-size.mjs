import { readFile, stat } from 'node:fs/promises';
import path from 'node:path';

const themeRoot = process.cwd();
const distRoot = path.join(themeRoot, 'dist');
const manifestPath = path.join(distRoot, '.vite', 'manifest.json');
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));

const budgets = [
  {
    label: 'global CSS',
    relativePath: manifest['js/main.js']?.css?.[0],
    maximumBytes: 1_075_000,
  },
  {
    label: 'Commerce CSS',
    relativePath: manifest['scss/commerce.scss']?.file,
    maximumBytes: 125_000,
  },
];

let failed = false;
for (const budget of budgets) {
  if (!budget.relativePath) {
    console.error(`Missing ${budget.label} entry in ${manifestPath}.`);
    failed = true;
    continue;
  }

  const file = path.join(distRoot, budget.relativePath);
  const { size } = await stat(file);
  const sizeKiB = (size / 1024).toFixed(1);
  const maximumKiB = (budget.maximumBytes / 1024).toFixed(1);
  console.log(`${budget.label}: ${sizeKiB} KiB (budget ${maximumKiB} KiB)`);

  if (size > budget.maximumBytes) {
    console.error(`${budget.label} exceeds its production size budget.`);
    failed = true;
  }
}

if (failed) {
  process.exit(1);
}
