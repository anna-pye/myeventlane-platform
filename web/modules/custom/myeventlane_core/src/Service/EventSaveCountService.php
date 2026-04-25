<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Counts "event_save" flag records for an event (node) ID.
 */
final class EventSaveCountService {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Returns how many users (or sessions) have saved the given event.
   */
  public function getSaveCount(int $nodeId): int {
    if ($nodeId < 1 || !$this->entityTypeManager->hasDefinition('flagging')) {
      return 0;
    }

    $query = $this->entityTypeManager
      ->getStorage('flagging')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', 'event_save')
      ->condition('entity_type', 'node')
      ->condition('entity_id', (string) $nodeId);

    return (int) $query->count()->execute();
  }

}
