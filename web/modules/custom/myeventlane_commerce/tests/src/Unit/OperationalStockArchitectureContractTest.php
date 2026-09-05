<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_commerce\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the controlled Commerce Stock migration boundary.
 *
 * @group myeventlane_commerce
 */
final class OperationalStockArchitectureContractTest extends TestCase {

  public function testManualRecoveryUrlIsDeclaredForTheOrdersTemplate(): void {
    $root = dirname(__DIR__, 3);
    $module = file_get_contents($root . '/myeventlane_commerce.module');
    $template = file_get_contents($root . '/templates/mel-vendor-operational-addon-orders.html.twig');
    self::assertStringContainsString("'manual_recovery_url' => NULL", $module);
    self::assertStringContainsString('href="{{ manual_recovery_url }}"', $template);
  }

  /**
   * Confirms local stock is scoped only to operational variation bundles.
   */
  public function testOnlyOperationalVariationsUseLocalStock(): void {
    $root = dirname(__DIR__, 7);
    $config = file_get_contents($root . '/config/sync/commerce_stock.service_manager.yml');
    self::assertIsString($config);
    self::assertStringContainsString('default_service_id: always_in_stock', $config);

    foreach ([
      'operational_merchandise_var',
      'operational_bundle_var',
      'hospitality_package_var',
      'timed_collection_var',
    ] as $bundle) {
      self::assertStringContainsString('commerce_product_variation_' . $bundle . '_service_id: local_stock', $config);
    }
    self::assertStringNotContainsString('ticket_service_id: local_stock', $config);
  }

  /**
   * Confirms the hold survives until Commerce Stock writes the sale.
   */
  public function testPlacementHoldReleasesAfterCommerceStockSale(): void {
    $root = dirname(__DIR__, 3);
    $subscriber = file_get_contents($root . '/src/EventSubscriber/OperationalStockCommerceSubscriber.php');
    self::assertIsString($subscriber);
    self::assertStringContainsString("place.pre_transition' => ['onOrderPlacePreTransition', 60]", $subscriber);
    self::assertStringContainsString("place.post_transition' => ['onOrderPlacePostTransition', -200]", $subscriber);
    self::assertStringContainsString('lockAndValidatePlacement', $subscriber);

    $module = file_get_contents($root . '/myeventlane_commerce.module');
    self::assertIsString($module);
    self::assertStringContainsString('myeventlane_commerce_operational_stock_validate', $module);
    self::assertStringContainsString('operational_stock_hold_manager\')->refresh($order)', $module);
  }

  /**
   * Confirms cancellation uses the capped MEL return ledger.
   */
  public function testCancellationCannotOverlapCommerceStockAutomaticReturn(): void {
    $root = dirname(__DIR__, 7);
    $events = file_get_contents($root . '/config/sync/commerce_stock.core_stock_events.yml');
    $manager = file_get_contents($root . '/web/modules/custom/myeventlane_tickets/src/Service/OperationalStockReturnManager.php');
    self::assertIsString($events);
    self::assertIsString($manager);
    self::assertStringContainsString('core_stock_events_order_cancel: false', $events);
    self::assertStringContainsString('reconcileCancellation', $manager);
    self::assertStringContainsString('returnedQuantity', $manager);
  }

  /**
   * Confirms migration creates a usable location and preserves live ledgers.
   */
  public function testMigrationBootstrapsLocationWithoutResettingHistory(): void {
    $root = dirname(__DIR__, 7);
    $deploy = file_get_contents($root . '/web/modules/custom/myeventlane_commerce/myeventlane_commerce.deploy.php');
    self::assertIsString($deploy);
    self::assertStringContainsString("getStorage('commerce_stock_location')", $deploy);
    self::assertStringContainsString("'name' => 'MyEventLane inventory'", $deploy);
    self::assertStringContainsString('if ($transactionCount > 0)', $deploy);
    self::assertStringContainsString('Existing Commerce Stock history wins.', $deploy);
  }

  /**
   * Confirms a code-first deployment can boot before Stock is enabled.
   */
  public function testCommerceStockServicesAreOptionalDuringModuleEnablement(): void {
    $root = dirname(__DIR__, 7);
    $commerceServices = file_get_contents($root . '/web/modules/custom/myeventlane_commerce/myeventlane_commerce.services.yml');
    $ticketServices = file_get_contents($root . '/web/modules/custom/myeventlane_tickets/myeventlane_tickets.services.yml');
    self::assertIsString($commerceServices);
    self::assertIsString($ticketServices);
    self::assertSame(3, substr_count($commerceServices, "'@?commerce_stock.service_manager'"));
    self::assertStringContainsString("'@?commerce_stock.service_manager'", $ticketServices);
  }

  /**
   * Confirms field updates preserve installed field identity metadata.
   */
  public function testFulfilmentUpdateUsesInstalledFieldDefinitions(): void {
    $root = dirname(__DIR__, 7);
    $install = file_get_contents($root . '/web/modules/custom/myeventlane_tickets/myeventlane_tickets.install');
    self::assertIsString($install);
    self::assertStringContainsString("getFieldStorageDefinition('fulfilment_status', 'myeventlane_ticket')", $install);
    self::assertStringContainsString("getFieldStorageDefinition('action_type', 'mel_redemption_log')", $install);
    self::assertStringNotContainsString("updateFieldStorageDefinition(\$ticket_fields['fulfilment_status'])", $install);
    self::assertStringNotContainsString("updateFieldStorageDefinition(\$log_fields['action_type'])", $install);
  }

}
