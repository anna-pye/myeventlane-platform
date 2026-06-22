import { runDrushPhp } from './helpers/drush.js';

export default async function globalTeardown(): Promise<void> {
  try {
    runDrushPhp('tests/e2e/scripts/restore-payment-gateways.php');
  }
  catch {
    // Non-fatal if DDEV is not running after local dev.
  }
}
