<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects organiser brand Media capture, backfill, and fallback contracts.
 */
#[Group('myeventlane_vendor')]
final class VendorBrandMediaBackfillContractTest extends UnitTestCase {

  /**
   * The canonical model has three Image Media references and admin widgets.
   */
  public function testThreeCanonicalMediaFieldsAndAdminWidgets(): void {
    $root = dirname(__DIR__, 7);
    $fieldNames = [
      'field_mel_vendor_logo_media',
      'field_mel_vendor_banner_media',
      'field_mel_vendor_email_media',
    ];

    foreach ($fieldNames as $fieldName) {
      $storage = file_get_contents($root . '/config/sync/field.storage.myeventlane_vendor.' . $fieldName . '.yml');
      $field = file_get_contents($root . '/config/sync/field.field.myeventlane_vendor.myeventlane_vendor.' . $fieldName . '.yml');
      self::assertIsString($storage);
      self::assertIsString($field);
      self::assertStringContainsString('target_type: media', $storage);
      self::assertStringContainsString('image: image', $field);
    }

    $display = file_get_contents($root . '/config/sync/core.entity_form_display.myeventlane_vendor.myeventlane_vendor.default.yml');
    self::assertIsString($display);
    self::assertSame(3, substr_count($display, 'type: media_library_widget'));
    foreach (['field_vendor_logo', 'field_logo_image', 'field_banner_image', 'field_msg_logo'] as $legacyField) {
      self::assertStringContainsString($legacyField . ': true', $display);
    }
  }

  /**
   * Display config stays canonical after Drupal recalculates field visibility.
   */
  public function testVendorDisplaysIncludeAllHiddenPublicControlsInCanonicalOrder(): void {
    $root = dirname(__DIR__, 7);
    $displayNames = [
      'core.entity_form_display.myeventlane_vendor.myeventlane_vendor.default',
      'core.entity_view_display.myeventlane_vendor.myeventlane_vendor.default',
      'core.entity_view_display.myeventlane_vendor.myeventlane_vendor.full',
    ];
    $publicControlFields = [
      'field_public_profile_published',
      'field_public_show_banner',
      'field_public_show_description',
      'field_public_show_social_links',
      'field_public_show_summary',
      'field_public_show_website',
    ];

    foreach ($displayNames as $displayName) {
      $display = Yaml::parseFile($root . '/config/sync/' . $displayName . '.yml');
      self::assertIsArray($display);
      self::assertIsArray($display['hidden'] ?? NULL);
      foreach ($publicControlFields as $fieldName) {
        self::assertArrayHasKey($fieldName, $display['hidden'], $displayName);
      }

      foreach (['content', 'hidden'] as $section) {
        $fieldNames = array_keys($display[$section] ?? []);
        $sortedFieldNames = $fieldNames;
        sort($sortedFieldNames, SORT_STRING);
        self::assertSame($sortedFieldNames, $fieldNames, $displayName . ':' . $section);
      }
    }
  }

  /**
   * Backfill reports errors and retains every direct-file rollback source.
   */
  public function testBackfillIsFailClosedAndPreservesEveryLegacyField(): void {
    $module = dirname(__DIR__, 3);
    $update = file_get_contents($module . '/myeventlane_vendor.install');
    self::assertIsString($update);
    self::assertStringContainsString('myeventlane_vendor_update_10023', $update);
    self::assertStringContainsString('synchroniseFromLegacy($vendor, [$assetType], FALSE)', $update);
    self::assertStringContainsString('throw new UpdateException', $update);
    self::assertStringContainsString("\$sandbox['unsupported']", $update);
    self::assertStringContainsString("\$sandbox['unavailable']", $update);
    self::assertStringContainsString('unavailable legacy files retained', $update);
    self::assertStringContainsString('legacy-logo conflicts reported', $update);
    foreach (['field_vendor_logo', 'field_logo_image', 'field_banner_image', 'field_msg_logo'] as $legacyField) {
      self::assertStringNotContainsString("delete('{$legacyField}')", $update);
      self::assertStringNotContainsString("set('{$legacyField}', [])", $update);
    }
  }

  /**
   * All write and read paths delegate to one Media provenance service.
   */
  public function testSaveAndRenderPathsUseTheCentralMediaManager(): void {
    $paths = [
      $module = dirname(__DIR__, 3) . '/src/Form/VendorBrandingForm.php',
      dirname(__DIR__, 3) . '/src/Form/OrganiserProfileSettingsForm.php',
      dirname(__DIR__, 7) . '/web/modules/custom/myeventlane_messaging/src/Form/VendorBrandingForm.php',
      dirname(__DIR__, 3) . '/src/Service/VendorCardBuilder.php',
      dirname(__DIR__, 7) . '/web/modules/custom/myeventlane_messaging/src/Service/VendorBrandResolver.php',
    ];
    foreach ($paths as $path) {
      $contents = file_get_contents($path);
      self::assertIsString($contents);
      self::assertStringContainsString('VendorBrandMediaManager', $contents, $path);
    }
  }

}
