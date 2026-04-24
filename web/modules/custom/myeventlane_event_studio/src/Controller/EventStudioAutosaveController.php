<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Component\Utility\Tags;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\myeventlane_event_studio\Service\EventHighlightHelper;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * POST /vendor/events/autosave — draft save from FormData.
 */
final class EventStudioAutosaveController {

  public static function create(ContainerInterface $container) {
  return new static(
    $container->get('entity_type.manager'),
    $container->get('current_user'),
    $container->get('request_stack'),
    $container->get('logger.factory'),
    $container->get('datetime.time'),
    $container->get('your.new_service') // <-- MUST exist
    );
  }

  public function handle(Request $request): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['ok' => FALSE], 405);
    }

    $account = $this->currentUser;
    $params = $request->request->all();

    // TEMP mel_debug: remove after verifying autosave mel_autosave_ts + stale compare.
    $this->loggerFactory->get('mel_debug')->notice('TS: @ts', [
      '@ts' => (string) ($request->request->get('mel_autosave_ts') ?? ''),
    ]);

    $storage = $this->entityTypeManager->getStorage('node');
    $node = NULL;
    if (!empty($params['nid'])) {
      $loaded = $storage->load((int) $params['nid']);
      if (!$loaded instanceof NodeInterface || $loaded->bundle() !== 'event') {
        return new JsonResponse(['ok' => FALSE, 'message' => 'Not found'], 404);
      }
      if (!$loaded->access('update', $account)) {
        throw new AccessDeniedHttpException();
      }
      $node = $loaded;
    }

    $incoming_autosave_ts = $this->parseAutosaveTimestamp($params);
    if ($node !== NULL && $incoming_autosave_ts !== NULL) {
      $store = $this->privateTempStoreFactory->get('myeventlane_event_studio_autosave');
      $stored_ts = $store->get('node.' . $node->id());
      if ($stored_ts !== NULL && is_numeric($stored_ts) && $incoming_autosave_ts < (float) $stored_ts) {
        // TEMP mel_debug: remove after verifying stale detection.
        $this->loggerFactory->get('mel_debug')->notice('COMPARE incoming=@in stored=@st', [
          '@in' => (string) $incoming_autosave_ts,
          '@st' => (string) $stored_ts,
        ]);
        return new JsonResponse([
          'ok' => FALSE,
          'latest_ts' => (float) $stored_ts,
        ], 409);
      }
    }

    $mel = $params['mel'] ?? [];
    if (!is_array($mel)) {
      $mel = [];
    }

    $decoded_event_highlights = NULL;
    $event_highlights_items_state = NULL;
    if (array_key_exists('event_highlights', $mel)) {
      try {
        $decoded_event_highlights = $this->decodeEventHighlightsFromMel($mel['event_highlights']);
      }
      catch (\InvalidArgumentException $e) {
        return new JsonResponse([
          'ok' => FALSE,
          'errors' => [$e->getMessage()],
        ], 422);
      }
      $eh = $mel['event_highlights'];
      $event_highlights_items_state = is_array($eh) ? trim((string) ($eh['items_state'] ?? '')) : '';
    }

    $payload = $this->payloadFromRequestParams($params, $decoded_event_highlights, $event_highlights_items_state);
    $result = $this->saveService->save($payload, $node, $account, TRUE);
    if ($result['errors'] !== []) {
      return new JsonResponse(['ok' => FALSE, 'errors' => $result['errors']], 422);
    }

    $saved_node = $result['node'];
    // Always key tempstore by the node returned from save() (nid exists after first create).
    if ($saved_node !== NULL && $incoming_autosave_ts !== NULL) {
      $store = $this->privateTempStoreFactory->get('myeventlane_event_studio_autosave');
      $store->set('node.' . $saved_node->id(), (float) $incoming_autosave_ts);
      // TEMP mel_debug: remove after verifying tempstore write path.
      $this->loggerFactory->get('mel_debug')->notice('SET TS nid=@nid ts=@ts', [
        '@nid' => (string) $saved_node->id(),
        '@ts' => (string) $incoming_autosave_ts,
      ]);
    }
    elseif ($incoming_autosave_ts !== NULL) {
      // TEMP mel_debug: should not happen when errors are empty; indicates save() returned no node.
      $this->loggerFactory->get('mel_debug')->notice('SKIP SET: no saved node in result while TS present');
    }

    return new JsonResponse([
      'ok' => TRUE,
      'nid' => $saved_node?->id(),
    ]);
  }

  /**
   * @param array<string, mixed> $params
   */
  private function parseAutosaveTimestamp(array $params): ?float {
    if (!isset($params['mel_autosave_ts']) || $params['mel_autosave_ts'] === '') {
      return NULL;
    }
    if (is_numeric($params['mel_autosave_ts'])) {
      return (float) $params['mel_autosave_ts'];
    }
    return NULL;
  }

  /**
   * @param array<string, mixed> $params
   * @param list<array{text: string, icon: string, weight: int}>|null $decoded_event_highlights
   *
   * @return array<string, mixed>
   */
  private function payloadFromRequestParams(array $params, ?array $decoded_event_highlights = NULL, ?string $event_highlights_items_state = NULL): array {
    $mel = $params['mel'] ?? [];
    if (!is_array($mel)) {
      $mel = [];
    }

    $choice = (string) ($mel['venue_mode'] ?? $params['venue_choice'] ?? 'one_off');
    $field_location = $choice === 'saved' ? [] : ($mel['field_location'] ?? $params['field_location'] ?? '');

    $raw_venue = $mel['venue_saved'] ?? $params['venue']['field_venue'] ?? $params['venue_id'] ?? NULL;
    $venue_id = NULL;
    if ($choice === 'saved' && $raw_venue !== NULL && $raw_venue !== '') {
      if (is_array($raw_venue) && isset($raw_venue[0]['target_id'])) {
        $venue_id = (int) $raw_venue[0]['target_id'];
      }
      elseif (is_numeric($raw_venue)) {
        $venue_id = (int) $raw_venue;
      }
      elseif (is_string($raw_venue)) {
        $eid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw_venue);
        $venue_id = $eid !== NULL ? (int) $eid : NULL;
      }
    }

    $ticket_type = (string) ($mel['field_event_type'] ?? $params['tickets']['field_event_type'] ?? $params['field_event_type'] ?? 'rsvp');
    $capacity_raw = $mel['rsvp_capacity'] ?? $params['capacity'] ?? NULL;
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

    $image_fids = $mel['field_event_image'] ?? [];
    $image_fid = 0;
    if (is_array($image_fids) && $image_fids !== []) {
      $image_fid = (int) reset($image_fids);
    }

    $payload = [
      'title' => $mel['title'] ?? $params['basics']['title'] ?? $params['title'] ?? '',
      'summary' => $mel['summary'] ?? $params['basics']['summary'] ?? $params['summary'] ?? '',
      'body' => $mel['body'] ?? $params['basics']['body'] ?? $params['body'] ?? '',
      'field_event_intro' => trim((string) ($mel['field_event_intro'] ?? $params['field_event_intro'] ?? '')),
      'field_event_image' => $image_fid,
      'field_event_image_alt' => trim((string) ($mel['field_event_image_alt'] ?? $params['field_event_image_alt'] ?? '')),
      'field_contact_email' => trim((string) ($mel['field_contact_email'] ?? $params['field_contact_email'] ?? '')),
      'field_contact_phone' => trim((string) ($mel['field_contact_phone'] ?? $params['field_contact_phone'] ?? '')),
      'field_category' => $this->extractMultipleEntityIds($mel['field_category'] ?? $params['field_category'] ?? ''),
      'field_tags' => $this->extractMultipleEntityIds($mel['field_tags'] ?? $params['field_tags'] ?? ''),
      'field_accessibility' => $this->extractMultipleEntityIds($mel['field_accessibility'] ?? $params['field_accessibility'] ?? ''),
      'field_accessibility_contact' => trim((string) ($mel['field_accessibility_contact'] ?? $params['field_accessibility_contact'] ?? '')),
      'field_accessibility_directions' => trim((string) ($mel['field_accessibility_directions'] ?? $params['field_accessibility_directions'] ?? '')),
      'field_accessibility_entry' => trim((string) ($mel['field_accessibility_entry'] ?? $params['field_accessibility_entry'] ?? '')),
      'field_accessibility_parking' => trim((string) ($mel['field_accessibility_parking'] ?? $params['field_accessibility_parking'] ?? '')),
      'field_product_target' => $this->extractSingleEntityId($mel['field_product_target'] ?? $params['field_product_target'] ?? NULL),
      'field_ticket_types' => $this->extractMultipleEntityIds($mel['field_ticket_types'] ?? $params['field_ticket_types'] ?? ''),
      'venue_choice' => $choice,
      'venue_id' => $venue_id,
      'new_venue_name' => $mel['venue_create_name'] ?? $params['venue']['create']['new_venue_name'] ?? $params['new_venue_name'] ?? '',
      'field_location' => $field_location,
      'field_event_start' => $this->normalizeDatetimeFromMelRequest($mel['start_date'] ?? $params['when']['field_event_start'] ?? $params['field_event_start'] ?? NULL),
      'field_event_end' => $this->normalizeDatetimeFromMelRequest($mel['end_date'] ?? $params['when']['field_event_end'] ?? $params['field_event_end'] ?? NULL),
      'field_sales_start' => $this->normalizeDatetimeFromMelRequest($mel['field_sales_start'] ?? $params['field_sales_start'] ?? NULL),
      'field_sales_end' => $this->normalizeDatetimeFromMelRequest($mel['field_sales_end'] ?? $params['field_sales_end'] ?? NULL),
      'field_age_policy' => trim((string) ($mel['field_age_policy'] ?? $params['field_age_policy'] ?? 'all_ages')),
      'field_age_policy_note' => trim((string) ($mel['field_age_policy_note'] ?? $params['field_age_policy_note'] ?? '')),
      'field_age_restriction' => trim((string) ($mel['field_age_restriction'] ?? $params['field_age_restriction'] ?? '')),
      'field_refund_policy' => trim((string) ($mel['field_refund_policy'] ?? $params['field_refund_policy'] ?? '')),
      'field_event_type' => $ticket_type,
      'ticket_type' => $ticket_type,
      'capacity' => $capacity,
      'external_url' => trim((string) ($mel['external_url'] ?? $params['external_url'] ?? '')),
      'collect_per_ticket' => !empty($mel['collect_attendee_questions']),
      'collect_attendee_questions' => !empty($mel['collect_attendee_questions']),
      'enable_donations' => !empty($mel['enable_donations']),
      'donation_enabled' => !empty($mel['enable_donations']),
      'donation_amount' => ($mel['donation_amount'] ?? '') !== '' ? (string) $mel['donation_amount'] : NULL,
      'donation_options' => trim((string) ($mel['donation_options'] ?? '')),
      'donation_label' => trim((string) ($mel['donation_label'] ?? 'Support this event')) ?: 'Support this event',
      'status' => FALSE,
      'field_location_latitude' => $mel['field_location_latitude'] ?? $params['field_location_latitude'] ?? NULL,
      'field_location_longitude' => $mel['field_location_longitude'] ?? $params['field_location_longitude'] ?? NULL,
      'studio_ticket_tiers' => $this->decodeStudioTicketTiers($mel['studio_ticket_tiers'] ?? NULL),
    ];
    if ($decoded_event_highlights !== NULL) {
      $payload['event_highlights'] = $decoded_event_highlights;
      $payload['event_highlights_items_state'] = $event_highlights_items_state ?? '';
    }
    return $payload;
  }

  /**
   * Decodes MEL `event_highlights.items_state` JSON and normalizes via EventHighlightHelper.
   *
   * @return list<array{text: string, icon: string, weight: int}>
   */
  private function decodeEventHighlightsFromMel(mixed $raw): array {
    if (!is_array($raw) || !array_key_exists('items_state', $raw)) {
      throw new \InvalidArgumentException('Invalid event highlights payload.');
    }
    $json = trim((string) $raw['items_state']);
    if ($json === '') {
      return [];
    }
    try {
      $decoded = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new \InvalidArgumentException('Highlights data could not be read.');
    }
    if (!is_array($decoded)) {
      throw new \InvalidArgumentException('Highlights data could not be read.');
    }
    $this->eventHighlightHelper->validateDecodedForAutosaveRequest($decoded);
    $allowed = $this->eventHighlightHelper->getAllowedIconKeys();

    return $this->eventHighlightHelper->normalizeHighlights($decoded, $allowed);
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function decodeStudioTicketTiers(mixed $raw): array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    if (is_array($raw)) {
      $out = [];
      foreach ($raw as $item) {
        if (is_array($item)) {
          $out[] = $this->normalizeStudioTierRow($item);
        }
      }
      return $out;
    }
    if (!is_string($raw)) {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $out = [];
    foreach ($decoded as $item) {
      if (is_array($item)) {
        $out[] = $this->normalizeStudioTierRow($item);
      }
    }
    return $out;
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

  private function normalizeDatetimeFromMelRequest(mixed $value): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_string($value)) {
      return strlen($value) > 4 ? $value : NULL;
    }
    if (!is_array($value)) {
      return NULL;
    }
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

}
