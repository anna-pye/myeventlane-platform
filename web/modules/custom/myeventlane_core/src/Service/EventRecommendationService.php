<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Fetches a small set of same-category public events (Invite Them Back / next step).
 */
final class EventRecommendationService {

  private const int MAX_RELATED_CANDIDATES = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly EventSaveCountService $eventSaveCount,
    private readonly EventViewerCountService $eventViewerCount,
    private readonly EventStateResolver $eventStateResolver,
  ) {
  }

  /**
   * Returns published event nodes (excluding the source) in any of the same categories.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   *   Newest first. Up to $limit (capped). Empty if the source has no category or no matches.
   */
  public function getRelatedEvents(NodeInterface $node, int $limit = 3): array {
    if ($limit < 1) {
      return [];
    }
    if ($node->bundle() !== 'event' || !$node->isPublished()) {
      return [];
    }
    if ($limit > self::MAX_RELATED_CANDIDATES) {
      $limit = self::MAX_RELATED_CANDIDATES;
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
    $now = $this->time->getRequestTime();
    $excluded_states = [
      'draft',
      'cancelled',
      'archived',
    ];
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('nid', (int) $node->id(), '<>')
      ->condition('field_event_state', $excluded_states, 'NOT IN')
      ->condition('field_event_start', $now, '>=')
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

  /**
   * Same pool as getRelatedEvents(), then re-ordered by a lightweight global score.
   *
   * Deterministic: equal scores use newer created time first, then lower nid.
   *
   * @return array<int, \Drupal\node\NodeInterface>
   */
  public function getRankedRelatedEvents(NodeInterface $node, int $limit = 3): array {
    if ($limit < 1) {
      return [];
    }
    $candidates = min(self::MAX_RELATED_CANDIDATES, $limit * 3);
    $events = $this->getRelatedEvents($node, $candidates);
    if ($events === []) {
      return [];
    }
    $now = $this->time->getRequestTime();
    $scored = [];
    foreach ($events as $event) {
      $score = 0;
      $age = $now - $event->getCreatedTime();
      if ($age < 86400 * 7) {
        $score += 20;
      }
      $save_count = $this->eventSaveCount->getSaveCount((int) $event->id());
      $score += min($save_count * 2, 40);
      $viewer_count = $this->eventViewerCount->getViewerCount((int) $event->id());
      $score += min($viewer_count * 3, 30);
      if ($this->eventStateResolver->isEventBoosted($event)) {
        $score += 15;
      }
      $scored[] = [
        'event' => $event,
        'score' => $score,
      ];
    }
    usort(
      $scored,
      static function (array $a, array $b): int {
        $cmp = $b['score'] <=> $a['score'];
        if ($cmp !== 0) {
          return $cmp;
        }
        /** @var \Drupal\node\NodeInterface $aEvent */
        $aEvent = $a['event'];
        /** @var \Drupal\node\NodeInterface $bEvent */
        $bEvent = $b['event'];
        $t = $bEvent->getCreatedTime() <=> $aEvent->getCreatedTime();
        if ($t !== 0) {
          return $t;
        }
        return (int) $aEvent->id() <=> (int) $bEvent->id();
      },
    );
    $top = array_slice($scored, 0, $limit);
    $out = [];
    foreach ($top as $row) {
      $out[] = $row['event'];
    }
    return $out;
  }

}
