<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\myeventlane_commerce\Service\OperationalCheckoutGovernanceManager;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OperationalCheckoutGovernanceManager
 *
 * @group myeventlane_commerce
 */
final class OperationalCheckoutGovernanceManagerTest extends UnitTestCase {

  private function manager(): OperationalCheckoutGovernanceManager {
    return new OperationalCheckoutGovernanceManager($this->getStringTranslationStub());
  }

  public function testProjectCheckoutGovernanceElevatesOnTimedConflict(): void {
    $contract = [
      'readiness_rollup' => ['blocked' => 0, 'degraded' => 0, 'authoring' => 0, 'nominal' => 1, 'unknown' => 0],
      'pickup_groups' => [],
      'hospitality_groups' => [],
      'timed_collection_groups' => [
        [
          'presentation' => ['pickup_mode' => 'conflict'],
        ],
      ],
      'continuity_groups' => [],
    ];
    $gov = $this->manager()->projectCheckoutGovernance($contract);
    $this->assertSame('elevated', $gov['severity']);
    $this->assertSame(1, $gov['timed_collection_conflicts']['conflict_row_count']);
  }

  public function testProjectCheckoutGovernanceNominalWhenClean(): void {
    $contract = [
      'readiness_rollup' => ['blocked' => 0, 'degraded' => 0, 'authoring' => 0, 'nominal' => 2, 'unknown' => 0],
      'pickup_groups' => [],
      'hospitality_groups' => [],
      'timed_collection_groups' => [],
      'continuity_groups' => [],
    ];
    $gov = $this->manager()->projectCheckoutGovernance($contract);
    $this->assertSame('nominal', $gov['severity']);
    $this->assertSame(0, $gov['incomplete_readiness']['blocked_count']);
  }

}
