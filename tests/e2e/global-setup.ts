import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runDrushPhp } from './helpers/drush.js';

export default async function globalSetup(): Promise<void> {
  const __dirname = dirname(fileURLToPath(import.meta.url));
  const fixturesDir = join(__dirname, 'fixtures');
  mkdirSync(fixturesDir, { recursive: true });

  const raw = runDrushPhp('tests/e2e/scripts/prepare-fixture.php');
  const env = JSON.parse(raw) as Record<string, string | number>;

  writeFileSync(join(fixturesDir, 'runtime-env.json'), JSON.stringify(env, null, 2));

  process.env.MEL_E2E_BASE_URL = String(env.baseUrl);
  process.env.MEL_E2E_EVENT_PATH = String(env.eventPath);
  process.env.MEL_E2E_BOOK_PATH = String(env.bookPath);
  process.env.MEL_E2E_BUYER_EMAIL = String(env.buyerEmail);
  process.env.MEL_E2E_PAYMENT_MODE = String(env.paymentMode);

  // eslint-disable-next-line no-console
  console.log(`E2E fixture ready: ${env.eventTitle} (${env.bookPath}), payment=${env.paymentMode}`);
}
