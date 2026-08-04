<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Media-backed venue image architecture and migration contract.
 *
 * @group myeventlane_venue
 */
final class VenueMediaArchitectureContractTest extends TestCase {

  /**
   * Verifies venue editing uses Image Media and keeps the legacy fallback.
   */
  public function testVenueImageUsesMediaLibraryWithLegacyFallback(): void {
    $root = dirname(__DIR__, 7);
    $entity = file_get_contents($root . '/web/modules/custom/myeventlane_venue/src/Entity/Venue.php');
    $form = file_get_contents($root . '/web/modules/custom/myeventlane_venue/src/Form/VenueForm.php');
    $template = file_get_contents($root . '/web/modules/custom/myeventlane_venue/templates/myeventlane-venue-page.html.twig');

    self::assertIsString($entity);
    self::assertStringContainsString("\$fields['image_media'] = BaseFieldDefinition::create('entity_reference')", $entity);
    self::assertStringContainsString("->setSetting('target_type', 'media')", $entity);
    self::assertStringContainsString("'image' => 'image'", $entity);
    self::assertStringContainsString("'type' => 'media_library_widget'", $entity);
    self::assertStringContainsString("\$fields['image'] = BaseFieldDefinition::create('image')", $entity);
    self::assertStringContainsString('return $this->getName();', $entity);

    self::assertIsString($form);
    self::assertStringContainsString('organiserMediaAccess->canSelect($media)', $form);
    self::assertStringContainsString('$is_unchanged_selection', $form);
    self::assertStringContainsString('Choose a venue image uploaded by your organiser account.', $form);

    self::assertIsString($template);
    self::assertStringContainsString('{% if image_url %}', $template);
    self::assertStringNotContainsString('venue.image.entity', $template);
  }

  /**
   * Verifies the update preserves ownership, alt text, and legacy data.
   */
  public function testVenueImageMigrationPreservesOwnerAndAccessibilityData(): void {
    $root = dirname(__DIR__, 7);
    $install = file_get_contents($root . '/web/modules/custom/myeventlane_venue/myeventlane_venue.install');

    self::assertIsString($install);
    self::assertStringContainsString('function myeventlane_venue_update_10002(array &$sandbox): string', $install);
    self::assertStringContainsString("'uid' => \$owner_id", $install);
    self::assertStringContainsString("'field_media_image.target_id' => (int) \$file->id()", $install);
    self::assertStringContainsString("'alt' => \$alt", $install);
    self::assertStringContainsString("\$venue->set(\$field_name, ['target_id' => (int) \$media->id()])", $install);
    self::assertStringNotContainsString("\$venue->set('image', NULL)", $install);
  }

}
