<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects aggregate organiser RSVP View presentation and isolation.
 *
 * @group myeventlane_rsvp
 */
final class VendorRsvpViewConfigTest extends TestCase {

  public function testAggregateViewShowsEventAndDefaultsToNewestFirst(): void {
    $config = (string) file_get_contents(dirname(__DIR__, 3) . '/config/install/views.view.myeventlane_vendor_rsvps.yml');

    $this->assertStringContainsString('organiser_owned:', $config);
    $this->assertStringContainsString('relationship: event_id', $config);
    $this->assertStringContainsString("empty: 'Legacy / unknown event'", $config);
    $this->assertStringContainsString('No RSVPs yet', $config);
    $this->assertStringContainsString('default_sort_order: desc', $config);
  }

}
