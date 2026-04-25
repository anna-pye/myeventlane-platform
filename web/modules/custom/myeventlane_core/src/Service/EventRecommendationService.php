<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_core\Utility\UpcomingEventEntityQueryHelper;
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
    private readonly RouteMatchInterface $routeMatch,
  ) {
  }

  /**
   * Suggests the default discovery chip (category listing / homepage context).
   *
   * Uses `field_event_type` (not Commerce ticket rows): `rsvp` maps to the “free” chip.
   */
  public function getDefaultChip(?NodeInterface $context = NULL): string {
    $now = $this->time->getRequestTime();

    if (!$context) {
      return 'all';
    }

    $save_count = $this->eventSaveCount->getSaveCount((int) $context->id());
    if ($save_count > 10) {
      return 'trending';
    }

    if ($context->hasField('field_event_start') && !$context->get('field_event_start')->isEmpty()) {
      $start = strtotime((string) $context->get('field_event_start')->value);
      if ($start > $now && ($start - $now) < 21600) {
        return 'now';
      }
    }

    if ($context->hasField('field_event_type') && !$context->get('field_event_type')->isEmpty()
      && (string) $context->get('field_event_type')->value === 'rsvp') {
      return 'free';
    }

    return 'all';
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
   * @return array{
   *   type: 'category'|'trending'|'soon'|'weekend'|null,
   *   label: string|null,
   *   category_id: int|null,
   *   is_weekend: bool,
   *   is_tonight: bool,
   *   route_name: string
   * }
   *   type/label are for display; other keys are request + card signals.
   */
  public function getRecommendationContext(
    NodeInterface $event,
    ?NodeInterface $current = NULL,
    ?int $prefetchedSaveCount = NULL,
    ?array $referenceCategoryIds = NULL,
  ): array {
    $signals = $this->getContextSignals($event);
    $context = array_merge(
      [
        'type' => NULL,
        'label' => NULL,
      ],
      $signals
    );

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
          $context['type'] = 'soon';
          $context['label'] = (string) $t->translate('Starting soon');
          return $context;
        }

        $weekday = (int) date('N', $start);
        if ($hours > 0 && $hours < 48 && ($weekday === 6 || $weekday === 7)) {
          $context['type'] = 'weekend';
          $context['label'] = (string) $t->translate('This weekend');
          return $context;
        }
      }
    }

    return $context;
  }

  /**
   * Request + card context signals (no storage; used with cache contexts).
   *
   * @return array{category_id: int|null, is_weekend: bool, is_tonight: bool, route_name: string}
   */
  private function getContextSignals(NodeInterface $event): array {
    return [
      'category_id' => $this->getPrimaryCategory($event),
      'is_weekend' => $this->isWeekend(),
      'is_tonight' => $this->isTonight($event),
      'route_name' => (string) ($this->routeMatch->getRouteName() ?? ''),
    ];
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
      ->condition('field_event_state', $excluded_states, 'NOT IN');

    UpcomingEventEntityQueryHelper::addStartOrEndInFutureOrOngoing($query, $now);

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
   * Same pool as getRelatedEvents(), re-ordered by deterministic global score.
   *
   * Behaviour: score → sort (desc) → per-category round-robin → first $limit.
   * Tie-break: higher score first, then newer created, then lower nid.
   *
   * @param array<int>|null $referenceCategoryIds
   *   Used for getRecommendationContext() labels; ranking uses route + same logic.
   *
   * @return array<int, array{node: \Drupal\node\NodeInterface, context: array<string, mixed>}>
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
      $age = $this->time->getRequestTime() - (int) $event->getCreatedTime();
      if ($age < 86400 * 7) {
        $score += 20;
      }
      $save_count = $this->getSaveCountCached((int) $event->id());
      $score += min($save_count * 2, 40);
      $viewer_count = $this->getViewerCountCached((int) $event->id());
      $score += min($viewer_count * 3, 30);
      if ($this->eventStateResolver->isEventBoosted($event)) {
        $score += 15;
      }

      $current = $node;
      if ($this->sharesCategory($event, $current)) {
        $score += 20;
      }
      if ($this->isTonight($event)) {
        $score += 25;
      }
      if ($this->isWeekendEvent($event)) {
        $score += 15;
      }
      if ($this->routeMatch->getRouteName() === 'view.upcoming_events.page_category') {
        $score += 10;
      }

      $intent_weight = 1;
      if ($this->sharesCategory($event, $current)) {
        $intent_weight = 3;
      }
      elseif ($this->isTonight($event)) {
        $intent_weight = 2;
      }
      $score += $intent_weight * 10;

      $event_start = 0;
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $start_ts = strtotime((string) $event->get('field_event_start')->value);
        if ($start_ts > 0) {
          $event_start = (int) $start_ts;
        }
      }

      $score = $this->applyTimeDecay($score, $event_start);
      if ($event_start > 0) {
        $hours_until_start = ($event_start - $this->time->getRequestTime()) / 3600.0;
        if ($hours_until_start > 0 && $hours_until_start <= 24) {
          $score += 25;
        }
      }
      $score = max(1, min($score, 200));

      $context = $this->getRecommendationContext(
        $event,
        $node,
        $save_count,
        $referenceCategoryIds
      );

      $primary_category = 0;
      if ($event->hasField('field_category') && !$event->get('field_category')->isEmpty()) {
        $primary_category = (int) $event->get('field_category')->first()->target_id;
      }
      $scored[] = [
        'event' => $event,
        'score' => $score,
        'context' => $context,
        'category_id' => $primary_category,
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

    $grouped = [];
    foreach ($scored as $item) {
      $cat = $item['category_id'] ?? 0;
      $grouped[$cat][] = $item;
    }
    $balanced = [];
    while (!empty($grouped) && count($balanced) < $limit) {
      foreach ($grouped as $cat => &$items) {
        if (count($balanced) >= $limit) {
          break 2;
        }
        if (!empty($items)) {
          $balanced[] = array_shift($items);
        }
        if (empty($items)) {
          unset($grouped[$cat]);
        }
      }
    }
    $selected = $balanced;

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
   * Dampens score for events that start more than 7 days away; never below 1.
   */
  private function applyTimeDecay(int $score, int $eventStartTimestamp): int {
    if ($eventStartTimestamp < 1) {
      return max(1, $score);
    }
    $now = $this->time->getRequestTime();
    $days_until = ($eventStartTimestamp - $now) / 86400.0;
    if ($days_until <= 7) {
      return max(1, $score);
    }
    $factor = exp(-0.08 * ($days_until - 7));

    return max(1, (int) round($score * $factor));
  }

  private function getPrimaryCategory(NodeInterface $event): ?int {
    if ($event->hasField('field_category') && !$event->get('field_category')->isEmpty()) {
      $id = (int) $event->get('field_category')->first()->target_id;
      return $id > 0 ? $id : NULL;
    }
    return NULL;
  }

  private function isWeekend(): bool {
    $n = (int) date('N', (int) $this->time->getRequestTime());

    return in_array($n, [5, 6, 7], TRUE);
  }

  private function isTonight(NodeInterface $event): bool {
    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      return FALSE;
    }
    $start = strtotime($event->get('field_event_start')->value ?? '');
    if ($start === FALSE) {
      return FALSE;
    }
    $now = (int) $this->time->getRequestTime();

    return $start > $now && ($start - $now) <= 86400;
  }

  private function isWeekendEvent(NodeInterface $event): bool {
    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      return FALSE;
    }
    $start = strtotime($event->get('field_event_start')->value ?? '');
    if ($start === FALSE) {
      return FALSE;
    }

    return in_array((int) date('N', $start), [5, 6, 7], TRUE);
  }

  private function getCategoryIds(NodeInterface $node): array {
    if (!$node->hasField('field_category') || $node->get('field_category')->isEmpty()) {
      return [];
    }
    $ids = array_map(
      'intval',
      array_column($node->get('field_category')->getValue(), 'target_id')
    );

    return array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
  }

  private function sharesCategory(NodeInterface $event, ?NodeInterface $current): bool {
    if ($current === NULL) {
      return FALSE;
    }

    return array_intersect($this->getCategoryIds($event), $this->getCategoryIds($current)) !== [];
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