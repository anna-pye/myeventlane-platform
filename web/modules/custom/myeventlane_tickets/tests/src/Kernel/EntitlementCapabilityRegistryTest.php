<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\EntitlementCapabilityRegistry
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class EntitlementCapabilityRegistryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'options',
    'datetime',
    'datetime_range',
    'commerce',
    'commerce_price',
    'commerce_order',
    'commerce_product',
    'entity_reference_revisions',
    'paragraphs',
    'mel_ticket',
    'myeventlane_vendor',
    'myeventlane_tickets',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    foreach (array_keys($container->getDefinitions()) as $service_id) {
      if (str_starts_with($service_id, 'myeventlane_vendor.') && $service_id !== 'myeventlane_vendor.event_access_checker') {
        $container->removeDefinition($service_id);
      }
    }

    $container->register('myeventlane_vendor.event_access_checker', EventVendorAccessChecker::class);
    $container->register('myeventlane_onboarding.manager', \stdClass::class);
    $container->register('myeventlane_core.vendor_follow', \stdClass::class);
    $container->register('myeventlane_event_studio.save', \stdClass::class);
    $container->register('myeventlane_legal.gatekeeper', \stdClass::class);
    $container->register('myeventlane_core.domain_detector', \stdClass::class);
    $container->register('myeventlane_analytics.order_item_classifier', \stdClass::class);
    $container->register('myeventlane_core.entity_id_normalizer', \stdClass::class);
    $container->register('myeventlane_boost.manager', \stdClass::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
  }

  /**
   * All supported entitlement types expose non-empty capability maps.
   */
  public function testAllEntitlementTypesHaveCapabilityMaps(): void {
    $registry = $this->registry();
    $maps = $registry->getAllCapabilityMaps();
    $expected = [
      Ticket::ENTITLEMENT_TICKET,
      Ticket::ENTITLEMENT_MERCH,
      Ticket::ENTITLEMENT_PARKING,
      Ticket::ENTITLEMENT_DRINK,
      Ticket::ENTITLEMENT_FOOD,
      Ticket::ENTITLEMENT_VIP,
      Ticket::ENTITLEMENT_ADDON,
    ];
    foreach ($expected as $type) {
      $this->assertArrayHasKey($type, $maps);
      $this->assertSame($type, $maps[$type]['entitlement_type']);
      foreach ([
        'scan_required',
        'redeemable',
        'multi_use',
        'requires_fulfilment',
        'supports_collection',
        'transferable',
        'expires',
        'scanner_mode',
        'fulfilment_mode',
      ] as $key) {
        $this->assertArrayHasKey($key, $maps[$type]);
      }
    }
  }

  /**
   * Scanner semantics match the redemption-log action spine.
   */
  public function testScannerSemantics(): void {
    $r = $this->registry();
    $this->assertSame(RedemptionLog::ACTION_ADMIT, $r->getScannerOperationAction(Ticket::ENTITLEMENT_TICKET));
    $this->assertSame(RedemptionLog::ACTION_COLLECT, $r->getScannerOperationAction(Ticket::ENTITLEMENT_MERCH));
    $this->assertSame(RedemptionLog::ACTION_VALIDATE, $r->getScannerOperationAction(Ticket::ENTITLEMENT_PARKING));
    $this->assertSame(RedemptionLog::ACTION_REDEEM, $r->getScannerOperationAction(Ticket::ENTITLEMENT_DRINK));
    $this->assertSame(RedemptionLog::ACTION_REDEEM, $r->getScannerOperationAction(Ticket::ENTITLEMENT_FOOD));
    $this->assertSame(RedemptionLog::ACTION_VERIFY, $r->getScannerOperationAction(Ticket::ENTITLEMENT_VIP));
    $this->assertSame(RedemptionLog::ACTION_REDEEM, $r->getScannerOperationAction(Ticket::ENTITLEMENT_ADDON));
  }

  /**
   * Fulfilment policy flags align with stored operational modes.
   */
  public function testFulfilmentSemantics(): void {
    $r = $this->registry();
    $this->assertSame('none', $r->getCapabilityMap(Ticket::ENTITLEMENT_TICKET)['fulfilment_mode']);
    $this->assertSame('collect', $r->getCapabilityMap(Ticket::ENTITLEMENT_MERCH)['fulfilment_mode']);
    $this->assertSame('none', $r->getCapabilityMap(Ticket::ENTITLEMENT_PARKING)['fulfilment_mode']);
    $this->assertSame('redeem', $r->getCapabilityMap(Ticket::ENTITLEMENT_DRINK)['fulfilment_mode']);
    $this->assertTrue($r->getCapabilityMap(Ticket::ENTITLEMENT_MERCH)['supports_collection']);
    $this->assertTrue($r->requiresFulfilmentWorkflow(Ticket::ENTITLEMENT_MERCH));
    $this->assertTrue($r->requiresFulfilmentWorkflow(Ticket::ENTITLEMENT_ADDON));
    $this->assertFalse($r->requiresFulfilmentWorkflow(Ticket::ENTITLEMENT_DRINK));
  }

  /**
   * Redemption-count consumption policy matches redeemable semantics.
   */
  public function testRedemptionAndMultiUseSemantics(): void {
    $r = $this->registry();
    $this->assertFalse($r->consumesRedemptionCount(Ticket::ENTITLEMENT_TICKET));
    $this->assertTrue($r->consumesRedemptionCount(Ticket::ENTITLEMENT_MERCH));
    $this->assertTrue($r->getCapabilityMap(Ticket::ENTITLEMENT_DRINK)['multi_use']);
    $this->assertFalse($r->getCapabilityMap(Ticket::ENTITLEMENT_TICKET)['multi_use']);
  }

  /**
   * Expiry is modeled as a type-level policy flag (field-level expiry remains on entities).
   */
  public function testExpirySemantics(): void {
    $r = $this->registry();
    foreach (array_keys($r->getAllCapabilityMaps()) as $type) {
      $this->assertTrue($r->getCapabilityMap($type)['expires']);
    }
  }

  /**
   * Unknown stored types normalize to ticket policy without widening the map.
   */
  public function testNormalizeUnknownEntitlementType(): void {
    $r = $this->registry();
    $this->assertSame(Ticket::ENTITLEMENT_TICKET, $r->normalizeEntitlementType('unknown_future_type'));
    $this->assertSame(Ticket::ENTITLEMENT_TICKET, $r->normalizeEntitlementType(''));
  }

  private function registry(): EntitlementCapabilityRegistry {
    return $this->container->get('myeventlane_tickets.entitlement_capability_registry');
  }

}
