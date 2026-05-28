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
final class CustomerOperationalProjectionSafetyTest extends UnitTestCase {

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
   * @covers ::sanitizeCustomerOperationalExperience
   */
  public function testSanitizeStripsForbiddenKeysRecursively(): void {
    $builder = $this->createBuilder();
    $dirty = [
      'capability_label' => 'Safe',
      'qr_payload' => 'secret',
      'nested' => [
        'replay_token' => 'no',
        'fulfillment_summary' => 'ok',
      ],
    ];
    $clean = $builder->sanitizeCustomerOperationalExperience($dirty);
    $this->assertSame('Safe', $clean['capability_label']);
    $this->assertArrayNotHasKey('qr_payload', $clean);
    $this->assertArrayNotHasKey('replay_token', $clean['nested']);
    $this->assertSame('ok', $clean['nested']['fulfillment_summary']);
  }

  /**
   * @covers ::buildFromOperationalDocument
   */
  public function testBuiltProjectionJsonExcludesForbiddenTokens(): void {
    $builder = $this->createBuilder();
    $studio = OperationalCapabilityStudioTestFactory::buildManager();
    $doc = $studio->emptyDocument();
    $doc['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP] = array_merge(
      $doc['capabilities'][OperationalEntitlementCapabilityManager::TYPE_MERCH_PICKUP],
      [
        'enabled' => TRUE,
        'customer_visibility' => 'after_purchase',
        'readiness_state' => OperationalCapabilityStudioManager::READINESS_CONFIGURED,
      ],
    );

    $out = $builder->buildFromOperationalDocument($doc);
    $json = json_encode($out, JSON_THROW_ON_ERROR);
    foreach (['qr_payload', 'replay_token', 'device_fingerprint', 'scanner_action', 'audit_trace'] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $json);
    }
  }

}
