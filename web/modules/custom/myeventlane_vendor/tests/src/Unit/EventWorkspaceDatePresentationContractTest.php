<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards organiser/public event wall-time presentation consistency.
 *
 * @group myeventlane_vendor
 */
final class EventWorkspaceDatePresentationContractTest extends TestCase {

  public function testWorkspaceDoesNotApplyASecondTimezoneOffset(): void {
    $source = (string) file_get_contents(
      dirname(__DIR__, 3) . '/src/Service/VendorEventWorkspaceViewModelBuilder.php',
    );

    self::assertStringContainsString("strtotime(\$value . ' UTC')", $source);
    self::assertStringContainsString("->format(\$startTs, 'medium', '', 'UTC')", $source);
    self::assertStringNotContainsString("\$item->date->getTimestamp()", $source);
  }

}
