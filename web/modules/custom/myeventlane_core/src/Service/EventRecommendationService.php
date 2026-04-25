<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Fetches a small set of same-category public events (Invite Them Back / next step).
 */
final class EventRecommendationService {

  private const int MAX_RELATED_CANDIDATES = 50;

  /**
   * In-request cache of flagging counts to avoid repeated queries per card.
   *
   * @var array<int, int>
   */
  private array $saveCountByNid = [];

  /**
   * In-request cache of viewer counts to avoid repeated queries.
   *
   * @var array<int, int>
   */
  private array $viewerCountByNid = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly EventSaveCountService $eventSaveCount,
    private readonly EventViewerCountService $eventViewerCount,
    private readonly EventStateResolver $eventStateResolver,
    private readonly TranslationInterface $stringTranslation,
  ) {
  }

  /**
   * Explains why an event card is surfaced (home, list, event page, search).
   *
   * @param int|null $prefetchedSaveCount
   *   When the caller already loaded save count (e.g. ranking), pass to avoid
   *   a second read; otherwise a single cached read is used per request.
   * @param array<int>|null $referenceCategoryIds
   *   Category term IDs (e.g. the taxonomy term of the current category page);
   *   unioned with $current’s categories when both are present for the
   *   “same category” check.
   *
   * @return array{type: 'category'|'trending'|'soon'|'weekend'|null, label: string|null}
   *   All keys always present. label is a translated string for display, or null.
   */
  public function getRecommendationContext(
    NodeInterface $event,
    ?NodeInterface $current = NULL,
    ?int $prefetchedSaveCount = NULL,
    ?array $referenceCategoryIds = NULL,
  ): array {
    $context = [
      'type' => NULL,
      'label' => NULL,
    ];

    $t = $this->stringTranslation;

    $from_current = NULL;
    if ($current && $current->hasField('field_category') && !$current->get('field_category')->isEmpty()) {
      $from_current = array_map(
        'intval',
        array_column($current->get('field_category')->getValue(), 'target_id')
      );
    }
    $from_page = NULL;
    if ($referenceCategoryIds !== NULL && $referenceCategoryIds !== []) {
      $from_page = array_values(array_unique(array_map('intval', $referenceCategoryIds)));
    }
    $reference_for_category = NULL;
    if ($from_current !== NULL && $from_page !== NULL) {
      $reference_for_category = array_values(array_unique([...$from_current, ...$from_page]));
    }
    elseif ($from_current !== NULL) {
      $reference_for_category = $from_current;
    }
    elseif ($from_page !== NULL) {
      $reference_for_category = $from_page;
    }

    if ($reference_for_category !== NULL && $event->hasField('field_category') && !$event->get('field_category')->isEmpty()) {
      $event_categories = array_map(
        'intval',
        array_column($event->get('field_category')->getValue(), 'target_id')
      );
      if (array_intersect($event_categories, $reference_for_category) !== []) {
        $context['type'] = 'category';
        $context['label'] = (string) $t->translate('Because you liked this category');
        return $context;
      }
    }

    $save_count = $prefetchedSaveCount ?? $this->getSaveCountCached((int) $event->id());
    if ($save_count > 10) {
      $context['type'] = 'trending';
      $context['label'] = (string) $t->translate('Trending right now');
      return $context;
    }

    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $value = (string) $event->get('field_event_start')->value;
      $start = strtotime($value);
      if ($start > 0) {
        $now = $this->time->getRequestTime();
        $hours = ($start - $now) / 3600.0;

        if ($hours > 0 && $hours < 6) {
          return [
            'type' => 'soon',
            'label' => (string) $t->translate('Starting soon'),
          ];
        }

        $weekday = (int) date('N', $start);
        if ($hours > 0 && $hours < 48 && ($weekday === 6 || $weekday === 7)) {
          return [
            'type' => 'weekend',
            'label' => (string) $t->translate('This weekend'),
          ];
        }
      }
    }

    return $context;
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
    $now_datetime = date('Y-m-d\TH:i:s', $now);
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
      ->condition('field_event_state', $excluded_states, 'NOT IN');

    $or = $query->orConditionGroup();
    $or->condition('field_event_start', $now_datetime, '>=');
    $or->condition('field_event_end', $now_datetime, '>=');
    $query->condition($or);

    $query
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
   * After base capping, applies a single time-weight (event start) for urgency + decay.
   * Deterministic: equal scores use newer created time first, then lower nid.
   *
   * When $referenceCategoryIds is non-empty (category page / category context), the
   * top-ranked list is returned unchanged. Otherwise a soft per-primary-category cap
   * (max 2) is applied after scoring, with fallback fill from the full ranked list.
   *
   * @param array<int>|null $referenceCategoryIds
   *   If non-empty, skips soft balancing (pure score order for category context).
   *
   * @return array<int, array{node: \Drupal\node\NodeInterface, context: array{type: 'category'|'trending'|'soon'|'weekend'|null, label: string|null}}>
   */
  public function getRankedRelatedEvents(
    NodeInterface $node,
    int $limit = 3,
    ?array $referenceCategoryIds = NULL,
  ): array {
    if ($limit < 1) {
      return [];
    }
    $candidates = min(self::MAX_RELATED_CANDIDATES, $limit * 3);
    $events = $this->getRelatedEvents($node, $candidates);
    if ($events === []) {
      return [];
    }
    $scored = [];
    foreach ($events as $event) {
      $score = 0;
      $save_count = $this->getSaveCountCached((int) $event->id());
      $score += min($save_count * 2, 40);
      $viewer_count = $this->getViewerCountCached((int) $event->id());
      $score += min($viewer_count * 3, 30);
      if ($this->eventStateResolver->isEventBoosted($event)) {
        $score += 15;
      }
      $score = max(0, min($score, 100));
      $event_start = 0;
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $start_ts = strtotime((string) $event->get('field_event_start')->value);
        if ($start_ts > 0) {
          $event_start = (int) $start_ts;
        }
      }
      $score = $this->applyTimeWeight($score, $event_start);
      $score = max(1, min($score, 200));
      $context = $this->getRecommendationContext(
        $event,
        $node,
        $save_count,
        $referenceCategoryIds
      );
      $scored[] = [
        'event' => $event,
        'score' => $score,
        'context' => $context,
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

    $is_category_context = $referenceCategoryIds !== NULL && $referenceCategoryIds !== [];
    if ($is_category_context) {
      $selected = array_slice($scored, 0, $limit);
    }
    else {
      $balanced = [];
      $category_counts = [];
      foreach ($scored as $item) {
        $event = $item['event'];
        $category_ids = [];
        if ($event->hasField('field_category')) {
          $category_ids = array_map(
            'intval',
            array_column($event->get('field_category')->getValue(), 'target_id')
          );
        }
        $primary_category = $category_ids[0] ?? 'none';
        $count = $category_counts[$primary_category] ?? 0;
        if ($count >= 2) {
          continue;
        }
        $balanced[] = $item;
        $category_counts[$primary_category] = $count + 1;
        if (count($balanced) >= $limit) {
          break;
        }
      }
      if (count($balanced) < $limit) {
        $seen_nids = [];
        foreach ($balanced as $b) {
          $seen_nids[(int) $b['event']->id()] = TRUE;
        }
        foreach ($scored as $item) {
          if (count($balanced) >= $limit) {
            break;
          }
          $nid = (int) $item['event']->id();
          if (isset($seen_nids[$nid])) {
            continue;
          }
          $balanced[] = $item;
          $seen_nids[$nid] = TRUE;
        }
      }
      $selected = $balanced;
    }

    $out = [];
    foreach ($selected as $row) {
      $out[] = [
        'node' => $row['event'],
        'context' => $row['context'],
      ];
    }
    return $out;
  }

  /**
   * Merges recommendation context onto a node view build (e.g. from entity_view).
   */
  public function attachListCardContextToBuild(
    array &$build,
    NodeInterface $node,
    ?NodeInterface $routeContextEvent = NULL,
    ?array $referenceCategoryIds = NULL,
  ): void {
    if (array_key_exists('#recommendation_context', $build)) {
      return;
    }
    $context = $this->getRecommendationContext(
      $node,
      $routeContextEvent,
      NULL,
      $referenceCategoryIds
    );
    $build['#recommendation_context'] = $context;
  }

  /**
   * Single time model: soon / near / far multipliers, then distance decay (all from start).
   *
   * Ongoing or past (non-positive hours-until) return 0; final score floor applies after.
   */
  private function applyTimeWeight(int $score, int $eventStart): int {
    if ($eventStart < 1) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $seconds_until = $eventStart - $now;
    $hours_until = $seconds_until / 3600.0;

    if ($hours_until <= 0) {
      return 0;
    }
    if ($hours_until <= 6) {
      return (int) ($score * 2.5);
    }
    if ($hours_until <= 24) {
      return (int) ($score * 1.8);
    }
    if ($hours_until <= 48) {
      return (int) ($score * 1.3);
    }

    $days_until = $hours_until / 24.0;
    $decay_factor = exp(-0.03 * $days_until);

    return (int) round($score * $decay_factor);
  }

  private function getSaveCountCached(int $nid): int {
    if (!array_key_exists($nid, $this->saveCountByNid)) {
      $this->saveCountByNid[$nid] = $this->eventSaveCount->getSaveCount($nid);
    }
    return $this->saveCountByNid[$nid];
  }

  private function getViewerCountCached(int $nid): int {
    if (!array_key_exists($nid, $this->viewerCountByNid)) {
      $this->viewerCountByNid[$nid] = $this->eventViewerCount->getViewerCount($nid);
    }
    return $this->viewerCountByNid[$nid];
  }

}
