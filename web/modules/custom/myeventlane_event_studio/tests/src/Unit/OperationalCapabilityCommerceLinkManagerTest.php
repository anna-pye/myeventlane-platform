<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager
 *
 * @group myeventlane_event_studio
 */
final class OperationalCapabilityCommerceLinkManagerTest extends UnitTestCase {

  /**
   * @covers ::emptyLinkageTemplate
   */
  public function testEmptyLinkageTemplateShape(): void {
    $empty = OperationalCapabilityCommerceLinkManager::emptyLinkageTemplate();
    $this->assertSame(0, $empty['product_id']);
    $this->assertSame([], $empty['variation_ids']);
    $this->assertArrayHasKey('readiness_projection', $empty);
    $this->assertArrayHasKey('capability_binding_state', $empty);
  }

}
