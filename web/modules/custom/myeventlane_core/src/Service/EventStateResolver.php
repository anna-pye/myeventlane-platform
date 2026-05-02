<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\mel_ticket\Entity\TicketType;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides canonical event state resolution for MyEventLane.
 *
 * This service centralises reading and normalising ticket and product
 * presence, RSVP capacity state, venue and capacity numbers, and boost or
 * promotion state from the event node (and wizard values where applicable).
 * Feature code should use this service rather than reimplementing the same
 * field checks in multiple places.
 */
final class EventStateResolver {

  /**
   * Constructs the resolver.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger, typically the myeventlane_core channel, for API failures.
   * @param mixed $boostManager
   *   Optional MyEventLane boost manager. When missing, use field only.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly mixed $boostManager = NULL,
  ) {}

  /**
   * Aggregates per-type capacity and price data from the event ticket field.
   *
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or null.
   *
   * @return array
   *   paragraph_count, has_finite_quantities, has_unlimited_paragraph,
   *   total_finite_qty, prices.
   */
  public function analyzeTicketParagraphs(?NodeInterface $event): array {
    $defaults = [
      'paragraph_count' => 0,
      'has_finite_quantities' => FALSE,
      'has_unlimited_paragraph' => FALSE,
      'total_finite_qty' => 0,
      'prices' => [],
    ];
    if (
      !$event
      || !$event->hasField('field_ticket_types')
      || $event->get('field_ticket_types')->isEmpty()
    ) {
      return $defaults;
    }
    $count = 0;
    $hasFinite = FALSE;
    $hasUnlimited = FALSE;
    $totalFinite = 0;
    $prices = [];
    foreach ($event->get('field_ticket_types')->referencedEntities() as $ticket) {
      if (!$ticket instanceof TicketType) {
        continue;
      }
      if ($ticket->isArchived()) {
        continue;
      }
      $count++;
      if ($ticket->get('capacity')->isEmpty()) {
        $hasUnlimited = TRUE;
      }
      else {
        $n = (int) ($ticket->get('capacity')->value ?? 0);
        if ($n > 0) {
          $hasFinite = TRUE;
          $totalFinite += $n;
        }
        else {
          $hasUnlimited = TRUE;
        }
      }
      $price = $ticket->toPriceValue();
      if ($price) {
        $prices[] = (float) $price->getNumber();
      }
    }
    return [
      'paragraph_count' => $count,
      'has_finite_quantities' => $hasFinite,
      'has_unlimited_paragraph' => $hasUnlimited,
      'total_finite_qty' => $totalFinite,
      'prices' => $prices,
    ];
  }

  /**
   * Merges saved ticket prices and live form prices into a min and max.
   *
   * @param list<float> $savedPrices
   *   Prices from stored ticket type entities.
   * @param list<mixed> $livePrices
   *   In-progress wizard price values.
   *
   * @return array
   *   Array with 'min' and 'max' keys, each a float or null.
   */
  public function mergePriceStats(array $savedPrices, array $livePrices): array {
    $nums = $savedPrices;
    foreach ($livePrices as $p) {
      if (is_numeric($p)) {
        $nums[] = (float) $p;
      }
    }
    $min = NULL;
    $max = NULL;
    foreach ($nums as $n) {
      if ($min === NULL || $n < $min) {
        $min = $n;
      }
      if ($max === NULL || $n > $max) {
        $max = $n;
      }
    }
    return ['min' => $min, 'max' => $max];
  }

  /**
   * Returns whether the event references a product target.
   *
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or null.
   *
   * @return bool
   *   TRUE if field_product_target is present and non-empty.
   */
  public function hasProductTarget(?NodeInterface $event): bool {
    if (!$event || !$event->hasField('field_product_target')) {
      return FALSE;
    }
    return !$event->get('field_product_target')->isEmpty();
  }

  /**
   * Returns the normalised event type (wizard or saved node value).
   *
   * @param array<string, mixed> $values
   *   Wizard or inline values (e.g. field_event_type).
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or null.
   *
   * @return string
   *   A known type: rsvp, paid, both, external, or an empty string.
   */
  public function effectiveEventType(array $values, ?NodeInterface $event): string {
    $raw = (string) ($values['field_event_type'] ?? '');
    if ($raw !== '') {
      return $this->normalizeEventType($raw);
    }
    if ($event && $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      return $this->normalizeEventType((string) $event->get('field_event_type')->value);
    }
    return '';
  }

