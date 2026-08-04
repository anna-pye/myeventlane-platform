<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Kernel;

use Drupal\Core\Entity\ContentEntityType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_venue\Entity\Venue;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies the real venue base-field definitions for Media Library images.
 *
 * @group myeventlane_venue
 */
#[RunTestsInSeparateProcesses]
final class VenueMediaFieldDefinitionKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'media_library',
    'options',
    'text',
    'views',
  ];

  /**
   * Ensures the canonical field accepts only Image Media Library entities.
   */
  public function testVenueImageMediaFieldDefinition(): void {
    // The isolated worktree reuses the primary checkout's Composer vendor
    // directory, so load the worktree entity class before Composer resolves
    // the same namespace from the primary checkout.
    require_once dirname(__DIR__, 3) . '/src/Entity/Venue.php';

    $entity_type = new ContentEntityType([
      'id' => 'myeventlane_venue',
      'label' => 'Venue',
      'class' => Venue::class,
      'entity_keys' => [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'name',
        'owner' => 'uid',
      ],
    ]);

    $fields = Venue::baseFieldDefinitions($entity_type);
    self::assertArrayHasKey('image_media', $fields);
    self::assertSame('entity_reference', $fields['image_media']->getType());
    self::assertSame('media', $fields['image_media']->getSetting('target_type'));
    self::assertSame(
      ['image' => 'image'],
      $fields['image_media']->getSetting('handler_settings')['target_bundles'] ?? NULL,
    );
    self::assertSame('media_library_widget', $fields['image_media']->getDisplayOptions('form')['type'] ?? NULL);

    self::assertArrayHasKey('image', $fields);
    self::assertSame(['region' => 'hidden'], $fields['image']->getDisplayOptions('form'));
  }

}
