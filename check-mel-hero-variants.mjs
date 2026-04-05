import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = process.cwd();
const targetFile = path.join(root, 'src/scss/components/_event-hero.scss');
const source = await readFile(targetFile, 'utf8');
const matches = [...source.matchAll(/mel-event-hero--([a-z0-9-]+)/g)].map((match) => match[0]);
const variants = [...new Set(matches)];
const allowed = 'mel-event-hero--featured-style';
const invalid = variants.filter((variant) => variant !== allowed);

if (invalid.length > 0) {
  console.error(`Only ${allowed} is allowed in the locked MEL hero surface.`);
  console.error(`Found disallowed variants: ${invalid.join(', ')}`);
  process.exit(1);
}

if (!variants.includes(allowed)) {
  console.error(`Expected to find ${allowed} in src/scss/components/_event-hero.scss.`);
  process.exit(1);
}

console.log(`MEL hero check passed: ${allowed} is the only locked hero variant.`);
