<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommerceLinkManager;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityCommercePreviewBuilder;
use Drupal\myeventlane_event_studio\Service\OperationalCapabilityPreviewBuilder;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_tickets\Service\FulfillmentLifecycleManager;
use Drupal\myeventlane_tickets\Service\InventoryReservationGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalEntitlementCapabilityManager;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_event_studio\Service\OperationalCapabilityPreviewBuilder
 *
 * @group myeventlane_event_studio
 */
final class OperationalCapabilityPreviewBuilderTest extends UnitTestCase {

  /**
   * @covers ::buildCustomerPreview
   */
  public function testCustomerPreviewExcludesSecrets(): void {
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
    $builder = new OperationalCapabilityPreviewBuilder($studio, $commercePreview, $this->getStringTranslationStub());

    $preview = $builder->buildCustomerPreview([
      'schema_version' => 1,
      'capabilities' => [
        OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP => [
          'enabled' => TRUE,
          'customer_visibility' => 'after_purchase',
          'preview_summary' => 'Merch pickup · collect on site',
          'fulfillment_mode' => 'collect',
          'reservation_mode' => 'merch',
        ],
      ],
    ]);
    $this->assertFalse($preview['empty']);
    $this->assertCount(1, $preview['items']);
    $this->assertStringNotContainsString('replay_token', (string) $preview['items'][0]['summary']);
  }

}
