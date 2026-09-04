<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Component\Utility\Tags;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event_studio\EventStudioSectionManager;
use Drupal\myeventlane_event_studio\Service\EventHighlightHelper;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EntityAutocompleteMelNormalizer;
use Drupal\myeventlane_event_studio\Service\EventStudioMelPayloadService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_event_studio\Plugin\EventStudioSection\EventStudioSectionInterface;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolver;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * POST /vendor/events/autosave — draft save from FormData.
 */
final class EventStudioAutosaveController {

  public function __construct(
    private readonly EventStudioSaveService $saveService,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventHighlightHelper $eventHighlightHelper,
    private readonly LoggerInterface $logger,
    private readonly AccessManagerInterface $accessManager,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly CurrentVendorResolver $currentVendorResolver,
    private readonly EventStudioAutosaveService $autosaveService,
    private readonly EventStudioSectionManager $sectionManager,
    private readonly FormBuilderInterface $formBuilder,
    private readonly EntityAutocompleteMelNormalizer $entityAutocompleteMelNormalizer,
  ) {}

  public function handle(Request $request): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['ok' => FALSE], 405);
    }

    $account = $this->currentUser;
    if ($account->isAnonymous()) {
      $this->logger->warning('Event Studio autosave denied: anonymous');
      throw new AccessDeniedHttpException();
    }

    $params = $request->request->all();

    $storage = $this->entityTypeManager->getStorage('node');
    if (empty($params['nid'])) {
      if (!$this->accessManager->checkNamedRoute('myeventlane_event_studio.create', [], $account, TRUE)->isAllowed()) {
        $this->logger->warning('Event Studio autosave denied: create route access uid=@uid', [
          '@uid' => (string) $account->id(),
        ]);
        throw new AccessDeniedHttpException();
      }
      return new JsonResponse([
        'ok' => FALSE,
        'errors' => ['Open the Event Studio draft before autosaving.'],
      ], 422);
    }

    $loaded = $storage->load((int) $params['nid']);
    if (!$loaded instanceof NodeInterface || $loaded->bundle() !== 'event') {
      return new JsonResponse(['ok' => FALSE, 'message' => 'Not found'], 404);
    }
    $this->assertCanAutosaveEvent($loaded);
    $node = $loaded;

    $incoming_autosave_ts = $this->parseAutosaveTimestamp($params);
    if ($incoming_autosave_ts === NULL) {
      $incoming_autosave_ts = (float) round(microtime(TRUE) * 1000);
    }

    $section = $this->normalizeSection($params['mel_studio_section'] ?? 'section');
    $section_error = $this->validateAutosaveSection($section, $node);
    if ($section_error instanceof JsonResponse) {
      return $section_error;
    }

    $base_changed = $this->parsePositiveInt($params['mel_studio_changed'] ?? NULL);
    $base_revision_id = $this->parsePositiveInt($params['mel_studio_revision'] ?? NULL);
    if ($this->autosaveService->isStaleSubmission($node, $base_changed, $base_revision_id)) {
      $this->autosaveService->clearDraft($node, $section);
      return new JsonResponse([
        'ok' => FALSE,
        'message' => 'This section was updated elsewhere. Refresh to continue editing safely.',
      ], 409);
    }

    $mel = $params['mel'] ?? [];
    if (!is_array($mel)) {
      $mel = [];
    }
    $mel = $this->normalizeMelForSection($section, $node, $mel);

    $decoded_event_highlights = NULL;
    $event_highlights_items_state = NULL;
    if (array_key_exists('event_highlights', $mel)) {
      try {
        $decoded_event_highlights = $this->decodeEventHighlightsFromMel($mel['event_highlights']);
      }
      catch (\InvalidArgumentException $e) {
        $this->logger->warning('Autosave event highlights rejected: @message', [
          '@message' => $e->getMessage(),
        ]);
        return new JsonResponse([
          'ok' => FALSE,
          'errors' => [$e->getMessage()],
        ], 422);
      }
      $eh = $mel['event_highlights'];
      $event_highlights_items_state = is_array($eh) ? trim((string) ($eh['items_state'] ?? '')) : '';
    }

    if ($decoded_event_highlights !== NULL) {
      $mel['event_highlights'] = [
        'items_state' => $event_highlights_items_state ?? '',
        'decoded' => $decoded_event_highlights,
      ];
    }
    $this->autosaveService->storeDraft($node, $section, $mel, $incoming_autosave_ts, $base_changed, $base_revision_id);

    return new JsonResponse([
      'ok' => TRUE,
      'nid' => $node->id(),
      'section' => $section,
      'message' => 'Saved just now',
      'restore_available' => TRUE,
      'changed' => $node->getChangedTime(),
      'revision_id' => (int) $node->getRevisionId(),
      'governance_urls' => $this->buildGovernanceUrls($node),
    ]);
  }

  private function assertCanAutosaveEvent(NodeInterface $event): void {
    $account = $this->currentUser;
    if ($account->hasPermission('administer nodes')) {
      return;
    }

    $vendor = $this->currentVendorResolver->resolveFromEvent($event)
      ?? $this->currentVendorResolver->resolveFromUser($account);
    if ($vendor === NULL) {
      $this->logger->warning('Event Studio autosave denied: no vendor context nid=@nid uid=@uid', [
        '@nid' => (string) $event->id(),
        '@uid' => (string) $account->id(),
      ]);
      throw new AccessDeniedHttpException();
    }

    // Workspace parity (organiser owner / team) is sufficient — same as
    // EventStudioAccess. Node entity update alone would block non-authors.
    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      $this->logger->warning('Event Studio autosave denied: workspace parity nid=@nid uid=@uid', [
        '@nid' => (string) $event->id(),
        '@uid' => (string) $account->id(),
      ]);
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function buildGovernanceUrls(NodeInterface $node): array {
    $nid = (int) $node->id();
    if ($nid <= 0) {
      return [];
    }

    return [
      'governanceRefresh' => Url::fromRoute('myeventlane_event_studio.governance_refresh', ['node' => $nid])->toString(),
      'governanceComponents' => [
        'intelligence' => Url::fromRoute('myeventlane_event_studio.governance_component', [
          'node' => $nid,
          'component' => 'intelligence',
        ])->toString(),
        'workflow' => Url::fromRoute('myeventlane_event_studio.governance_component', [
          'node' => $nid,
          'component' => 'workflow',
        ])->toString(),
        'state' => Url::fromRoute('myeventlane_event_studio.governance_component', [
          'node' => $nid,
          'component' => 'state',
        ])->toString(),
        'continuity' => Url::fromRoute('myeventlane_event_studio.governance_component', [
          'node' => $nid,
          'component' => 'continuity',
        ])->toString(),
      ],
    ];
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

  private function parsePositiveInt(mixed $value): int {
    if (!is_numeric($value)) {
      return 0;
    }
    $parsed = (int) $value;
    return $parsed > 0 ? $parsed : 0;
  }

  private function normalizeSection(mixed $value): string {
    $section = trim((string) $value);
    if ($section === '') {
      return 'section';
    }
    return preg_replace('/[^a-z0-9_:-]+/i', '_', $section) ?: 'section';
  }

  private function validateAutosaveSection(string $section, NodeInterface $node): ?JsonResponse {
    $plugin = $this->sectionManager->section($section);
    if (!$plugin instanceof EventStudioSectionInterface) {
      $this->logger->warning('Event Studio autosave rejected for unsupported section @section nid=@nid uid=@uid.', [
        '@section' => $section,
        '@nid' => (string) $node->id(),
        '@uid' => (string) $this->currentUser->id(),
      ]);
      return new JsonResponse([
        'ok' => FALSE,
        'errors' => ['This Studio section does not support autosave.'],
      ], 422);
    }

    if (!$plugin->supportsAutosave()
      || !$plugin->isWritable()
      || $plugin->sectionState() === EventStudioSectionInterface::STATE_READONLY
      || $plugin->sectionState() === EventStudioSectionInterface::STATE_DEFERRED
      || $plugin->sectionState() === EventStudioSectionInterface::STATE_COMING_SOON) {
      $this->logger->warning('Event Studio autosave rejected for non-writable section @section nid=@nid uid=@uid state=@state.', [
        '@section' => $section,
        '@nid' => (string) $node->id(),
        '@uid' => (string) $this->currentUser->id(),
        '@state' => $plugin->sectionState(),
      ]);
      return new JsonResponse([
        'ok' => FALSE,
        'errors' => ['This Studio section is readonly and cannot be autosaved.'],
      ], 403);
    }

    return NULL;
  }

  /**
   * @param array<string, mixed> $mel
   *
   * @return array<string, mixed>
   */
  private function normalizeMelForSection(string $section, NodeInterface $node, array $mel): array {
    $plugin = $this->sectionManager->section($section);
    if (!$plugin instanceof EventStudioSectionInterface) {
      return $mel;
    }
    $target = $plugin->renderTarget();
    if (!str_starts_with($target, 'form:')) {
      return $mel;
    }
    $form_class = substr($target, 5);
    if ($form_class === '' || !class_exists($form_class)) {
      return $mel;
    }

    try {
      $form = $this->formBuilder->getForm($form_class, $node);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Event Studio autosave could not inspect section form @section for autocomplete normalization: @message', [
        '@section' => $section,
        '@message' => $e->getMessage(),
      ]);
      return $mel;
    }

    if (!isset($form['mel']) || !is_array($form['mel'])) {
      return $mel;
    }

    return $this->entityAutocompleteMelNormalizer->normalizeValuesForForm($form['mel'], $mel, [
      'section' => $section,
      'event_id' => (int) $node->id(),
      'uid' => (int) $this->currentUser->id(),
    ]);
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

    $hero = EventStudioMelPayloadService::normalizeHeroFromMelFragment($mel);
    $image_fid = $hero['fid'];
    $image_alt = $hero['alt'];
    if ($image_alt === '') {
      $image_alt = trim((string) ($params['field_event_image_alt'] ?? ''));
    }

    $payload = [
      'title' => $mel['title'] ?? $params['basics']['title'] ?? $params['title'] ?? '',
      'summary' => $mel['summary'] ?? $params['basics']['summary'] ?? $params['summary'] ?? '',
      'body' => $mel['body'] ?? $params['basics']['body'] ?? $params['body'] ?? '',
      'field_event_intro' => trim((string) ($mel['field_event_intro'] ?? $params['field_event_intro'] ?? '')),
      'field_event_image' => $image_fid,
      'field_event_image_alt' => $image_alt,
      'field_contact_email' => trim((string) ($mel['field_contact_email'] ?? $params['field_contact_email'] ?? '')),
      'field_contact_phone' => trim((string) ($mel['field_contact_phone'] ?? $params['field_contact_phone'] ?? '')),
      'field_category' => $this->extractMultipleEntityIds($mel['field_category'] ?? $params['field_category'] ?? ''),
      'field_tags' => $this->extractMultipleEntityIds($mel['field_tags'] ?? $params['field_tags'] ?? ''),
      'field_social_proof_visibility' => trim((string) ($mel['field_social_proof_visibility'] ?? $params['field_social_proof_visibility'] ?? 'auto')),
      'field_accessibility' => $this->extractMultipleEntityIds($mel['field_accessibility'] ?? $params['field_accessibility'] ?? ''),
      'field_accessibility_contact' => trim((string) ($mel['field_accessibility_contact'] ?? $params['field_accessibility_contact'] ?? '')),
      'field_accessibility_directions' => trim((string) ($mel['field_accessibility_directions'] ?? $params['field_accessibility_directions'] ?? '')),
      'field_accessibility_entry' => trim((string) ($mel['field_accessibility_entry'] ?? $params['field_accessibility_entry'] ?? '')),
      'field_accessibility_parking' => trim((string) ($mel['field_accessibility_parking'] ?? $params['field_accessibility_parking'] ?? '')),
      'field_product_target' => $this->extractSingleEntityId($mel['field_product_target'] ?? $params['field_product_target'] ?? NULL),
      'venue_choice' => $choice,
      'venue_id' => $venue_id,
      'new_venue_name' => $mel['venue_create_name'] ?? $params['venue']['create']['new_venue_name'] ?? $params['new_venue_name'] ?? '',
      'one_off_venue_name' => trim((string) ($mel['venue_one_off_name'] ?? $params['one_off_venue_name'] ?? '')),
      'field_location' => $field_location,
      'field_event_start' => $this->normalizeDatetimeFromMelRequest($mel['start_date'] ?? $params['when']['field_event_start'] ?? $params['field_event_start'] ?? NULL),
      'field_event_end' => $this->normalizeDatetimeFromMelRequest($mel['end_date'] ?? $params['when']['field_event_end'] ?? $params['field_event_end'] ?? NULL),
      'field_series_timezone' => trim((string) ($mel['field_series_timezone'] ?? $params['field_series_timezone'] ?? '')),
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
      'location_coordinates_submitted' => array_key_exists('field_location_latitude', $mel)
      || array_key_exists('field_location_longitude', $mel)
      || array_key_exists('field_location_latitude', $params)
      || array_key_exists('field_location_longitude', $params),
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
