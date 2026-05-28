<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\myeventlane_core\Commerce\OperationalProductBundles;
use Drupal\myeventlane_event_studio\Service\VendorOperationalProductCreationManager;
use Drupal\myeventlane_event_studio\Service\VendorProductisationStudioManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for unified merch & add-on Studio authoring.
 *
 * @group myeventlane_event_studio
 */
#[Group('myeventlane_event_studio')]
final class EventStudioMerchAddonStudioTest extends TestCase {

  public function testOperationalBundleClassificationsMatchStudioExpectations(): void {
    $map = OperationalProductBundles::productBundleClassifications();
    $this->assertSame('merchandise', $map['operational_merchandise']);
    $this->assertSame('addon', $map['operational_bundle']);
    $this->assertSame('addon', $map['hospitality_package']);
    $this->assertSame('addon', $map['timed_collection_product']);
  }

  public function testVendorAddonTypesMapToProductisation(): void {
    $map = VendorOperationalProductCreationManager::VENDOR_EXTRA_TYPE_MAP;
    $this->assertSame(VendorProductisationStudioManager::TYPE_PARKING_ADDON, $map['parking']);
    $this->assertSame(VendorProductisationStudioManager::TYPE_HOSPITALITY_PACKAGE, $map['meal_package']);
    $this->assertSame(VendorProductisationStudioManager::TYPE_OPERATIONAL_BUNDLE, $map['camping']);
    $this->assertSame(VendorProductisationStudioManager::TYPE_TIMED_COLLECTION, $map['shuttle']);
  }

  public function testMerchandiseAndAddonTypeGroupsAreDisjoint(): void {
    $merch = VendorOperationalProductCreationManager::VENDOR_MERCH_EXTRA_TYPES;
    $addons = VendorOperationalProductCreationManager::VENDOR_ADDON_EXTRA_TYPES;
    $this->assertNotEmpty($merch);
    $this->assertNotEmpty($addons);
    $this->assertSame([], array_intersect($merch, $addons));
  }

  public function testExtrasSectionMetadataReferencesUnifiedForm(): void {
    $contents = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/EventStudioSection/ExtrasSection.php');
    $this->assertIsString($contents);
    $this->assertStringContainsString("title: 'Merch & add-ons'", $contents);
    $this->assertStringContainsString('EventStudioEventExtrasForm', $contents);
    $this->assertStringContainsString("section_state: 'active'", $contents);
  }

  public function testMerchandiseAndAddonsWorkspaceRoutesRedirectToExtras(): void {
    $routing = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.routing.yml');
    $this->assertIsString($routing);
    $this->assertStringContainsString('redirectToExtrasWorkspace', $routing);
    $this->assertStringContainsString('workspace_merchandise:', $routing);
    $this->assertStringContainsString('workspace_addons:', $routing);
  }

}
