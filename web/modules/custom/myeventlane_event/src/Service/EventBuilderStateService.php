<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;

/**
 * Single source of truth for vendor event builder UI state (mode, completeness).
 *
 * Mode matches ticket-tier mix rules used by the event form and preview. Tickets
 * are loaded via TicketTypeManager (same as EventFormAlter); no new storage.
 */
final class EventBuilderStateService {

  public function __construct(
    private readonly TicketTypeManager $ticketTypeManager,
  ) {}

  /**
   * Builds serializable state for the MEL builder (PHP attributes + hidden element + JS).
   *
   * @return array{
   *   mode: string,
   *   hasTickets: bool,
   *   isSaved: bool,
   *   hasLocation: bool,
   *   hasImage: bool,
   *   score: int
   * }
   */
  public function buildState(NodeInterface $event): array {
    $tickets = $this->getTickets($event);
    $mode = $this->deriveMode($tickets);

    return [
      'mode' => $mode,
      'hasTickets' => $tickets !== [],
      'isSaved' => !$event->isNew(),
      'hasLocation' => $event->hasField('field_location') && !$event->get('field_location')->isEmpty(),
      'hasImage' => $event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty(),
      'score' => $this->calculateScore($event, $tickets),
    ];
  }

  /**
   * @param list<TicketTypeInterface> $tickets
   */
  private function deriveMode(array $tickets): string {
    if ($tickets === []) {
      return 'none';
    }
    $kinds = [];
    foreach ($tickets as $t) {
      $kinds[$t->getTicketKind()] = TRUE;
    }
    $hasPaid = !empty($kinds['paid']);
    $hasRsvp = !empty($kinds['rsvp']);
    $hasExternal = !empty($kinds['external']);

    if ($hasExternal && !$hasPaid && !$hasRsvp) {
      return 'external';
    }
    if ($hasPaid && $hasRsvp) {
      return 'both';
    }
    if ($hasPaid) {
      return 'paid';
    }
    if ($hasRsvp) {
      return 'rsvp';
    }
    return 'rsvp';
  }

  /**
   * @param list<TicketTypeInterface> $tickets
   */
  private function calculateScore(NodeInterface $event, array $tickets): int {
    $score = 0;

    if (trim((string) ($event->label() ?? '')) !== '') {
      $score += 20;
    }
    if ($tickets !== []) {
      $score += 25;
    }
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $score += 15;
    }
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $score += 15;
    }
    if ($event->hasField('body') && !$event->get('body')->isEmpty()) {
      $score += 10;
    }
    if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
      $score += 15;
    }

    return min(100, $score);
  }

  /**
   * @return list<TicketTypeInterface>
   */
  private function getTickets(NodeInterface $event): array {
    return array_values($this->ticketTypeManager->loadEventTicketTypes($event));
  }

}
