#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Template parity audit — detects unintentional drift between module templates
 * and theme overrides while allowing intentional delegation (include-only).
 *
 * Exit 0 when all checks pass; non-zero on drift or registry errors.
 */

$root = dirname(__DIR__, 2);
$registryPath = $root . '/mel-template-parity.json';
if (!is_readable($registryPath)) {
  fwrite(STDERR, "Missing mel-template-parity.json at repository root.\n");
  exit(1);
}

$registry = json_decode((string) file_get_contents($registryPath), TRUE);
if (!is_array($registry) || !isset($registry['pairs']) || !is_array($registry['pairs'])) {
  fwrite(STDERR, "Invalid mel-template-parity.json shape.\n");
  exit(1);
}

/**
 * Strip leading Twig comment blocks and normalise line endings.
 */
function mel_template_parity_normalise(string $contents): string {
  $contents = str_replace("\r\n", "\n", $contents);
  $contents = preg_replace('/^\s*\{#.*?#\}\s*/s', '', $contents) ?? $contents;
  return trim($contents);
}

/**
 * @return list<string>
 */
function mel_template_parity_include_only_body(string $contents): array {
  $n = mel_template_parity_normalise($contents);
  if ($n === '') {
    return [];
  }
  // Allow multiple consecutive include lines (future composition).
  if (preg_match_all('/^\{%\s*include\s+[\'"]([^\'"]+)[\'"]\s*%\}\s*$/m', $n, $m)) {
    return $m[1];
  }
  return [];
}

$failures = [];
$reports = [];
$canonical_paths = [];
$override_paths = [];

foreach ($registry['pairs'] as $index => $pair) {
  if (!is_array($pair)) {
    $failures[] = "Pair index $index is not an object.";
    continue;
  }
  $id = (string) ($pair['id'] ?? 'unknown');
  $mode = (string) ($pair['mode'] ?? '');
  $canonical = $root . '/web/modules/custom/' . ($pair['canonical_module'] ?? '') . '/' . ($pair['canonical_relative'] ?? '');
  $override = $root . '/web/themes/custom/' . ($pair['override_theme'] ?? '') . '/' . ($pair['override_relative'] ?? '');

  if (!is_readable($canonical)) {
    $failures[] = "[$id] Canonical not readable: $canonical";
    continue;
  }
  if (!is_readable($override)) {
    $failures[] = "[$id] Override not readable: $override";
    continue;
  }

  $canonical_paths[$canonical][] = $id;
  $override_paths[$override][] = $id;

  $canonicalBody = (string) file_get_contents($canonical);
  $overrideBody = (string) file_get_contents($override);

  if ($mode === 'theme_delegates_include') {
    $expected = (string) ($pair['include_target'] ?? '');
    $includes = mel_template_parity_include_only_body($overrideBody);
    if (count($includes) !== 1) {
      $failures[] = "[$id] Override must be include-only (single {% include %} after comments): $override";
      continue;
    }
    if ($includes[0] !== $expected) {
      $failures[] = "[$id] Include target mismatch.\n  Expected: $expected\n  Found: {$includes[0]}";
      continue;
    }
    $reports[] = [
      'id' => $id,
      'status' => 'ok',
      'mode' => $mode,
      'note' => 'Theme override is thin delegation to canonical module template.',
    ];
    continue;
  }

  if ($mode === 'parallel_markup') {
    $normCanonical = mel_template_parity_normalise($canonicalBody);
    $normOverride = mel_template_parity_normalise($overrideBody);
    if ($normCanonical !== $normOverride && empty($pair['intentional_divergence'])) {
      $failures[] = "[$id] Normalised markup differs between canonical and override.";
      continue;
    }
    $reports[] = [
      'id' => $id,
      'status' => 'ok',
      'mode' => $mode,
      'intentional_divergence' => (bool) ($pair['intentional_divergence'] ?? FALSE),
    ];
    continue;
  }

  $failures[] = "[$id] Unknown mode: $mode";
}

$duplicate_warnings = [];
foreach ($canonical_paths as $multi) {
  if (count($multi) > 1) {
    $duplicate_warnings[] = 'Pairs share the same canonical path (split ownership?): ' . implode(', ', array_unique($multi));
  }
}
foreach ($override_paths as $multi) {
  if (count($multi) > 1) {
    $duplicate_warnings[] = 'Pairs share the same theme override path: ' . implode(', ', array_unique($multi));
  }
}

if (isset($registry['governance_leakage_guard']) && is_array($registry['governance_leakage_guard'])) {
  $guard = $registry['governance_leakage_guard'];
  $roots = $guard['roots_relative'] ?? [];
  $needles = $guard['forbidden_substrings'] ?? [];
  if (is_array($roots) && is_array($needles) && $needles !== []) {
    foreach ($roots as $rel) {
      if (!is_string($rel) || $rel === '') {
        continue;
      }
      $dir = $root . '/' . $rel;
      if (!is_dir($dir)) {
        continue;
      }
      $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
      );
      foreach ($iterator as $file) {
        if (!$file->isFile() || !str_ends_with(strtolower($file->getFilename()), '.twig')) {
          continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        foreach ($needles as $needle) {
          if (!is_string($needle) || $needle === '') {
            continue;
          }
          if (str_contains($contents, $needle)) {
            $failures[] = 'Governance leakage guard: found forbidden substring in theme Twig: ' . $needle . ' @ ' . $file->getPathname();
          }
        }
      }
    }
  }
}

$out = [
  'tool' => 'mel-template-parity-audit',
  'generated_at' => gmdate('c'),
  'passed' => $failures === [],
  'pairs_checked' => count($registry['pairs']),
  'reports' => $reports,
  'failures' => $failures,
  'duplicate_path_warnings' => $duplicate_warnings,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit($failures === [] ? 0 : 2);
