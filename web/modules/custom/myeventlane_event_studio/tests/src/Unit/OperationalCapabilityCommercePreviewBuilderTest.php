<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommercePreviewBuilder;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommercePreviewBuilder
 *
 * @group myeventlane_event_studio
 */
final class OperationalCapabilityCommercePreviewBuilderTest extends UnitTestCase {

  private function linkManager(): OperationalCapabilityCommerceLinkManager {
    $registry = new EntitlementCapabilityRegistry();
    $logger = new TestLoggerChannel();
    $reservation = new InventoryReservationGovernanceManager($registry, $logger);
    $oecm = new OperationalEntitlementCapabilityManager($registry, $reservation, $logger);
    $fulfillment = new FulfillmentLifecycleManager($registry);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $currentUser = $this->createMock(AccountProxyInterface::class);
    return new OperationalCapabilityCommerceLinkManager($etm, $currentUser, $registry, $oecm, $fulfillment, $reservation);
  }

  /**
   * @covers ::buildCustomerSafeLinkageLines
   */
  public function testCustomerSafeLinkageLinesUsesLinkManager(): void {
    $link = $this->linkManager();
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $builder = new OperationalCapabilityCommercePreviewBuilder($link, $etm, $this->getStringTranslationStub());
    $lines = $builder->buildCustomerSafeLinkageLines('merch_pickup', [
      'capability_binding_state' => OperationalCapabilityCommerceLinkManager::BINDING_BOUND,
      'linkage_mode' => OperationalCapabilityCommerceLinkManager::LINKAGE_PRODUCT,
      'product_id' => 1,
      'fulfillment_reference' => 'merch_pickup',
      'reservation_reference' => 'merch',
    ]);
    $this->assertNotEmpty($lines);
  }

}
