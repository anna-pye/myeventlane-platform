<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\mel_ticket\Entity\TicketType;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves event, ticket, RSVP capacity, and boost state (pure logic).
 */
final class EventStateResolver {

  /**
   * Constructs an EventStateResolver.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for boost status failures.
   * @param mixed $boostManager
   *   Optional boost manager service, if available.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly mixed $boostManager = NULL,
  ) {}

  /**
   * Analyses ticket type paragraphs on the event node.
   *
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or null.
   *
   * @return array
   *   Keys: paragraph_count, has_finite_quantities, has_unlimited_paragraph,
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
    if (!$event || !$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
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
   * Merges saved and live price lists into min/max stats.
   *
   * @param list<float> $savedPrices
   *   Prices from stored ticket types.
   * @param list<mixed> $livePrices
   *   Live wizard price values.
   *
   * @return array
   *   With keys 'min' and 'max' (float or null each).
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
   * Whether the event has a commerce product target reference.
   *
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or null.
   *
   * @return bool
   *   TRUE if field_product_target is non-empty.
   */
  public function hasProductTarget(?NodeInterface $event): bool {
    if (!$event || !$event->hasField('field_product_target')) {
      return FALSE;
    }
    return !$event->get('field_product_target')->isEmpty();
  }

  /**
   * Effective event type from wizard values or the saved node.
   *
   * @param array<string, mixed> $values
   *   Live wizard values.
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or null.
   *
   * @return string
   *   Normalised type: rsvp, paid, both, external, or empty string.
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
   * Effective numeric venue/capacity from wizard or node field_capacity.
   *
   * @param array<string, mixed> $values
   *   Live wizard values.
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node, or null.
   *
   * @return int
   *   Non-negative capacity, or 0.
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
   * RSVP / event capacity field: unset vs unlimited (0) vs limited (>0).
   *
   * @param array<string, mixed> $values
   *   Live wizard values.
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
   * Whether the event is currently boosted (service or field_promoted).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if boosted.
   */
  public function isEventBoosted(NodeInterface $event): bool {
    if ($this->boostManager && method_exists($this->boostManager, 'isBoosted')) {
      try {
        return (bool) $this->boostManager->isBoosted($event);
      }
      catch (\Throwable $e) {
        $this->logger->notice('Boost status check failed: @m', ['@m' => $e->getMessage()]);
      }
    }
    if ($event->hasField('field_promoted') && !$event->get('field_promoted')->isEmpty()) {
      return (bool) $event->get('field_promoted')->value;
    }
    return FALSE;
  }

  /**
   * Normalises raw event type string to known vocabulary.
   *
   * @param string $raw
   *   Raw value from form or field.
   *
   * @return string
   *   Normalised type or empty.
   */
  private function normalizeEventType(string $raw): string {
    $raw = mb_strtolower(trim($raw));
    return in_array($raw, ['rsvp', 'paid', 'both', 'external'], TRUE) ? $raw : '';
  }

  /**
   * Maps capacity field value to unset, unlimited, or limited.
   *
   * @param mixed $venueCapacityOrRaw
   *   Field value.
   *
   * @return string
   *   One of: unset, unlimited, limited.
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
