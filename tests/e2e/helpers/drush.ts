import { execSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '../../..');

export interface DrushPhpResult {
  stdout: string;
  stderr: string;
  status: number;
}

export function runDrushPhp(
  scriptPath: string,
  env: Record<string, string> = {},
): string {
  const result = runDrushPhpRaw(scriptPath, env);
  if (result.status !== 0) {
    const details = [result.stderr, result.stdout].filter(Boolean).join('\n').trim();
    throw new Error(
      details
        ? `Drush script failed (${scriptPath}, exit ${result.status}): ${details}`
        : `Drush script failed (${scriptPath}, exit ${result.status})`,
    );
  }
  return result.stdout;
}

export function runDrushPhpRaw(
  scriptPath: string,
  env: Record<string, string> = {},
): DrushPhpResult {
  const envPrefix = Object.entries(env)
    .map(([key, value]) => `${key}=${shellQuote(value)}`)
    .join(' ');
  const command = envPrefix
    ? `ddev exec env ${envPrefix} drush php:script ${scriptPath}`
    : `ddev drush php:script ${scriptPath}`;
  try {
    return {
      stdout: execSync(command, {
        cwd: repoRoot,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
      }).trim(),
      stderr: '',
      status: 0,
    };
  }
  catch (error) {
    if (!isExecSyncError(error)) {
      throw error;
    }
    return {
      stdout: String(error.stdout ?? '').trim(),
      stderr: String(error.stderr ?? '').trim(),
      status: typeof error.status === 'number' ? error.status : 1,
    };
  }
}

const DDEV_UNAVAILABLE_PATTERNS = [
  /project ['"]?[\w.-]+['"]? is not running/i,
  /project is not yet running/i,
  /use 'ddev start' first/i,
  /no running container found/i,
  /failed to start [\w.-]+:/i,
  /cannot connect to the docker daemon/i,
  /could not connect to docker/i,
  /docker is not running/i,
  /(?:^|\s)ddev(?:\s|:).*command not found/i,
  /command not found.*(?:^|\s)ddev(?:\s|$)/i,
];

/** Drush/Drupal failures that prove the DDEV container ran. */
const INNER_COMMAND_FAILURE_PATTERNS = [
  /failed to run drush/i,
  /failed to execute command/i,
  /could not connect to (?:database|mysql|mariadb|postgres|db)\b/i,
  /sqlstate\[/i,
];

export function isDdevUnavailable(
  result: Pick<DrushPhpResult, 'stderr' | 'stdout' | 'status'>,
): boolean {
  const stderr = result.stderr;
  if (stderr === '') {
    return false;
  }

  // Script stdout means DDEV reached the container and Drush bootstrapped Drupal.
  if (result.stdout.trim() !== '') {
    return false;
  }

  if (INNER_COMMAND_FAILURE_PATTERNS.some((pattern) => pattern.test(stderr))) {
    return false;
  }

  return DDEV_UNAVAILABLE_PATTERNS.some((pattern) => pattern.test(stderr));
}

function isExecSyncError(
  error: unknown,
): error is Error & { status?: number; stdout?: string | Buffer; stderr?: string | Buffer } {
  return error instanceof Error && 'status' in error;
}

function shellQuote(value: string): string {
  return `'${value.replace(/'/g, `'\\''`)}'`;
}

export function runDrush(command: string): string {
  return execSync(`ddev drush ${command}`, {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}
