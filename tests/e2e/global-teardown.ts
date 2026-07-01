import { isDdevUnavailable, runDrushPhpRaw } from './helpers/drush.js';

interface RestoreResult {
  restored?: boolean;
  reason?: string;
  gateways?: string[];
}

function parseRestoreResult(stdout: string): RestoreResult | null {
  if (stdout === '') {
    return null;
  }
  try {
    return JSON.parse(stdout) as RestoreResult;
  }
  catch {
    return null;
  }
}

export default async function globalTeardown(): Promise<void> {
  const result = runDrushPhpRaw('tests/e2e/scripts/restore-payment-gateways.php');

  if (isDdevUnavailable(result)) {
    // eslint-disable-next-line no-console
    console.warn(
      'E2E teardown: skipped payment gateway restore because DDEV is not running. '
      + 'If tests used manual payment mode, run restore manually when DDEV is back up.',
    );
    return;
  }

  const restore = parseRestoreResult(result.stdout);

  if (result.status === 0 && restore?.restored) {
    // eslint-disable-next-line no-console
    console.log(
      `E2E teardown: restored payment gateways (${restore.gateways?.join(', ') ?? 'unknown'}).`,
    );
    return;
  }

  if (result.status === 0 && restore?.reason === 'no state file') {
    return;
  }

  if (result.status === 0 && restore?.reason === 'empty state') {
    // eslint-disable-next-line no-console
    console.warn('E2E teardown: payment gateway state file was empty; nothing to restore.');
    return;
  }

  const detail = [result.stderr, result.stdout].filter(Boolean).join('\n').trim();
  // eslint-disable-next-line no-console
  console.error(
    'E2E teardown: failed to restore payment gateways. '
    + 'Stripe gateways may still be disabled on this DDEV site.',
  );
  if (detail) {
    // eslint-disable-next-line no-console
    console.error(detail);
  }
}
