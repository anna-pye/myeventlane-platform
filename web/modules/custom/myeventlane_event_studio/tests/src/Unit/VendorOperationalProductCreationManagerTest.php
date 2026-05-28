<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_event_studio\Service\VendorOperationalProductCreationManager;
use Drupal\myeventlane_event_studio\Service\VendorProductisationStudioManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lightweight contract tests for vendor operational product creation payloads.
 *
 * @group myeventlane_event_studio
 */
#[Group('myeventlane_event_studio')]
final class VendorOperationalProductCreationManagerTest extends TestCase {

  public function testForbiddenCreationKeysList(): void {
    $this->assertContains('inventory_quantity', VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS);
    $this->assertContains('qr_payload', VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS);
    $this->assertContains('entitlement_id', VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS);
    $this->assertContains('scanner_action', VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS);
    $this->assertContains('warehouse_ids', VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS);
    $this->assertContains('shipment_state', VendorOperationalProductCreationManager::FORBIDDEN_CREATION_KEYS);
  }

  public function testVendorExtraTypeMapCoversProductisationTypes(): void {
    $this->assertSame(
      VendorProductisationStudioManager::TYPE_MERCHANDISE,
      VendorOperationalProductCreationManager::VENDOR_EXTRA_TYPE_MAP['merchandise'],
    );
    $this->assertSame(
      VendorProductisationStudioManager::TYPE_PARKING_ADDON,
      VendorOperationalProductCreationManager::VENDOR_EXTRA_TYPE_MAP['parking'],
    );
    $this->assertSame(
      VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE,
      VendorOperationalProductCreationManager::VENDOR_EXTRA_TYPE_MAP['vip_hospitality'],
    );
    $this->assertSame(
      VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE,
      VendorOperationalProductCreationManager::VENDOR_EXTRA_TYPE_MAP['bundle'],
    );
  }

  public function testIsMerchandiseAndAddonExtraTypeHelpers(): void {
    $manager = $this->createPartialManager();
    $this->assertTrue($manager->isMerchandiseExtraType('merchandise'));
    $this->assertFalse($manager->isMerchandiseExtraType('parking'));
    $this->assertTrue($manager->isAddonExtraType('parking'));
    $this->assertTrue($manager->isAddonExtraType('meal_package'));
    $this->assertFalse($manager->isAddonExtraType('merchandise'));
  }

  public function testStripForbiddenFromPayloadRemovesOperationalKeys(): void {
    $manager = $this->createPartialManager();
    $out = $manager->stripForbiddenFromPayload([
      'title' => 'T-shirt',
      'qr_payload' => 'secret',
      'scanner_action' => 'x',
      'entitlement_id' => '1',
      'warehouse_ids' => ['w1'],
      'shipment_state' => 'ship',
      'nested' => ['scanner_tokens' => ['a']],
    ]);
    $this->assertSame('T-shirt', $out['title']);
    $this->assertArrayNotHasKey('qr_payload', $out);
    $this->assertArrayNotHasKey('scanner_action', $out);
    $this->assertArrayNotHasKey('entitlement_id', $out);
    $this->assertArrayNotHasKey('warehouse_ids', $out);
    $this->assertArrayNotHasKey('shipment_state', $out);
    $this->assertArrayHasKey('nested', $out);
    $this->assertArrayNotHasKey('scanner_tokens', $out['nested']);
  }

  public function testNormalizeVendorExtraTypeRejectsUnknown(): void {
    $manager = $this->createPartialManager();
    $this->assertSame('', $manager->normalizeVendorExtraType('operational_product'));
    $this->assertSame('merchandise', $manager->normalizeVendorExtraType('Merchandise'));
  }

  private function createPartialManager(): VendorOperationalProductCreationManager {
    $ref = new \ReflectionClass(VendorOperationalProductCreationManager::class);
    /** @var VendorOperationalProductCreationManager $manager */
    $manager = $ref->newInstanceWithoutConstructor();
    return $manager;
  }

}
