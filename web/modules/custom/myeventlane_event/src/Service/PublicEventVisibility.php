<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
   * Obvious internal staging markers in titles (fallback when no status field).
   *
   * @var list<string>
   */
  private const INTERNAL_TITLE_MARKERS = [
    'test',
    'demo',
    'copy',
    'untitled',
  ];

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

    if ($this->hasInternalMarkerTitle($event->label())) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Whether a title contains obvious internal staging markers.
   */
  public function hasInternalMarkerTitle(string $title): bool {
    $normalized = trim(mb_strtolower($title));
    if ($normalized === '') {
      return TRUE;
    }

    foreach (self::INTERNAL_TITLE_MARKERS as $marker) {
      if ($normalized === $marker) {
        return TRUE;
      }
      if (str_starts_with($normalized, $marker . ' ')
        || str_starts_with($normalized, $marker . '-')
        || str_contains($normalized, ' ' . $marker . ' ')
        || str_contains($normalized, ' ' . $marker)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * SQL LIKE patterns for excluding internal marker titles in Views queries.
   *
   * @return list<string>
   */
  public function getInternalTitleSqlLikePatterns(): array {
    $patterns = [];
    foreach (self::INTERNAL_TITLE_MARKERS as $marker) {
      $patterns[] = $marker;
      $patterns[] = $marker . ' %';
      $patterns[] = $marker . '-%';
      $patterns[] = '% ' . $marker;
      $patterns[] = '% ' . $marker . ' %';
    }

    return $patterns;
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

  /**
   * Replaces obvious placeholder copy for public event display surfaces.
   *
   * Returns NULL when the source text should not be shown publicly.
   */
  public function sanitizePublicDisplayText(?string $text): ?string {
    if ($text === NULL || trim($text) === '') {
      return NULL;
    }

    $plain = trim(strip_tags($text));
    if ($plain === '') {
      return NULL;
    }

    $markers = [
      '[date]',
      '[time]',
      '[venue]',
      'lorem ipsum',
    ];
    foreach ($markers as $marker) {
      if (stripos($plain, $marker) !== FALSE) {
        return NULL;
      }
    }

    return $text;
  }

  /**
   * Fallback copy when public teaser/highlight text is withheld.
   */
  public function publicDisplayFallbackMessage(): string {
    return (string) new TranslatableMarkup('More details coming soon.');
  }

}
