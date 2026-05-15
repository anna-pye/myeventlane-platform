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
    private readonly QuestionFieldTypeRegistry $fieldTypeRegistry,
  ) {}

  /**
   * Normalizes hero fid + alt from `mel` or baseline fragments.
   *
   * Supports legacy `managed_file` values (`mel[field_event_image]` fid list),
   * optional `mel[field_event_image_alt]`, and image / image_widget_crop widgets
   * (`mel[field_event_image][0][fids]`, `…[0][alt]`).
   *
   * @param array<string, mixed> $fragment
   *   Partial or full mel array containing `field_event_image` / `field_event_image_alt`.
   *
   * @return array{fid: int, alt: string}
   */
  public static function normalizeHeroFromMelFragment(array $fragment): array {
    $alt = trim((string) ($fragment['field_event_image_alt'] ?? ''));
    $raw = $fragment['field_event_image'] ?? NULL;
    if (!is_array($raw)) {
      return ['fid' => 0, 'alt' => $alt];
    }

    $delta = self::imageWidgetDeltaFromRaw($raw);
    $fid = self::firstPositiveIntFromFidsValue($delta['fids'] ?? NULL);
    if ($fid < 1 && isset($delta['target_id'])) {
      $fid = max(0, (int) $delta['target_id']);
    }
    $delta_alt = trim((string) ($delta['alt'] ?? ''));
    if ($delta_alt !== '') {
      $alt = $delta_alt;
    }
    if ($fid > 0) {
      return ['fid' => $fid, 'alt' => $alt];
    }

    if (array_key_exists('fids', $raw)) {
      return ['fid' => self::firstPositiveIntFromFidsValue($raw['fids']), 'alt' => $alt];
    }

    $fid = 0;
    foreach ($raw as $value) {
      if (is_array($value)) {
        continue;
      }
      if (is_numeric($value)) {
        $candidate = (int) $value;
        if ($candidate > 0) {
          $fid = $candidate;
          break;
        }
      }
    }

    return ['fid' => $fid, 'alt' => $alt];
  }

  /**
   * Unwraps an image field widget value to its first delta array.
   *
   * @param array<string, mixed> $raw
   *
   * @return array<string, mixed>
   */
  public static function imageWidgetDeltaFromRaw(array $raw): array {
    if (isset($raw['widget']) && is_array($raw['widget'])) {
      $raw = $raw['widget'];
    }
    if (isset($raw[0]) && is_array($raw[0])) {
      return $raw[0];
    }

    return $raw;
  }

  /**
   * Builds a `field_event_image` item from a mel fragment (branding widget save).
   *
   * @param array<string, mixed> $fragment
   *
   * @return array<string, mixed>|null
   *   NULL when no file is selected.
   */
  public static function buildHeroFieldItemFromMelFragment(array $fragment): ?array {
    $hero = self::normalizeHeroFromMelFragment($fragment);
    if ($hero['fid'] < 1) {
      return NULL;
    }

    $raw = $fragment['field_event_image'] ?? [];
    if (!is_array($raw)) {
      $raw = [];
    }
    $delta = self::imageWidgetDeltaFromRaw($raw);

    $item = [
      'target_id' => $hero['fid'],
      'alt' => $hero['alt'],
      'title' => trim((string) ($delta['title'] ?? '')),
    ];

    if (isset($delta['focal_point']) && trim((string) $delta['focal_point']) !== '') {
      $item['focal_point'] = trim((string) $delta['focal_point']);
    }

    foreach (['width', 'height'] as $key) {
      if (isset($delta[$key]) && $delta[$key] !== '' && $delta[$key] !== NULL) {
        $item[$key] = $delta[$key];
      }
    }

    return $item;
  }

  /**
   * @return int
   *   First positive file id from a managed_file / image widget fids value.
   */
  private static function firstPositiveIntFromFidsValue(mixed $fids): int {
    if ($fids === NULL || $fids === '') {
      return 0;
    }
    if (is_array($fids)) {
      foreach ($fids as $value) {
        $candidate = (int) $value;
        if ($candidate > 0) {
          return $candidate;
        }
      }
      return 0;
    }
    if (is_string($fids)) {
      $parts = preg_split('/\s+/', trim($fids));
      if (!is_array($parts)) {
        return 0;
      }
      foreach ($parts as $part) {
        if ($part === '') {
          continue;
        }
        $candidate = (int) $part;
        if ($candidate > 0) {
          return $candidate;
        }
      }
    }
    return 0;
  }

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
    $attendee_questions = $this->decodeAttendeeQuestionsFromMel($mel);
    $has_attendee_questions = $attendee_questions !== [];
    $collect_per_ticket = !empty($mel['collect_attendee_questions']) || $has_attendee_questions;

    $hero = self::normalizeHeroFromMelFragment($mel);

    $payload = [
      'title' => $mel['title'] ?? '',
      'summary' => $mel['summary'] ?? '',
      'body' => $mel['body'] ?? '',
      'field_event_intro' => trim((string) ($mel['field_event_intro'] ?? '')),
      'field_event_image' => $hero['fid'],
      'field_event_image_alt' => $hero['alt'],
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
      'attendee_questions' => $attendee_questions,
    ];

    $nid = (int) ($form_state->getValue('nid') ?? 0);
    if ($nid > 0) {
      $loaded = $entityTypeManager->getStorage('node')->load($nid);
      if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
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

  /**
   * @param array<string, mixed> $mel
   *
   * @return list<array<string, mixed>>
   */
  private function decodeAttendeeQuestionsFromMel(array $mel): array {
    $raw = '[]';
    if (isset($mel['attendee_questions_editor']) && is_array($mel['attendee_questions_editor'])
        && array_key_exists('items_state', $mel['attendee_questions_editor'])) {
      $raw = trim((string) $mel['attendee_questions_editor']['items_state']);
    }
    elseif (isset($mel['attendee_questions_items'])) {
      $raw = trim((string) $mel['attendee_questions_items']);
    }
    if ($raw === '') {
      $raw = '[]';
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
    $out = [];
    foreach ($decoded as $row) {
      if (!is_array($row)) {
        continue;
      }
      $vendor_id = isset($row['vendor_question_id']) ? (int) $row['vendor_question_id'] : 0;
      if ($vendor_id > 0) {
        $out[] = ['vendor_question_id' => $vendor_id];
        continue;
      }
      $label = trim((string) ($row['label'] ?? ''));
      $type = $this->fieldTypeRegistry->normalize((string) ($row['type'] ?? 'textfield'));
      if ($label === '') {
        continue;
      }
      if (!$this->fieldTypeRegistry->isSupported($type)) {
        $type = 'textfield';
      }
      $item = [
        'label' => $label,
        'type' => $type,
        'required' => !empty($row['required']),
        'save_to_library' => !empty($row['save_to_library']),
      ];
      $status = trim((string) ($row['status'] ?? ''));
      if ($status !== '') {
        $item['status'] = $status;
      }
      $applicability = trim((string) ($row['applicability'] ?? ''));
      if ($applicability !== '') {
        $item['applicability'] = $applicability;
      }
      if (isset($row['ticket_type_ids']) && is_array($row['ticket_type_ids'])) {
        $item['ticket_type_ids'] = $row['ticket_type_ids'];
      }
      $machine = trim((string) ($row['machine_name'] ?? ''));
      if ($machine !== '') {
        $item['machine_name'] = $machine;
      }
      $option_lines = [];
      if (isset($row['options']) && is_array($row['options'])) {
        foreach ($row['options'] as $opt) {
          $t = trim((string) $opt);
          if ($t !== '') {
            $option_lines[] = $t;
          }
        }
      }
      if ($option_lines !== []) {
        $item['options'] = $option_lines;
      }
      $out[] = $item;
    }
    return $out;
  }

}
