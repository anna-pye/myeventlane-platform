<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Component\Utility\Tags;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the flat save payload from submitted `mel` values (shared with Event Studio + wizard).
 */
final class EventStudioMelPayloadService {

  public function __construct(
    private readonly EventHighlightHelper $eventHighlightHelper,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function buildFromFormState(FormStateInterface $form_state, EntityTypeManagerInterface $entityTypeManager): array {
    $mel = $form_state->getValue('mel');
    if (!is_array($mel)) {
      $mel = [];
    }

    $choice = (string) ($mel['venue_mode'] ?? 'one_off');
    $venue_id = NULL;
    if ($choice === 'saved' && !empty($mel['venue_saved'])) {
      $raw = $mel['venue_saved'];
      if (is_array($raw) && isset($raw[0]['target_id'])) {
        $venue_id = (int) $raw[0]['target_id'];
      }
      elseif (is_numeric($raw)) {
        $venue_id = (int) $raw;
      }
      elseif (is_string($raw)) {
        $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw);
        $venue_id = $eid !== NULL ? (int) $eid : NULL;
      }
    }

    $new_name = '';
    if ($choice === 'create') {
      $new_name = trim((string) ($mel['venue_create_name'] ?? ''));
    }

    $field_location = $choice === 'saved' ? [] : ($mel['field_location'] ?? '');

    $ticket_type = (string) ($mel['field_event_type'] ?? 'rsvp');
    $capacity_raw = $mel['rsvp_capacity'] ?? '';
    $capacity = NULL;
    if ($ticket_type === 'rsvp') {
      if ($capacity_raw === '' || $capacity_raw === NULL) {
        $capacity = NULL;
      }
      else {
        $cap = (int) $capacity_raw;
        $capacity = $cap > 0 ? $cap : NULL;
      }
    }

    $external_url = trim((string) ($mel['external_url'] ?? ''));
    $collect_per_ticket = !empty($mel['collect_attendee_questions']);

    $image_fids = $mel['field_event_image'] ?? [];
    $image_fid = 0;
    if (is_array($image_fids) && $image_fids !== []) {
      $image_fid = (int) reset($image_fids);
    }

    $tiers_raw = $mel['studio_ticket_tiers'] ?? '';
    $studio_ticket_tiers = [];
    if (is_string($tiers_raw) && $tiers_raw !== '') {
      try {
        $decoded = json_decode($tiers_raw, TRUE, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
          foreach ($decoded as $item) {
            if (is_array($item)) {
              $studio_ticket_tiers[] = $this->normalizeStudioTierRow($item);
            }
          }
        }
      }
      catch (\JsonException) {
        $studio_ticket_tiers = [];
      }
    }

    $payload = [
      'title' => $mel['title'] ?? '',
      'summary' => $mel['summary'] ?? '',
      'body' => $mel['body'] ?? '',
      'field_event_intro' => trim((string) ($mel['field_event_intro'] ?? '')),
      'field_event_image' => $image_fid,
      'field_event_image_alt' => trim((string) ($mel['field_event_image_alt'] ?? '')),
      'field_contact_email' => trim((string) ($mel['field_contact_email'] ?? '')),
      'field_contact_phone' => trim((string) ($mel['field_contact_phone'] ?? '')),
      'field_category' => $this->extractMultipleEntityIds($mel['field_category'] ?? ''),
      'field_tags' => $this->extractMultipleEntityIds($mel['field_tags'] ?? ''),
      'field_accessibility' => $this->extractMultipleEntityIds($mel['field_accessibility'] ?? ''),
      'field_accessibility_contact' => trim((string) ($mel['field_accessibility_contact'] ?? '')),
      'field_accessibility_directions' => trim((string) ($mel['field_accessibility_directions'] ?? '')),
      'field_accessibility_entry' => trim((string) ($mel['field_accessibility_entry'] ?? '')),
      'field_accessibility_parking' => trim((string) ($mel['field_accessibility_parking'] ?? '')),
      'field_product_target' => $this->extractSingleEntityId($mel['field_product_target'] ?? NULL),
      'field_ticket_types' => $this->extractMultipleEntityIds($mel['field_ticket_types'] ?? ''),
      'studio_ticket_tiers' => $studio_ticket_tiers,
      'venue_choice' => $choice,
      'venue_id' => $venue_id,
      'new_venue_name' => $new_name,
      'field_location' => $field_location,
      'field_event_start' => $this->normalizeDatetimeValue($mel['start_date'] ?? NULL),
      'field_event_end' => $this->normalizeDatetimeValue($mel['end_date'] ?? NULL),
      'field_sales_start' => $this->normalizeDatetimeValue($mel['field_sales_start'] ?? NULL),
      'field_sales_end' => $this->normalizeDatetimeValue($mel['field_sales_end'] ?? NULL),
      'field_age_policy' => trim((string) ($mel['field_age_policy'] ?? 'all_ages')),
      'field_age_policy_note' => trim((string) ($mel['field_age_policy_note'] ?? '')),
      'field_age_restriction' => trim((string) ($mel['field_age_restriction'] ?? '')),
      'field_refund_policy' => trim((string) ($mel['field_refund_policy'] ?? '')),
      'field_event_type' => $ticket_type,
      'ticket_type' => $ticket_type,
      'capacity' => $capacity,
      'external_url' => $external_url,
      'collect_per_ticket' => $collect_per_ticket,
      'collect_attendee_questions' => $collect_per_ticket,
      'enable_donations' => !empty($mel['enable_donations']),
      'donation_enabled' => !empty($mel['enable_donations']),
      'donation_amount' => ($mel['donation_amount'] ?? '') !== '' ? (string) $mel['donation_amount'] : NULL,
      'donation_options' => trim((string) ($mel['donation_options'] ?? '')),
      'donation_label' => trim((string) ($mel['donation_label'] ?? 'Support this event')) ?: 'Support this event',
      'status' => !empty($mel['status']),
      'field_location_latitude' => $mel['field_location_latitude'] ?? NULL,
      'field_location_longitude' => $mel['field_location_longitude'] ?? NULL,
      'event_highlights' => $this->decodeAndNormalizeEventHighlightsFromMel($mel),
      'event_highlights_items_state' => trim((string) (($mel['event_highlights'] ?? [])['items_state'] ?? '')),
    ];

    $nid = (int) ($form_state->getValue('nid') ?? 0);
    if ($nid > 0) {
      $loaded = $entityTypeManager->getStorage('node')->load($nid);
      if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
        if ($loaded->hasField('field_ticket_types')) {
          $payload['field_ticket_types'] = [];
          foreach ($loaded->get('field_ticket_types')->getValue() as $row) {
            $tid = (int) ($row['target_id'] ?? 0);
            if ($tid > 0) {
              $payload['field_ticket_types'][] = $tid;
            }
          }
        }
        // Ticket product autocomplete may be omitted from POST when hidden or unchanged; do not
        // clear field_product_target on save when the node already has a linked product.
        $pid = $payload['field_product_target'] ?? NULL;
        if (($pid === NULL || $pid < 1)
            && $loaded->hasField('field_product_target')
            && !$loaded->get('field_product_target')->isEmpty()) {
          $payload['field_product_target'] = (int) $loaded->get('field_product_target')->target_id;
        }
      }
    }

