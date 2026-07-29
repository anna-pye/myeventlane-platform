<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects MyEventLane's calendar title and wall-clock datetime contract.
 *
 * @group myeventlane_event
 */
final class CalendarViewContractTest extends TestCase {

  public function testCalendarHasDocumentTitleAndCanonicalTimeCorrection(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $viewConfig = (string) file_get_contents($webRoot . '/../config/sync/views.view.events_calendar.yml');
    $theme = (string) file_get_contents($webRoot . '/themes/custom/myeventlane_theme/myeventlane_theme.theme');

    $this->assertStringContainsString("title: 'Events Calendar'", $viewConfig);
    $this->assertStringContainsString("\$event_times[\$entity->id()][]", $theme);
    $this->assertStringContainsString("\$event['start'] = \$canonical_times['start'];", $theme);
    $this->assertStringContainsString("\$event['end'] = \$canonical_times['end'];", $theme);
  }

}
