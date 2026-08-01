<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account_test\Controller;

/**
 * Supplies inert routes required while rendering the settings form in tests.
 */
final class TestController {

  /**
   * Returns an empty test page.
   *
   * @return array<string, mixed>
   *   An empty render array.
   */
  public function page(): array {
    return [];
  }

}