    return $payload;
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function normalizeStudioTierRow(array $row): array {
    $row['capacity'] = max(1, (int) ($row['capacity'] ?? 0));
    return $row;
  }

  /**
   * @return list<int>
   */
  private function extractMultipleEntityIds(mixed $raw): array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    if (is_array($raw)) {
      $ids = [];
      foreach ($raw as $item) {
        if (is_array($item) && isset($item['target_id'])) {
          $tid = (int) $item['target_id'];
          if ($tid > 0) {
            $ids[] = $tid;
          }
        }
        elseif (is_numeric($item)) {
          $tid = (int) $item;
          if ($tid > 0) {
            $ids[] = $tid;
          }
        }
        elseif (is_string($item)) {
          $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($item);
          if ($eid !== NULL) {
            $ids[] = (int) $eid;
          }
        }
      }
      return array_values(array_unique(array_filter($ids)));
    }
    if (is_string($raw)) {
      $ids = [];
      foreach (Tags::explode($raw) as $part) {
        $part = trim($part);
        if ($part === '') {
          continue;
        }
        $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($part);
        if ($eid !== NULL) {
          $ids[] = (int) $eid;
        }
      }
      return array_values(array_unique(array_filter($ids)));
    }
    return [];
  }

  private function extractSingleEntityId(mixed $raw): ?int {
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    if (is_numeric($raw)) {
      $id = (int) $raw;
      return $id > 0 ? $id : NULL;
    }
    if (is_string($raw)) {
      $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw);
      return $eid !== NULL ? (int) $eid : NULL;
    }
    if (is_array($raw) && isset($raw[0]['target_id'])) {
      $id = (int) $raw[0]['target_id'];
      return $id > 0 ? $id : NULL;
    }
    return NULL;
  }

  private function normalizeDatetimeValue(mixed $value): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if ($value instanceof DrupalDateTime) {
      return $value->format('Y-m-d\TH:i:s');
    }
    if (is_array($value) && isset($value['object']) && $value['object'] instanceof DrupalDateTime) {
      return $value['object']->format('Y-m-d\TH:i:s');
    }
    if (is_array($value)) {
      $date = trim((string) ($value['date'] ?? ''));
      $time = trim((string) ($value['time'] ?? ''));
      if ($date === '') {
        return NULL;
      }
      if ($time === '') {
        $time = '00:00:00';
      }
      try {
        $dt = new DrupalDateTime($date . 'T' . $time);
        return $dt->format('Y-m-d\TH:i:s');
      }
      catch (\Throwable) {
        return NULL;
      }
    }
    return is_string($value) ? $value : NULL;
  }

  /**
   * @param array<string, mixed> $mel
   *
   * @return list<array{text: string, icon: string, weight: int}>
   */
  private function decodeAndNormalizeEventHighlightsFromMel(array $mel): array {
    if (!isset($mel['event_highlights']['items_state'])) {
      return [];
    }
    $raw = trim((string) $mel['event_highlights']['items_state']);
    if ($raw === '') {
      return [];
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }
    if (!is_array($decoded)) {
      return [];
    }
    $allowed = $this->eventHighlightHelper->getAllowedIconKeys();

    return $this->eventHighlightHelper->normalizeHighlights($decoded, $allowed);
  }

}
