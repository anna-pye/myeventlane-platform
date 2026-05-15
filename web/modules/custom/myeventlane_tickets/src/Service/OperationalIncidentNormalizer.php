<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Machine-stable incident typing and severity for staff operational workspace.
 *
 * Emits lowercase tokens only (info, warning, critical) suitable for Twig/CSS
 * hooks without embedding policy semantics in templates.
 */
final class OperationalIncidentNormalizer {

  /**
   * Normalizes a free-form or legacy incident label to a machine token.
   */
  public function normalizeIncidentType(string $raw): string {
    $t = strtolower(trim($raw));
    $t = preg_replace('/[^a-z0-9_]+/', '_', $t) ?? '';
    $t = trim((string) $t, '_');
    if ($t === '') {
      return 'unknown';
    }
    return strlen($t) > 64 ? substr($t, 0, 64) : $t;
  }

  /**
   * @return 'info'|'warning'|'critical'
   */
  public function normalizeSeverity(string $raw): string {
    $s = strtolower(trim($raw));
    return match ($s) {
      'critical', 'crit', 'error', 'fatal' => 'critical',
      'warning', 'warn' => 'warning',
      'info', 'informational', 'notice', 'ok', 'success' => 'info',
      default => 'info',
    };
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array{type: string, severity: string, count: int, context: array<string, string>}
   */
  public function normalizeIncidentRow(string $type, int $count, array $row = []): array {
    $type = $this->normalizeIncidentType($type);
    $severity = $this->normalizeSeverity((string) ($row['severity'] ?? 'info'));
    $context = [];
    foreach ($row as $k => $v) {
      if ($k === 'severity') {
        continue;
      }
      if (is_scalar($v) || $v === NULL) {
        $context[(string) $k] = $v === NULL ? '' : (string) $v;
      }
    }
    return [
      'type' => $type,
      'severity' => $severity,
      'count' => max(0, $count),
      'context' => $context,
    ];
  }

}
