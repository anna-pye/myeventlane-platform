<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use Drupal\myeventlane_commerce\Service\OperationalMerchandiseGovernanceManager;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_commerce\Service\OperationalMerchandiseGovernanceManager
 *
 * @group myeventlane_commerce
 */
final class OperationalMerchandiseGovernanceManagerTest extends UnitTestCase {

  private function governance(): OperationalMerchandiseGovernanceManager {
    $etm = $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $merch = new OperationalMerchandiseManager(
      $etm,
      $this->getStringTranslationStub(),
      $logger,
    );
    return new OperationalMerchandiseGovernanceManager(
      $merch,
      $this->getStringTranslationStub(),
    );
  }

  public function testProjectGovernanceElevatesOnPickupConflict(): void {
    $presentations = [
      [
        'readiness_mode' => 'nominal',
        'operational_product_type' => 'merch_pickup',
        'pickup_mode' => 'conflict',
        'customer_visibility' => 'visible',
      ],
    ];
    $gov = $this->governance()->projectGovernance($presentations);
    $this->assertSame('elevated', $gov['severity']);
    $this->assertSame(1, $gov['continuity_conflict_rows']);
  }

  public function testProjectAuthoringGovernanceUsesCustomerSafePayloads(): void {
    $authoring = [
      'linked_products' => [
        [
          'product_payload' => [
            'operational_product_type' => 'hospitality_package',
            'operational_summary' => 'Suite',
            'customer_visibility' => 'hidden',
            'readiness_mode' => 'blocked',
            'pickup_mode' => 'none',
            'qr_payload' => 'must-not-count',
          ],
        ],
      ],
    ];
    $gov = $this->governance()->projectAuthoringGovernance($authoring);
    $this->assertSame('elevated', $gov['severity']);
    $this->assertSame(1, $gov['pickup_degraded_rows']);
    $this->assertSame(1, $gov['hospitality_degraded_rows']);
    $this->assertSame(0, $gov['continuity_conflict_rows']);
  }

}
