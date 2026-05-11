<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Readiness;

/**
 * Defines severity levels for future domain-owned readiness providers.
 */
enum ReadinessSeverity: string {

  case Error = 'error';
  case Warning = 'warning';
  case Completed = 'completed';

  /**
   * Returns TRUE when this severity blocks publishing.
   */
  public function isBlocking(): bool {
    return $this === self::Error;
  }

}
