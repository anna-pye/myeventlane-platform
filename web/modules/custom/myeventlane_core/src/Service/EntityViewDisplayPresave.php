<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Ensures view modes exist before entity_view_display save (Layout Builder workaround).
 *
 * Fixes a core bug where calculateDependencies() calls getConfigDependencyName()
 * on a null view mode entity. Creates the missing view mode when needed.
 * Behaviour is identical to the prior inline hook_entity_presave logic.
 */
final class EntityViewDisplayPresave {

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private \Psr\Log\LoggerInterface $logger;

  /**
   * Constructs an EntityViewDisplayPresave.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('myeventlane_core');
  }

  /**
   * Ensures a view mode exists for the entity view display; creates it if missing.
   *
   * No-op when the entity is not an entity_view_display, or when the mode
   * is 'default'. Otherwise ensures the view mode entity exists before the
   * display's calculateDependencies runs.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved (expected: entity_view_display).
   */
  public function presave(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() !== 'entity_view_display') {
      return;
    }

    if ($entity->mode === 'default') {
      return;
    }

    $target_entity_type = $this->entityTypeManager->getDefinition($entity->targetEntityType);
    if (!$target_entity_type) {
      return;
    }

    $mode_id = $target_entity_type->id() . '.' . $entity->mode;
    $mode_storage = $this->entityTypeManager->getStorage('entity_view_mode');

    $mode_storage->resetCache([$mode_id]);
    $mode_entity = $mode_storage->load($mode_id);

    if (!$mode_entity) {
      try {
        $mode_entity = $mode_storage->create([
          'id' => $mode_id,
          'label' => ucfirst(str_replace('_', ' ', $entity->mode)),
          'targetEntityType' => $target_entity_type->id(),
          'status' => TRUE,
        ]);
        $mode_entity->save();

        $mode_storage->resetCache([$mode_id]);
        $this->cacheTagsInvalidator->invalidateTags(['entity_view_mode_list']);

        $this->logger->notice(
          'Created missing view mode @mode_id for entity view display @display_id.',
          ['@mode_id' => $mode_id, '@display_id' => $entity->id()]
        );
      }
      catch (\Exception $e) {
        $this->logger->error(
          'Failed to create view mode @mode_id for @display_id: @message',
          [
            '@mode_id' => $mode_id,
            '@display_id' => $entity->id(),
            '@message' => $e->getMessage(),
          ]
        );
      }
    }
  }

}
