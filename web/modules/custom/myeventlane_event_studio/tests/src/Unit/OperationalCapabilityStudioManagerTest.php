<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\OperationalCapabilityStudioManager
 *
 * @group myeventlane_event_studio
 */
final class OperationalCapabilityStudioManagerTest extends UnitTestCase {

  /**
   * @covers ::normalizeDocument
   */
  public function testNormalizeStripsForbiddenKeys(): void {
    $manager = OperationalCapabilityStudioTestFactory::buildManager();
    $doc = $manager->normalizeDocument([
      'capabilities' => [
        OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP => [
          'enabled' => TRUE,
          'replay_token' => 'secret',
          'fulfillment_mode' => 'collect',
        ],
      ],
    ]);
    $row = $doc['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP];
    $this->assertTrue($row['enabled']);
    $this->assertArrayNotHasKey('replay_token', $row);
    $this->assertSame('collect', $row['fulfillment_mode']);
  }

  /**
   * @covers ::buildCustomerSafePreviewSummary
   */
  public function testCustomerPreviewOmitsHiddenCapabilities(): void {
    $manager = OperationalCapabilityStudioTestFactory::buildManager();
    $doc = $manager->normalizeDocument([
      'capabilities' => [
        OperationalEntitlementCapabilityManager::TYPE_VIP_ACCESS => [
          'enabled' => TRUE,
          'customer_visibility' => 'hidden',
        ],
      ],
    ]);
    $summary = $manager->buildCustomerSafePreviewSummary(
      OperationalEntitlementCapabilityManager::TYPE_VIP_ACCESS,
      $doc['capabilities'][OperationalEntitlementCapabilityManager::TYPE_VIP_ACCESS],
    );
    $this->assertSame('', $summary);
  }

  /**
   * @covers ::projectPolicySemantics
   */
  public function testPolicyProjectionDelegatesToRegistry(): void {
    $manager = OperationalCapabilityStudioTestFactory::buildManager();
    $semantics = $manager->projectPolicySemantics(
      OperationalEntitlementCapabilityManager::TYPE_ADMISSION,
      ['enabled' => TRUE],
    );
    $this->assertSame(Ticket::ENTITLEMENT_TICKET, $semantics['entitlement_type']);
    $this->assertSame('none', $semantics['fulfillment_mode']);
  }

}
