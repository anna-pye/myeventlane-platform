<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_governance\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * Ensures the public web root does not contain backup archives or phpinfo probes.
 *
 * @group myeventlane_governance
 */
final class WebrootSafetyGovernanceTest extends TestCase {

  /**
   * Directory prefixes excluded from the scan (core/contrib test fixtures).
   *
   * @var list<string>
   */
  private const EXCLUDED_PREFIXES = [
    'web/core/',
    'web/modules/contrib/',
    'web/profiles/contrib/',
    'web/themes/contrib/',
  ];

  /**
   * Unsafe filename patterns that must not appear under web/.
   */
  private const UNSAFE_PATTERNS = [
    '/\.sql$/',
    '/\.sql\.gz$/',
    '/\.tar$/',
    '/\.tar\.gz$/',
    '/\.zip$/',
    '/^phpinfo.*\.php$/',
  ];

  /**
   * Fails when backup archives, SQL dumps, or phpinfo scripts exist in web/.
   */
  public function testWebrootHasNoUnsafeFiles(): void {
    $repoRoot = dirname(__DIR__, 7);
    $webDir = $repoRoot . '/web';
    $this->assertDirectoryExists($webDir);

    $unsafe = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($webDir, \FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $fileInfo) {
      if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
        continue;
      }

      $relative = $this->relativePath($repoRoot, $fileInfo->getPathname());
      if ($this->isExcluded($relative)) {
        continue;
      }

      $basename = $fileInfo->getBasename();
      foreach (self::UNSAFE_PATTERNS as $pattern) {
        if (preg_match($pattern, $basename) === 1) {
          $unsafe[] = $relative;
          break;
        }
      }
    }

    sort($unsafe);
    $this->assertSame([], $unsafe, $unsafe === [] ? '' : "Unsafe files found in web root:\n- " . implode("\n- ", $unsafe));
  }

  private function isExcluded(string $relativePath): bool {
    foreach (self::EXCLUDED_PREFIXES as $prefix) {
      if (str_starts_with($relativePath, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function relativePath(string $repoRoot, string $absolutePath): string {
    $prefix = rtrim($repoRoot, '/') . '/';
    if (str_starts_with($absolutePath, $prefix)) {
      return substr($absolutePath, strlen($prefix));
    }
    return $absolutePath;
  }

}
