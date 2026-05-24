<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\node\NodeInterface;

/**
 * Canonical rules for whether an event belongs on public discovery surfaces.
 *
 * Views should continue to express these rules as filters where possible; this
 * service is the single PHP source of truth for controllers, APIs, and SEO.
 */
final class PublicEventVisibility {

  /**
   * field_event_state values that must never appear in public listings.
   */
  private const EXCLUDED_STATES = [
    EventStateResolver::STATE_DRAFT,
    EventStateResolver::STATE_CANCELLED,
    EventStateResolver::STATE_ARCHIVED,
  ];

  public function __construct(
    private readonly EventStateResolverInterface $eventStateResolver,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Whether a published event may appear on public discovery listings or SEO.
   */
  public function isPubliclyListable(NodeInterface $event): bool {
    if ($event->bundle() !== 'event' || !$event->isPublished()) {
      return FALSE;
    }

    if ($this->isExcludedLifecycleState($event)) {
      return FALSE;
    }

    if ($this->hasEnded($event)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Whether the event is still in progress or upcoming (not past).
   */
  public function isUpcomingOrCurrent(NodeInterface $event): bool {
    return !$this->hasEnded($event);
  }

  /**
   * field_event_state values excluded from public discovery (for Views filters).
   *
   * @return list<string>
   */
  public function getExcludedLifecycleStates(): array {
    return self::EXCLUDED_STATES;
  }

  /**
   * Checks stored lifecycle state on the event node.
   */
  private function isExcludedLifecycleState(NodeInterface $event): bool {
    if (!$event->hasField('field_event_state') || $event->get('field_event_state')->isEmpty()) {
      return FALSE;
    }

    $state = (string) $event->get('field_event_state')->value;
    if (in_array($state, self::EXCLUDED_STATES, TRUE)) {
      return TRUE;
    }

    return $state === EventStateResolver::STATE_ENDED;
  }

  /**
   * Whether the event has ended by stored state or end datetime.
   */
  private function hasEnded(NodeInterface $event): bool {
    if ($event->hasField('field_event_state') && !$event->get('field_event_state')->isEmpty()) {
      if ((string) $event->get('field_event_state')->value === EventStateResolver::STATE_ENDED) {
        return TRUE;
      }
    }

    if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
      $end = $event->get('field_event_end')->date;
      if ($end !== NULL && $end->getTimestamp() < $this->time->getRequestTime()) {
        return TRUE;
      }
    }

    return $this->eventStateResolver->resolveState($event) === EventStateResolver::STATE_ENDED;
  }

}
