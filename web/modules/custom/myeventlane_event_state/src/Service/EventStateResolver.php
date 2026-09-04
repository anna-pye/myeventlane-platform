<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_state\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;

/**
 * Resolves event state based on timing, capacity, and overrides.
 */
final class EventStateResolver implements EventStateResolverInterface {

  /**
   * State constants.
   */
  const STATE_DRAFT = 'draft';
  const STATE_SCHEDULED = 'scheduled';
  const STATE_LIVE = 'live';
  const STATE_SOLD_OUT = 'sold_out';
  const STATE_ENDED = 'ended';
  const STATE_CANCELLED = 'cancelled';
  const STATE_ARCHIVED = 'archived';

  /**
   * Constructs the resolver.
   */
  public function __construct(
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly EventDateTimeResolver $eventDateTime,
    private readonly ?EventCapacityServiceInterface $capacityService = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolveState(NodeInterface $event): string {
    $cache_key = 'event_state:' . $event->id();
    $cache = $this->cache->get($cache_key);
    if ($cache) {
      return $cache->data;
    }

    $state = $this->computeState($event);
    $this->cache->set($cache_key, $state, time() + 300, ['node:' . $event->id()]);
    return $state;
  }

  /**
   * Computes the state without caching.
   */
  private function computeState(NodeInterface $event): string {
    // 1. Check override field (highest priority except admin rules).
    if ($event->hasField('field_event_state_override') && !$event->get('field_event_state_override')->isEmpty()) {
      $override = $event->get('field_event_state_override')->value;
      if (in_array($override, [self::STATE_CANCELLED, self::STATE_ARCHIVED], TRUE)) {
        return $override;
      }
    }

    // 2. If node unpublished, return draft.
    if (!$event->isPublished()) {
      return self::STATE_DRAFT;
    }

    // 3. Get timing values.
    $now = $this->time->getRequestTime();
    $salesStart = $this->getSalesStart($event);
    $salesEnd = $this->getSalesEnd($event);
    $eventEnd = $this->getEventEnd($event);

    // 4. If now < sales_start, return scheduled.
    if ($salesStart !== NULL && $now < $salesStart) {
      return self::STATE_SCHEDULED;
    }

    // 5. If now > event_end, return ended.
    if ($eventEnd !== NULL && $now > $eventEnd) {
      return self::STATE_ENDED;
    }

    // 6. Check if sold out (requires capacity service).
    if ($this->isSoldOut($event)) {
      return self::STATE_SOLD_OUT;
    }

    // 7. If now is inside the sales window, return live.
    if ($salesStart !== NULL && $salesEnd !== NULL) {
      if ($now >= $salesStart && $now <= $salesEnd) {
        return self::STATE_LIVE;
      }
    }
    elseif ($salesStart !== NULL && $now >= $salesStart) {
      // Sales started but no end date - check event end.
      if ($eventEnd === NULL || $now <= $eventEnd) {
        return self::STATE_LIVE;
      }
    }
    elseif ($salesEnd !== NULL && $now <= $salesEnd) {
      // Sales end set but no start - assume sales are open.
      return self::STATE_LIVE;
    }
    else {
      // No sales windows set - use event timing.
      $eventStart = $this->getEventStart($event);
      if ($eventStart !== NULL && $now >= $eventStart) {
        if ($eventEnd === NULL || $now <= $eventEnd) {
          return self::STATE_LIVE;
        }
      }
    }

    // Default to scheduled if we have future dates.
    if ($salesStart !== NULL && $now < $salesStart) {
      return self::STATE_SCHEDULED;
    }

    // Fallback to live if event exists and is published.
    return self::STATE_LIVE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSalesStart(NodeInterface $event): ?int {
    $timestamp = $this->eventDateTime->getFieldTimestamp($event, 'field_sales_start');
    if ($timestamp !== NULL) {
      return $timestamp;
    }

    // Default: use publish time.
    return (int) $event->getCreatedTime();
  }

  /**
   * {@inheritdoc}
   */
  public function getSalesEnd(NodeInterface $event): ?int {
    $timestamp = $this->eventDateTime->getFieldTimestamp($event, 'field_sales_end');
    if ($timestamp !== NULL) {
      return $timestamp;
    }

    // Default: use event end time.
    return $this->getEventEnd($event);
  }

  /**
   * Gets the event start timestamp.
   */
  private function getEventStart(NodeInterface $event): ?int {
    return $this->eventDateTime->getFieldTimestamp($event, 'field_event_start');
  }

  /**
   * Gets the event end timestamp.
   */
  private function getEventEnd(NodeInterface $event): ?int {
    return $this->eventDateTime->getFieldTimestamp($event, 'field_event_end');
  }

  /**
   * Checks if event is sold out.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if sold out.
   */
  private function isSoldOut(NodeInterface $event): bool {
    if ($this->capacityService) {
      return $this->capacityService->isSoldOut($event);
    }

    // Fallback: check if service exists via container.
    if (\Drupal::hasService('myeventlane_capacity.service')) {
      try {
        $capacityService = \Drupal::service('myeventlane_capacity.service');
        return $capacityService->isSoldOut($event);
      }
      catch (\Exception $e) {
        return FALSE;
      }
    }

    return FALSE;
  }

}
