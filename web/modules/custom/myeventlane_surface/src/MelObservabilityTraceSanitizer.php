<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Redacts staff observability copy to categorical, explainable strings only.
 */
final class MelObservabilityTraceSanitizer {

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, mixed>>
   */
  public static function sanitizeRows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $code = isset($row['code']) && is_string($row['code']) ? $row['code'] : '';
      $message = isset($row['message']) && is_string($row['message']) ? self::sanitizeMessage($row['message']) : '';
      $oid = isset($row['observability_id']) && is_string($row['observability_id']) ? $row['observability_id'] : '';
      $key = isset($row['deterministic_key']) && is_string($row['deterministic_key']) ? $row['deterministic_key'] : '';
      $out[] = [
        'observability_id' => $oid,
        'code' => self::sanitizeCode($code),
        'message' => $message,
        'deterministic_key' => $key,
      ];
    }
    return array_values($out);
  }

  /**
   * @param list<array<string, mixed>> $registry_meta
   *
   * @return list<array<string, mixed>>
   */
  public static function sanitizeRegistryMeta(array $registry_meta): array {
    $out = [];
    foreach ($registry_meta as $row) {
      if (!is_array($row)) {
        continue;
      }
      $out[] = [
        'id' => isset($row['id']) && is_string($row['id']) ? $row['id'] : '',
        'category' => isset($row['category']) && is_string($row['category']) ? $row['category'] : '',
        'sources' => isset($row['sources']) && is_string($row['sources']) ? self::sanitizeMessage($row['sources']) : '',
        'privacy' => isset($row['privacy']) && is_string($row['privacy']) ? self::sanitizeMessage($row['privacy']) : '',
        'accessibility' => isset($row['accessibility']) && is_string($row['accessibility']) ? self::sanitizeMessage($row['accessibility']) : '',
      ];
    }
    return array_values($out);
  }

  /**
   * @param array<string, mixed> $telemetry
   *
   * @return array<string, mixed>
   */
  public static function sanitizeTelemetryReference(array $telemetry): array {
    $clean = [];
    foreach ($telemetry as $k => $v) {
      if (!is_string($k)) {
        continue;
      }
      if (is_string($v)) {
        $clean[$k] = self::sanitizeMessage($v);
      }
      elseif (is_int($v) || is_float($v) || is_bool($v)) {
        $clean[$k] = $v;
      }
    }
    return $clean;
  }

  private static function sanitizeCode(string $code): string {
    $code = preg_replace('/[^a-z0-9_.@-]/i', '_', $code) ?? '';
    return substr($code, 0, 128);
  }

  private static function sanitizeMessage(string $message): string {
    $m = $message;
    // Stripe / payment identifiers.
    $m = preg_replace('/\bacct_[A-Za-z0-9]+\b/', '[stripe_account]', $m) ?? $m;
    $m = preg_replace('/\bch_[A-Za-z0-9]+\b/', '[charge]', $m) ?? $m;
    $m = preg_replace('/\bpi_[A-Za-z0-9]+\b/', '[payment_intent]', $m) ?? $m;
    $m = preg_replace('/\bseti_[A-Za-z0-9]+\b/', '[setup_intent]', $m) ?? $m;
    $m = preg_replace('/\bsk_(live|test)_[A-Za-z0-9]+\b/i', '[secret]', $m) ?? $m;
    $m = preg_replace('/\bpk_(live|test)_[A-Za-z0-9]+\b/i', '[publishable_key]', $m) ?? $m;
    // Long digit runs (order-like, queue-like, large entity ids).
    $m = preg_replace('/\b\d{8,}\b/', '[id]', $m) ?? $m;
    // Email-shaped tokens.
    $m = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $m) ?? $m;
    return $m;
  }

}
