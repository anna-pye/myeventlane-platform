<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\myeventlane_commerce\Service\OperationalCustomerGuidanceBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OperationalCustomerGuidanceBuilder
 *
 * @group myeventlane_commerce
 */
final class OperationalCustomerGuidanceBuilderTest extends UnitTestCase {

  private function builder(): OperationalCustomerGuidanceBuilder {
    return new OperationalCustomerGuidanceBuilder($this->getStringTranslationStub());
  }

  public function testBuildCustomerGuidanceIncludesPickupWhenPickupGroupsPresent(): void {
    $contract = [
      'governance' => ['severity' => 'nominal'],
      'pickup_groups' => [['product_id' => 1]],
      'hospitality_groups' => [],
      'timed_collection_groups' => [],
      'continuity_groups' => [],
    ];
    $rows = $this->builder()->buildCustomerGuidance($contract);
    $types = array_column($rows, 'type');
    $this->assertContains('pickup', $types);
  }

  public function testBuildOperationalBannersUsesGovernanceNestedCounts(): void {
    $contract = [
      'governance' => [
        'timed_collection_conflicts' => ['conflict_row_count' => 1],
        'hospitality_degradation' => ['hidden_row_count' => 0],
        'incomplete_readiness' => ['blocked_count' => 0],
      ],
    ];
    $banners = $this->builder()->buildOperationalBanners($contract);
    $this->assertNotEmpty($banners);
    $this->assertSame('warning', $banners[0]['tone']);
  }

}
