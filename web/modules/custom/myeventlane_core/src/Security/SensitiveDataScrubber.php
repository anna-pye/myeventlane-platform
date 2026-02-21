<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Security;

/**
 * Scrubs sensitive card data from arrays before logging or storage.
 *
 * Recursively removes keys that could contain raw PAN, CVC, or expiry
 * values. Used as a defensive layer to prevent accidental logging of
 * card data even if upstream code changes.
 */
final class SensitiveDataScrubber {

  /**
   * Keys that must never appear in log context or stored data.
   *
   * Covers Stripe API parameter names, common form field names, and
   * variations seen in payment integrations.
   */
  private const SENSITIVE_KEYS = [
    'number',
    'card_number',
    'card[number]',
    'pan',
    'cvc',
    'cvv',
    'cvc2',
    'cvv2',
    'security_code',
    'exp_month',
    'exp_year',
    'expiry',
    'expiration',
    'card_exp',
    'card_cvc',
    'card_cvv',
  ];

  /**
   * Scrubs sensitive keys from a context array.
   *
   * @param array $context
   *   The array to scrub (e.g., logger context, API params).
   *
   * @return array
   *   The scrubbed array with sensitive values replaced by '[REDACTED]'.
   */
  public static function scrub(array $context): array {
    return self::scrubRecursive($context);
  }

  /**
   * Recursively processes the array, redacting sensitive keys.
   */
  private static function scrubRecursive(array $data): array {
    $scrubbed = [];

    foreach ($data as $key => $value) {
      $normalised = strtolower(str_replace(['-', ' '], '_', (string) $key));

      if (in_array($normalised, self::SENSITIVE_KEYS, TRUE)) {
        $scrubbed[$key] = '[REDACTED]';
        continue;
      }

      if (is_array($value)) {
        $scrubbed[$key] = self::scrubRecursive($value);
      }
      else {
        $scrubbed[$key] = $value;
      }
    }

    return $scrubbed;
  }

  /**
   * Checks if any sensitive card field names exist in an array's keys.
   *
   * @param array $data
   *   The data to inspect (e.g., request POST body).
   *
   * @return bool
   *   TRUE if any sensitive key is found at any depth.
   */
  public static function containsSensitiveKeys(array $data): bool {
    return self::detectRecursive($data);
  }

  /**
   * Recursively detects sensitive keys.
   */
  private static function detectRecursive(array $data): bool {
    foreach ($data as $key => $value) {
      $normalised = strtolower(str_replace(['-', ' '], '_', (string) $key));

      if (in_array($normalised, self::SENSITIVE_KEYS, TRUE)) {
        return TRUE;
      }

      if (is_array($value) && self::detectRecursive($value)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
