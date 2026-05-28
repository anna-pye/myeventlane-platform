<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_studio\Service\CustomerOperationalCommerceExperienceBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommercePreviewBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityPreviewBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityStudioManager;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\CustomerOperationalCommerceExperienceBuilder
 *
 * @group myeventlane_event_studio
 */
final class CustomerOperationalCommerceExperienceBuilderTest extends UnitTestCase {

  private function createBuilder(): CustomerOperationalCommerceExperienceBuilder {
    $studio = OperationalCapabilityStudioTestFactory::buildManager();
    $registry = new EntitlementCapabilityRegistry();
    $logger = new TestLoggerChannel();
    $reservation = new InventoryReservationGovernanceManager($registry, $logger);
    $oecm = new OperationalEntitlementCapabilityManager($registry, $reservation, $logger);
    $fulfillment = new FulfillmentLifecycleManager($registry);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $link = new OperationalCapabilityCommerceLinkManager($etm, $currentUser, $registry, $oecm, $fulfillment, $reservation);
    $commercePreview = new OperationalCapabilityCommercePreviewBuilder($link, $etm, $this->getStringTranslationStub());
    $preview = new OperationalCapabilityPreviewBuilder($studio, $commercePreview, $this->getStringTranslationStub());

    return new CustomerOperationalCommerceExperienceBuilder(
      $link,
      $preview,
      $studio,
      $registry,
      $fulfillment,
      $reservation,
      $etm,
      $this->getStringTranslationStub(),
    );
  }

  /**
   * @covers ::buildFromOperationalDocument
   */
  public function testBuildFromOperationalDocumentProducesCustomerContract(): void {
    $builder = $this->createBuilder();
    $studio = OperationalCapabilityStudioTestFactory::buildManager();
    $doc = $studio->emptyDocument();
    $doc['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP] = array_merge(
      $doc['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP],
      [
        'enabled' => TRUE,
        'customer_visibility' => 'after_purchase',
        'readiness_state' => OperationalCapabilityStudioManager::READINESS_CONFIGURED,
        'timed_entry' => FALSE,
      ],
    );

    $out = $builder->buildFromOperationalDocument($doc);
    $this->assertTrue($out['customer_operational_experience']);
    $this->assertFalse($out['empty']);
    $this->assertNotEmpty($out['items']);
    $first = $out['items'][0];
    $this->assertArrayHasKey('capability_label', $first);
    $this->assertArrayHasKey('operational_chips', $first);
  }

  /**
   * @covers ::buildCustomerOperationalExperienceForOrder
   */
  public function testBuildForOrderWithoutEventReturnsEmpty(): void {
    $builder = $this->createBuilder();
    $order = $this->createMock(\Drupal\commerce_order\Entity\OrderInterface::class);
    $order->method('getItems')->willReturn([]);
    $out = $builder->buildCustomerOperationalExperienceForOrder($order);
    $this->assertTrue($out['empty']);
  }

}