  /**
   * Returns a non-negative venue or capacity value from the wizard or node.
   *
   * @param array<string, mixed> $values
   *   Wizard values including field_capacity.
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or null.
   *
   * @return int
   *   Parsed capacity, or 0.
   */
  public function effectiveVenueCapacity(array $values, ?NodeInterface $event): int {
    $raw = $values['field_capacity'] ?? NULL;
    if ($raw !== NULL && $raw !== '') {
      if (is_numeric($raw)) {
        return max(0, (int) $raw);
      }
    }
    if ($event && $event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      return max(0, (int) $event->get('field_capacity')->value);
    }
    return 0;
  }

  /**
   * Returns RSVP or overall capacity as unset, unlimited, or limited.
   *
   * @param array<string, mixed> $values
   *   Wizard values including field_capacity.
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or null.
   *
   * @return string
   *   One of: unset, unlimited, limited.
   */
  public function effectiveRsvpCapacityState(array $values, ?NodeInterface $event): string {
    $raw = $values['field_capacity'] ?? NULL;
    if ($raw !== NULL && $raw !== '') {
      return $this->normalizeCapacityState($raw);
    }
    if ($event && $event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      return $this->normalizeCapacityState($event->get('field_capacity')->value);
    }
    return 'unset';
  }

  /**
   * Whether the event is boosted via the service or the promotion field.
   *
   * If the optional boost service is missing, the field is used. If present,
   * the service is tried first; on exception, the field is used.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if boosted.
   */
  public function isEventBoosted(NodeInterface $event): bool {
    if ($this->boostManager) {
      if (method_exists($this->boostManager, 'isBoosted')) {
        try {
          return (bool) $this->boostManager->isBoosted($event);
        }
        catch (\Throwable $e) {
          $this->logger->warning(
            'Event boost check failed while resolving event state: @message',
            ['@message' => $e->getMessage()]
          );
        }
      }
    }
    if ($event->hasField('field_promoted') && !$event->get('field_promoted')->isEmpty()) {
      return (bool) $event->get('field_promoted')->value;
    }
    return FALSE;
  }

  /**
   * Returns the canonical event domain state snapshot for templates and UIs.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array{
   *   has_product: bool,
   *   is_boosted: bool,
   *   rsvp_state: string,
   *   capacity: int
   * }
   *   Normalised state from field-based resolver methods. Capacity uses venue
   *   field only (same as effectiveVenueCapacity with no wizard values).
   */
  public function getEventDomainState(NodeInterface $event): array {
    return [
      'has_product' => $this->hasProductTarget($event),
      'is_boosted' => $this->isEventBoosted($event),
      'rsvp_state' => $this->effectiveRsvpCapacityState([], $event),
      'capacity' => $this->effectiveVenueCapacity([], $event),
    ];
  }

  /**
   * Maps a string to a known event type, or returns empty.
   */
  private function normalizeEventType(string $raw): string {
    $raw = mb_strtolower(trim($raw));
    return in_array($raw, ['rsvp', 'paid', 'both', 'external'], TRUE) ? $raw : '';
  }

  /**
   * Interprets capacity field data as unset, unlimited, or limited.
   */
  private function normalizeCapacityState(mixed $venueCapacityOrRaw): string {
    if ($venueCapacityOrRaw === NULL || $venueCapacityOrRaw === '') {
      return 'unset';
    }
    if (is_int($venueCapacityOrRaw) || is_float($venueCapacityOrRaw)) {
      $n = (int) $venueCapacityOrRaw;
      return $n > 0 ? 'limited' : 'unlimited';
    }
    if (is_string($venueCapacityOrRaw)) {
      $trim = trim($venueCapacityOrRaw);
      if ($trim === '') {
        return 'unset';
      }
      if (is_numeric($trim)) {
        $n = (int) $trim;
        return $n > 0 ? 'limited' : 'unlimited';
      }
    }
    return 'unset';
  }

}
