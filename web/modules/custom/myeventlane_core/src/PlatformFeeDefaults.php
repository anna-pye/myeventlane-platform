<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

/**
 * Single source of truth for platform fee fallbacks when config is missing.
 *
 * Canonical values live in config (myeventlane_core.settings). Use this only
 * for ?? fallbacks in PHP so defaults cannot drift across modules.
 */
final class PlatformFeeDefaults {

  /**
   * Default platform fee as a percentage (e.g. 1.5 means 1.5%).
   */
  public const DEFAULT_PLATFORM_FEE_PERCENT = 1.5;

  /**
   * Resolves raw config (or default) to a clamped, rounded percentage (0–100).
   */
  public static function normalizePercent(mixed $raw): float {
    $v = is_numeric($raw) ? (float) $raw : self::DEFAULT_PLATFORM_FEE_PERCENT;
    return round(max(0.0, min(100.0, $v)), 2);
  }

}
