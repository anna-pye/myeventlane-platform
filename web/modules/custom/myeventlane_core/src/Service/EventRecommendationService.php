<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Fetches a small set of same-category public events (Invite Them Back / next step).
 */
final class EventRecommendationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Returns published event nodes (excluding the source) in any of the same categories.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   Newest first. Empty if the source has no category or no matches.
   */
  public function getRelatedEvents(NodeInterface $node, int $limit = 3): array {
    if ($limit < 1) {
      return [];
    }
    if ($node->bundle() !== 'event' || !$node->isPublished()) {
      return [];
    }
    if ($limit > 3) {
      $limit = 3;
    }

    $category_ids = [];
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      foreach ($node->get('field_category') as $item) {
        if ($item->target_id) {
          $category_ids[] = (int) $item->target_id;
        }
      }
    }
    if ($category_ids === []) {
      return [];
    }

    $category_ids = array_values(array_unique($category_ids));
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('nid', (int) $node->id(), '<>')
      ->condition('field_category', $category_ids, 'IN')
      ->sort('created', 'DESC')
      ->range(0, $limit);

    $nids = $query->execute();
    if (!is_array($nids) || $nids === []) {
      return [];
    }

    $nodes = $storage->loadMultiple($nids);
    if (!is_array($nodes)) {
      return [];
    }

    $out = [];
    foreach ($nids as $nid) {
      if (isset($nodes[$nid]) && $nodes[$nid] instanceof NodeInterface) {
        $out[] = $nodes[$nid];
      }
    }
    return $out;
  }

}
