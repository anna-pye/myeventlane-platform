<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Entity\EntityInterface;

/**
 * Shared event metadata extraction for legacy PDF compatibility surfaces.
 *
 * This helper encodes the frozen legacy PDF contract for location strings:
 * only postal components from field_location (no field_venue_name shortcut).
 * Canonical issued-ticket PDFs continue to use UniversalTicketViewModelBuilder,
 * which applies different precedence and must remain authoritative there.
 */
final class TicketPdfEventMetadataHelper {

  /**
   * Title, start datetime raw value, and legacy location line for PDF themes.
   *
   * @return array{title: string, start: ?string, location: string}
   */
  public function legacyPdfRowsForEvent(EntityInterface $event): array {
    $event_start = NULL;
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $event_start = (string) $event->get('field_event_start')->value;
    }

    return [
      'title' => $event->label(),
      'start' => $event_start,
      'location' => $this->locationFromFieldLocation($event),
    ];
  }

  /**
   * Legacy PDF location: field_location address parts only.
   */
  public function locationFromFieldLocation(EntityInterface $event): string {
    if (!$event->hasField('field_location') || $event->get('field_location')->isEmpty()) {
      return '';
    }
    $address_field = $event->get('field_location')->first();
    if (!$address_field) {
      return '';
    }
    $address = $address_field->getValue();
    $parts = [];
    if (!empty($address['address_line1'])) {
      $parts[] = $address['address_line1'];
    }
    if (!empty($address['address_line2'])) {
      $parts[] = $address['address_line2'];
    }
    if (!empty($address['locality'])) {
      $parts[] = $address['locality'];
    }
    if (!empty($address['administrative_area'])) {
      $parts[] = $address['administrative_area'];
    }
    if (!empty($address['postal_code'])) {
      $parts[] = $address['postal_code'];
    }

    return implode(', ', $parts);
  }

}
