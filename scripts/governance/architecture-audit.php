#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lightweight governance-specific static checks (narrow scopes to reduce noise).
 */

$root = dirname(__DIR__, 2);
$violations = [];

// Vendor-facing Twig: direct suppression conditionals are a drift signal.
$vendorTwigRoots = [
  $root . '/web/modules/custom/myeventlane_vendor',
  $root . '/web/themes/custom/myeventlane_vendor_theme',
];

foreach ($vendorTwigRoots as $dir) {
  if (!is_dir($dir)) {
    continue;
  }
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
  foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
      continue;
    }
    $contents = file_get_contents($file->getPathname());
    if ($contents === FALSE) {
      continue;
    }
    if (preg_match('/suppress_(boost|marketing|cross_sell|recommendations)/', $contents)) {
      $rel = substr($file->getPathname(), strlen($root) + 1);
      $violations[] = sprintf('%s: Possible direct suppression conditional — prefer operational policy interpretation.', $rel);
    }
  }
}

if ($violations !== []) {
  fwrite(STDERR, "MEL governance architecture audit failed:\n- " . implode("\n- ", $violations) . "\n");
  exit(1);
}

echo "MEL governance architecture audit: OK\n";
