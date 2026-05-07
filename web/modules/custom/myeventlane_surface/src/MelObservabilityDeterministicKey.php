<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical sort key for merged observability traces.
 *
 * Format: {@code observability_id|code} when {@code code} is unique within the payload,
 * else {@code observability_id|code|disambiguator}. Disambiguator must be locale-invariant
 * ASCII (ids, indices, slugs) — never translated user-facing text.
 */
final class MelObservabilityDeterministicKey {

  public static function format(string $observabilityId, string $code, string $disambiguator = ''): string {
    $code = self::segment($code);
    $disambiguator = trim(self::segment($disambiguator));
    if ($disambiguator === '') {
      return self::segment($observabilityId) . '|' . $code;
    }
    return self::segment($observabilityId) . '|' . $code . '|' . $disambiguator;
  }

  private static function segment(string $part): string {
    return str_replace('|', '_', $part);
  }

}
