<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

/**
 * Generates secure share tokens for venues.
 */
class ShareTokenGenerator {

  /**
   * Generates a unique share token.
   *
   * @return string
   *   A 32-character hex token.
   */
  public function generate(): string {
    return bin2hex(random_bytes(16));
  }

}
