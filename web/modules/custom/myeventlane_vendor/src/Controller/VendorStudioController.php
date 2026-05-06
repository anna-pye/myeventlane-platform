<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\EntityIdNormalizer;
use Drupal\myeventlane_event_studio\DTO\MelEventData;
use Drupal\myeventlane_event_studio\Service\EventRepository;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_metrics\Service\EventMetricsServiceInterface;
use Drupal\myeventlane_vendor\Service\RsvpStatsService;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\myeventlane_vendor\Service\VendorEventRemovalService;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Vendor Studio shell controller and MEL JSON endpoints.
 */
final class VendorStudioController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * CSRF token namespace for Overview saves.
   */
  private const OVERVIEW_SAVE_TOKEN_ID = 'myeventlane_vendor_studio_overview_save';

  private const ATTENDEE_QUESTION_LIMIT = 5;

  /**
   * Constructs the Studio controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly LoggerInterface $logger,
    private readonly EventStudioSaveService $eventStudioSave,
    private readonly EntityIdNormalizer $entityIdNormalizer,
    private readonly EventRepository $eventRepository,
    private readonly VendorEventRemovalService $vendorEventRemovalService,
    private readonly ?TicketSalesService $ticketSalesService = NULL,
    private readonly ?RsvpStatsService $rsvpStatsService = NULL,
    private readonly ?EventMetricsServiceInterface $eventMetricsService = NULL,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('csrf_token'),
      $container->get('logger.channel.myeventlane_vendor'),
      $container->get('myeventlane_event_studio.save'),
      $container->get('myeventlane_core.entity_id_normalizer'),
      $container->get('myeventlane_event_studio.repository'),
      $container->get('myeventlane_vendor.vendor_event_removal'),
      $container->has('myeventlane_vendor.service.ticket_sales') ? $container->get('myeventlane_vendor.service.ticket_sales') : NULL,
      $container->has('myeventlane_vendor.service.rsvp_stats') ? $container->get('myeventlane_vendor.service.rsvp_stats') : NULL,
      $container->has('myeventlane_metrics.service') ? $container->get('myeventlane_metrics.service') : NULL,
    );
  }

  /**
   * Vendor Studio entrypoint at /vendor/studio.
   */
  public function studio(Request $request): array|RedirectResponse {
    // Organisers use Event Studio routes; retain this shell only for staff/support.
    if (!$this->currentUser->hasPermission('administer nodes') && (int) $this->currentUser->id() !== 1) {
      $query_event_id = (int) $request->query->get('event', 0);
      if ($query_event_id > 0) {
        $event = $this->entityTypeManager->getStorage('node')->load($query_event_id);
        if ($event instanceof NodeInterface && $event->bundle() === 'event') {
          try {
            $this->assertEventOwnership($event);
            $url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()])->toString();
            return new RedirectResponse($url, 302);
          }
          catch (AccessDeniedHttpException) {
            $this->logger->warning('Vendor Studio redirect denied for uid=@uid nid=@nid', [
              '@uid' => (int) $this->currentUser->id(),
              '@nid' => (int) $query_event_id,
            ]);
          }
        }
      }

      $url = Url::fromRoute('myeventlane_event_studio.create')->toString();
      return new RedirectResponse($url, 302);
    }

    $query_event_id = (int) $request->query->get('event', 0);
    if ($query_event_id > 0) {
      $event = $this->entityTypeManager->getStorage('node')->load($query_event_id);
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        try {
          $this->assertEventOwnership($event);
          $canonical_editor = $this->safeRouteUrl('myeventlane_vendor.console.event_editor', ['event' => (int) $event->id()]);
          if ($canonical_editor !== NULL) {
            return new RedirectResponse($canonical_editor);
          }
        }
        catch (AccessDeniedHttpException) {
          $this->logger->warning('Vendor Studio redirect denied for uid=@uid nid=@nid', [
            '@uid' => (int) $this->currentUser->id(),
            '@nid' => (int) $query_event_id,
          ]);
        }
      }
    }

    return $this->buildStudioRenderArray(NULL);
  }

  /**
   * Canonical Event Editor entrypoint for existing events.
   */
  public function eventEditor(NodeInterface $event): array|RedirectResponse {
    $this->assertEventOwnership($event);

    if ($event->bundle() !== 'event') {
      throw new AccessDeniedHttpException('Only event nodes can be edited in the Event Editor.');
    }

    if (!$this->currentUser->hasPermission('administer nodes') && (int) $this->currentUser->id() !== 1) {
      $url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()])->toString();
      return new RedirectResponse($url, 302);
    }

    return $this->buildStudioRenderArray($event);
  }

  /**
   * Builds the shared MEL Event Editor shell render array.
   */
  private function buildStudioRenderArray(?NodeInterface $active_event): array {
    $active_event_id = $active_event instanceof NodeInterface ? (int) $active_event->id() : 0;
    $events = $this->buildEventCards($active_event_id);
    $overview_form = [];
    $active_payload = $active_event instanceof NodeInterface ? $this->buildEventPayload($active_event) : NULL;

    return [
      '#theme' => 'studio',
      '#events' => $events,
      '#active_event' => $active_payload,
      '#overview_form' => $overview_form,
      '#overview_save_token' => $this->csrfToken->get(self::OVERVIEW_SAVE_TOKEN_ID),
      '#sidebar' => [
        '#theme' => 'vendor_sidebar',
      ],
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/vendor-workspace',
        ],
      ],
    ];
  }

  /**
   * MEL endpoint: fetches Studio payload for a selected event.
   */
  public function eventData(NodeInterface $event): JsonResponse {
    try {
      $this->assertEventOwnership($event);
    }
    catch (AccessDeniedHttpException) {
      $this->logger->warning('Vendor Studio data denied. uid=@uid nid=@nid', [
        '@uid' => (int) $this->currentUser->id(),
        '@nid' => (int) $event->id(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    return new JsonResponse([
      'success' => TRUE,
      'event' => $this->buildEventPayload($event),
    ]);
  }

  /**
   * MEL endpoint: updates Event fields from a generic Studio payload.
   */
  public function saveEvent(NodeInterface $event, Request $request): JsonResponse {
    if ($event->bundle() !== 'event') {
      $this->logger->warning('Vendor Studio generic save rejected for non-event node. nid=@nid bundle=@bundle', [
        '@nid' => (int) $event->id(),
        '@bundle' => $event->bundle(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Event not found.',
      ], 404);
    }

    if ($response = $this->guardStudioMutation($event, $request, 'generic save')) {
      return $response;
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload) || !isset($payload['values']) || !is_array($payload['values'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid payload. Expected { "values": { ... } }.',
      ], 400);
    }

    if ($payload['values'] === []) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'No field values were provided.',
      ], 400);
    }

    $unknown_fields = [];
    $blocked_fields = [];
    $invalid_values = [];
    $has_changes = FALSE;

    foreach ($payload['values'] as $field_name => $value) {
      if (!is_string($field_name) || $field_name === '' || !$event->hasField($field_name)) {
        $unknown_fields[] = (string) $field_name;
        continue;
      }

      $definition = $event->getFieldDefinition($field_name);
      if ($definition === NULL || $definition->isComputed()) {
        $blocked_fields[] = $field_name;
        continue;
      }

      $storage = $definition->getFieldStorageDefinition();
      if ($storage->isBaseField()) {
        $blocked_fields[] = $field_name;
        continue;
      }

      $field_type = (string) $definition->getType();
      $target_type = (string) ($definition->getSetting('target_type') ?? '');
      $paragraph_reference = $field_type === 'entity_reference_revisions'
        || ($field_type === 'entity_reference' && $target_type === 'paragraph');
      if ($paragraph_reference || $field_type === 'entity_reference') {
        $blocked_fields[] = $field_name;
        continue;
      }

      try {
        $current = $event->get($field_name)->getValue();
        $normalized_value = $this->normalizeGenericSaveValue($event, $field_name, $value);
        $event->set($field_name, $normalized_value);
        $updated = $event->get($field_name)->getValue();
        if ($current !== $updated) {
          $has_changes = TRUE;
        }
      }
      catch (\Throwable $exception) {
        $this->logger->warning('Vendor Studio generic save rejected value. nid=@nid field=@field message=@message', [
          '@nid' => (int) $event->id(),
          '@field' => $field_name,
          '@message' => $exception->getMessage(),
        ]);
        $invalid_values[] = $field_name;
      }
    }

    if ($unknown_fields !== [] || $blocked_fields !== [] || $invalid_values !== []) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'One or more fields are invalid for generic save.',
        'unknown_fields' => array_values(array_unique($unknown_fields)),
        'blocked_fields' => array_values(array_unique($blocked_fields)),
        'invalid_values' => array_values(array_unique($invalid_values)),
      ], 422);
    }

    try {
      if ($has_changes) {
        $this->saveStudioEventRevision($event, 'Updated event fields from Vendor Studio generic save endpoint.');
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Vendor Studio generic save failed. nid=@nid message=@message', [
        '@nid' => (int) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Could not save event changes.',
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'status' => 'saved',
      'event_id' => (int) $event->id(),
      'message' => $has_changes ? 'Event saved.' : 'No changes detected.',
      'event' => $this->buildEventPayload($event),
    ]);
  }

  /**
   * Normalizes incoming values for generic field saves.
   */
  private function normalizeGenericSaveValue(NodeInterface $event, string $field_name, mixed $value): mixed {
    $definition = $event->getFieldDefinition($field_name);
    if ($definition === NULL) {
      throw new \InvalidArgumentException(sprintf('Field "%s" does not exist.', $field_name));
    }

    $field_type = (string) $definition->getType();

    if (in_array($field_type, ['text', 'text_long', 'text_with_summary'], TRUE)) {
      return $this->normalizeFormattedTextValue($event, $field_name, $value);
    }

    if ($field_type === 'boolean') {
      return $value ? 1 : 0;
    }

    if (in_array($field_type, ['string', 'string_long', 'email', 'telephone', 'link'], TRUE)) {
      return trim((string) $value);
    }

    return $value;
  }

  /**
   * Normalizes formatted-text fields while preserving a valid text format.
   */
  private function normalizeFormattedTextValue(NodeInterface $event, string $field_name, mixed $value): array|string {
    $current_items = $event->get($field_name)->getValue();
    $first_item = is_array($current_items) && isset($current_items[0]) && is_array($current_items[0]) ? $current_items[0] : [];
    $current_format = '';
    $current_summary = '';
    $current_format = trim((string) ($first_item['format'] ?? ''));
    $current_summary = (string) ($first_item['summary'] ?? '');

    $definition = $event->getFieldDefinition($field_name);
    $allowed_formats = $definition ? $definition->getSetting('allowed_formats') : [];
    $fallback_format = '';
    if (is_array($allowed_formats) && $allowed_formats !== []) {
      $fallback_format = (string) array_key_first($allowed_formats);
    }

    $format = $current_format !== '' ? $current_format : $fallback_format;
    if ($format === '') {
      if (is_array($value)) {
        return (string) ($value['value'] ?? '');
      }
      return (string) $value;
    }

    if (is_array($value)) {
      $normalized = [
        'value' => (string) ($value['value'] ?? ''),
        'format' => (string) ($value['format'] ?? $format),
      ];
      if (array_key_exists('summary', $value)) {
        $normalized['summary'] = (string) ($value['summary'] ?? '');
      }
      elseif ($current_summary !== '') {
        $normalized['summary'] = $current_summary;
      }
      return $normalized;
    }

    return [
      'value' => (string) $value,
      'format' => $format,
      'summary' => $current_summary,
    ];
  }

  /**
   * MEL endpoint: updates Overview section fields only.
   */
  public function saveOverview(NodeInterface $event, Request $request): JsonResponse {
    try {
      $this->assertEventOwnership($event);
    }
    catch (AccessDeniedHttpException) {
      $this->logger->warning('Vendor Studio overview save denied. uid=@uid nid=@nid', [
        '@uid' => (int) $this->currentUser->id(),
        '@nid' => (int) $event->id(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    $token = (string) ($request->headers->get('X-CSRF-Token') ?? '');
    if (!$this->csrfToken->validate($token, self::OVERVIEW_SAVE_TOKEN_ID)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid CSRF token.',
      ], 403);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid request payload.',
      ], 400);
    }

    $result = $this->eventStudioSave->patchOverviewBasics($event, $this->currentUser, $payload);
    if (!$result['ok']) {
      if ($result['http_status'] >= 500) {
        $this->logger->error('Vendor Studio overview save failed. nid=@nid message=@message', [
          '@nid' => (int) $event->id(),
          '@message' => $result['message'],
        ]);
      }
      return new JsonResponse([
        'success' => FALSE,
        'message' => $result['message'],
      ], $result['http_status']);
    }

    return new JsonResponse([
      'success' => TRUE,
      'message' => $result['message'],
      'event' => $this->buildEventPayload($event),
    ]);
  }

  /**
   * MEL endpoint: updates ticket editor payload.
   */
  public function saveTickets(NodeInterface $event, Request $request): JsonResponse {
    if ($response = $this->guardStudioMutation($event, $request, 'tickets save')) {
      return $response;
    }

    if (!$event->hasField('field_ticket_config')) {
      $this->logger->error('Vendor Studio tickets save blocked. field_ticket_config missing on nid=@nid', [
        '@nid' => (int) $event->id(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Ticket config field is not available on this event.',
      ], 422);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid request payload.',
      ], 400);
    }

    try {
      if ($event->hasField('field_ticket_config')) {
        $event->set('field_ticket_config', json_encode($payload, JSON_THROW_ON_ERROR));
      }
      $this->saveStudioEventRevision($event, 'Updated Tickets tab from Vendor Studio.');
    }
    catch (\Throwable $exception) {
      $this->logger->error('Vendor Studio tickets save failed. nid=@nid message=@message', [
        '@nid' => (int) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to save tickets.',
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Tickets configuration saved.',
      'event' => $this->buildEventPayload($event),
    ]);
  }

  /**
   * MEL endpoint: updates attendee editor payload.
   */
  public function saveAttendees(NodeInterface $event, Request $request): JsonResponse {
    $event_id = (int) $event->id();
    $loaded_event = $this->entityTypeManager->getStorage('node')->load($event_id);
    if (!$loaded_event instanceof NodeInterface || $loaded_event->bundle() !== 'event') {
      $this->logger->error('Vendor Studio attendees save failed. Event not found or invalid. nid=@nid', [
        '@nid' => $event_id,
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Event not found.',
      ], 404);
    }

    if ($response = $this->guardStudioMutation($loaded_event, $request, 'attendees save')) {
      return $response;
    }

    $event = $loaded_event;

    if (!$event->hasField('field_attendee_questions')) {
      $this->logger->error('Vendor Studio attendees save blocked. field_attendee_questions missing on nid=@nid', [
        '@nid' => $event_id,
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Attendee questions field is not available on this event.',
      ], 422);
    }

    if ($event->getFieldDefinition('field_attendee_questions')->getType() !== 'entity_reference_revisions') {
      $this->logger->error('Vendor Studio attendees save blocked. field_attendee_questions type is not entity_reference_revisions. nid=@nid', [
        '@nid' => $event_id,
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Attendee questions field does not support paragraph references.',
      ], 422);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid request payload.',
      ], 400);
    }

    if (!isset($payload['questions']) || !is_array($payload['questions'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid attendee payload. Expected { "questions": [] }.',
      ], 422);
    }

    if (count($payload['questions']) > self::ATTENDEE_QUESTION_LIMIT) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Add at most 5 attendee questions. More questions may reduce bookings.',
      ], 422);
    }

    $paragraph_bundle = $this->entityTypeManager->getStorage('paragraphs_type')->load('attendee_extra_field');
    if ($paragraph_bundle === NULL) {
      $this->logger->error('Vendor Studio attendees save failed. Missing paragraph bundle attendee_extra_field. nid=@nid', [
        '@nid' => $event_id,
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Required paragraph bundle attendee_extra_field does not exist.',
      ], 500);
    }

    $field_map = $this->resolveAttendeeQuestionFieldMap();
    if ($field_map === NULL) {
      $this->logger->error('Vendor Studio attendees save failed. Required paragraph fields are missing on attendee_extra_field. nid=@nid', [
        '@nid' => $event_id,
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Attendee paragraph field mapping is invalid.',
      ], 500);
    }

    try {
      $event->set('field_attendee_questions', []);

      $references = [];
      foreach ($payload['questions'] as $index => $question) {
        if (!is_array($question)) {
          return new JsonResponse([
            'success' => FALSE,
            'message' => sprintf('Question at index %d must be an object.', $index),
          ], 422);
        }

        $label = trim((string) ($question['label'] ?? ''));
        $type = trim((string) ($question['type'] ?? ''));
        $required = (bool) ($question['required'] ?? FALSE);

        if ($label === '') {
          return new JsonResponse([
            'success' => FALSE,
            'message' => sprintf('Question at index %d requires a non-empty label.', $index),
          ], 422);
        }

        if ($type === '') {
          return new JsonResponse([
            'success' => FALSE,
            'message' => sprintf('Question at index %d requires a type.', $index),
          ], 422);
        }

        $paragraph = Paragraph::create([
          'type' => 'attendee_extra_field',
        ]);
        $paragraph->set($field_map['label'], $label);
        $paragraph->set($field_map['type'], $this->normalizeAttendeeQuestionType($type));
        $paragraph->set($field_map['required'], $required ? 1 : 0);
        $paragraph->save();

        $references[] = [
          'target_id' => (int) $paragraph->id(),
          'target_revision_id' => (int) $paragraph->getRevisionId(),
        ];
      }

      $event->set('field_attendee_questions', $references);
      $this->saveStudioEventRevision($event, 'Updated Attendees tab from Vendor Studio.');
    }
    catch (\Throwable $exception) {
      $this->logger->error('Vendor Studio attendees save failed. nid=@nid message=@message', [
        '@nid' => (int) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to save attendees.',
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Attendees configuration saved.',
      'event' => $this->buildEventPayload($event),
    ]);
  }

  /**
   * MEL endpoint: updates promotion editor payload.
   */
  public function savePromotion(NodeInterface $event, Request $request): JsonResponse {
    if ($response = $this->guardStudioMutation($event, $request, 'promotion save')) {
      return $response;
    }

    if (!$event->hasField('field_promo_config')) {
      $this->logger->error('Vendor Studio promotion save blocked. field_promo_config missing on nid=@nid', [
        '@nid' => (int) $event->id(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Promotion config field is not available on this event.',
      ], 422);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid request payload.',
      ], 400);
    }

    try {
      if ($event->hasField('field_promo_config')) {
        $event->set('field_promo_config', json_encode($payload, JSON_THROW_ON_ERROR));
      }
      $this->saveStudioEventRevision($event, 'Updated Promotion tab from Vendor Studio.');
    }
    catch (\Throwable $exception) {
      $this->logger->error('Vendor Studio promotion save failed. nid=@nid message=@message', [
        '@nid' => (int) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to save promotion settings.',
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Promotion settings saved.',
      'event' => $this->buildEventPayload($event),
    ]);
  }

  /**
   * MEL endpoint: updates event settings fields.
   */
  public function saveSettings(NodeInterface $event, Request $request): JsonResponse {
    if ($response = $this->guardStudioMutation($event, $request, 'settings save')) {
      return $response;
    }

    if (!$event->hasField('field_event_capacity') && !$event->hasField('field_event_visibility')) {
      $this->logger->error('Vendor Studio settings save blocked. No settings fields available on nid=@nid', [
        '@nid' => (int) $event->id(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Settings fields are not available on this event.',
      ], 422);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid request payload.',
      ], 400);
    }

    try {
      $has_changes = FALSE;

      if (isset($payload['capacity']) && $event->hasField('field_event_capacity')) {
        $capacity = (int) $payload['capacity'];
        $current_capacity = (int) ($event->get('field_event_capacity')->value ?? 0);
        if ($current_capacity !== $capacity) {
          $event->set('field_event_capacity', $capacity);
          $has_changes = TRUE;
        }
      }

      if (isset($payload['visibility']) && $event->hasField('field_event_visibility')) {
        $visibility = (string) $payload['visibility'];
        $current_visibility = (string) ($event->get('field_event_visibility')->value ?? '');
        if ($current_visibility !== $visibility) {
          $event->set('field_event_visibility', $visibility);
          $has_changes = TRUE;
        }
      }

      if ($has_changes) {
        $this->saveStudioEventRevision($event, 'Updated Settings tab from Vendor Studio.');
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Vendor Studio settings save failed. nid=@nid message=@message', [
        '@nid' => (int) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to save event settings.',
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Event settings saved.',
      'event' => $this->buildEventPayload($event),
    ]);
  }

  /**
   * MEL endpoint: submits event to review from Studio.
   */
  public function publishEvent(NodeInterface $event, Request $request): JsonResponse {
    if ($response = $this->guardStudioMutation($event, $request, 'publish')) {
      return $response;
    }

    try {
      if (!$event->hasField('moderation_state')) {
        $this->logger->error('Vendor Studio publish failed. moderation_state missing. nid=@nid', [
          '@nid' => (int) $event->id(),
        ]);
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Moderation is not enabled for this event.',
        ], 500);
      }

      $current_state = (string) ($event->get('moderation_state')->value ?? '');
      if ($current_state !== 'review') {
        $event->set('moderation_state', 'review');
        $this->saveStudioEventRevision($event, 'Submitted event for review from Vendor Studio.');
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Vendor Studio publish failed. nid=@nid message=@message', [
        '@nid' => (int) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to submit event for review.',
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Event submitted for review.',
      'event' => $this->buildEventPayload($event),
    ]);
  }

  /**
   * Validates ownership and CSRF token for Studio POST mutations.
   */
  private function guardStudioMutation(NodeInterface $event, Request $request, string $action): ?JsonResponse {
    try {
      $this->assertEventOwnership($event);
    }
    catch (AccessDeniedHttpException) {
      $this->logger->warning('Vendor Studio @action denied. uid=@uid nid=@nid', [
        '@action' => $action,
        '@uid' => (int) $this->currentUser->id(),
        '@nid' => (int) $event->id(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    $token = (string) ($request->headers->get('X-CSRF-Token') ?? '');
    if (!$this->csrfToken->validate($token, self::OVERVIEW_SAVE_TOKEN_ID)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid CSRF token.',
      ], 403);
    }

    return NULL;
  }

  /**
   * Saves a revision for Studio endpoint updates.
   */
  private function saveStudioEventRevision(NodeInterface $event, string $revision_log): void {
    $event->setNewRevision(TRUE);
    $event->setRevisionUserId((int) $this->currentUser->id());
    $event->setRevisionLogMessage($revision_log);
    $event->save();
  }

  /**
   * Handles POST action to submit a vendor event for admin review.
   */
  public function submitReview(NodeInterface $event, Request $request): RedirectResponse {
    $event_id = (int) $event->id();
    $redirect = Url::fromRoute('myeventlane_vendor.console.event_editor', ['event' => $event_id])->toString();

    $token = (string) $request->request->get('token', '');
    if (!$this->csrfToken->validate($token, 'myeventlane_vendor_studio_submit_review')) {
      throw new AccessDeniedHttpException('Invalid CSRF token.');
    }

    try {
      $this->assertEventOwnership($event);
    }
    catch (AccessDeniedHttpException) {
      $this->logger->warning('Vendor submit-review denied. uid=@uid nid=@nid', [
        '@uid' => (int) $this->currentUser->id(),
        '@nid' => $event_id,
      ]);
      $this->messenger->addError($this->t('You do not have access to submit this event.'));
      return new RedirectResponse($redirect);
    }

    if (!$event->hasField('moderation_state')) {
      $this->logger->error('Event moderation_state missing during submit-review. nid=@nid', [
        '@nid' => $event_id,
      ]);
      $this->messenger->addError($this->t('Moderation is not enabled for this event.'));
      return new RedirectResponse($redirect);
    }

    $state = (string) $event->get('moderation_state')->value;
    if ($state !== 'review') {
      try {
        $event->setNewRevision(TRUE);
        $event->setRevisionUserId((int) $this->currentUser->id());
        $event->setRevisionLogMessage('Submitted for admin review from Vendor Studio.');
        $event->set('moderation_state', 'review');
        $event->save();
        $this->messenger->addStatus($this->t('Event submitted for review.'));
      }
      catch (\Throwable $exception) {
        $this->logger->error('Vendor submit-review failed. nid=@nid message=@message', [
          '@nid' => $event_id,
          '@message' => $exception->getMessage(),
        ]);
        $this->messenger->addError($this->t('Could not submit this event for review.'));
      }
    }
    else {
      $this->messenger->addStatus($this->t('This event is already in review.'));
    }

    return new RedirectResponse($redirect);
  }

  /**
   * Loads active context event from query arg or first owned event.
   */
  protected function resolveActiveEvent(Request $request): ?NodeInterface {
    $query_event_id = (int) $request->query->get('event', 0);
    if ($query_event_id > 0) {
      $event = $this->entityTypeManager->getStorage('node')->load($query_event_id);
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        try {
          $this->assertEventOwnership($event);
          return $event;
        }
        catch (AccessDeniedHttpException) {
          return NULL;
        }
      }
    }

    $ids = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('uid', (int) $this->currentUser->id())
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $event = $this->entityTypeManager->getStorage('node')->load((int) reset($ids));
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

  /**
   * Builds Studio event-card records for the navigator.
   *
   * @return array<int, array<string, mixed>>
   *   Event card rows.
   */
  protected function buildEventCards(int $active_event_id = 0): array {
    $event_ids = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('uid', (int) $this->currentUser->id())
      ->sort('changed', 'DESC')
      ->range(0, 24)
      ->execute();

    if (empty($event_ids)) {
      return [];
    }

    // Before: query ->execute() may yield string-keyed maps; values int|string.
    // After: scalar int list for loadMultiple() (avoids array_flip() type warnings).
    $event_ids = $this->entityIdNormalizer->normalizeNodeIds(array_values($event_ids));
    if ($event_ids === []) {
      return [];
    }

    $melEvents = $this->eventRepository->loadMany($event_ids);
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($event_ids);
    $cards = [];

    foreach ($melEvents as $event) {
      if (!$event instanceof MelEventData) {
        continue;
      }

      $event_id = $event->id;
      $studio_endpoints = $this->buildStudioEndpoints($event_id);
      $moderation = $event->moderation_state !== ''
        ? $event->moderation_state
        : ($event->published ? 'published' : 'draft');
      $node = $nodes[$event_id] ?? NULL;
      $thumb = ($node instanceof NodeInterface && $node->bundle() === 'event')
        ? $this->vendorEventRemovalService->buildEventThumbnailData($node)
        : $this->vendorEventRemovalService->buildPlaceholderThumbnailData($event->title);

      $cards[] = [
        'id' => $event_id,
        'nid' => $event_id,
        'title' => $event->title,
        'date' => $this->extractMelEventDateLabel($event),
        'location' => $this->extractMelEventLocationLabel($event),
        'image' => $thumb['url'],
        'image_alt' => $thumb['alt'],
        'image_is_placeholder' => $thumb['is_placeholder'],
        'status' => $this->normalizeModerationState($moderation),
        'tickets_sold' => 0,
        'rsvp_count' => 0,
        'revenue' => '0',
        'edit_url' => Url::fromRoute('myeventlane_vendor.console.event_editor', ['event' => $event_id])->toString(),
        'studio_link' => Url::fromRoute('myeventlane_vendor.console.event_editor', ['event' => $event_id])->toString(),
        'read_url' => $studio_endpoints['overview_read'],
        'save_url' => $studio_endpoints['overview_save'],
        'overview_read_url' => $studio_endpoints['overview_read'],
        'overview_save_url' => $studio_endpoints['overview_save'],
        'tickets_save_url' => $studio_endpoints['tickets_save'],
        'attendees_save_url' => $studio_endpoints['attendees_save'],
        'promotion_save_url' => $studio_endpoints['promotion_save'],
        'settings_save_url' => $studio_endpoints['settings_save'],
        'publish_url' => $studio_endpoints['publish'],
        'preview_url' => Url::fromRoute('entity.node.canonical', ['node' => $event_id])->toString(),
        'view_url' => Url::fromRoute('entity.node.canonical', ['node' => $event_id])->toString(),
        'wizard_url' => Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event_id])->toString(),
        'active' => $active_event_id > 0 && $active_event_id === $event_id,
      ];
    }

    return $cards;
  }

  /**
   * Builds Studio payload for one event.
   *
   * @return array<string, mixed>
   *   Studio event data.
   */
  protected function buildEventPayload(NodeInterface $event): array {
    $event_id = (int) $event->id();
    $metrics = $this->buildStudioEventMetrics($event);
    $studio_endpoints = $this->buildStudioEndpoints($event_id);
    $moderation_state = $event->hasField('moderation_state') && !$event->get('moderation_state')->isEmpty()
      ? (string) $event->get('moderation_state')->value
      : 'draft';

    $checklist = [
      'title' => trim($event->label()) !== '',
      'date' => $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty(),
      'location' => ($event->hasField('field_location') && !$event->get('field_location')->isEmpty())
        || ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()),
      'banner' => $event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty(),
    ];

    return [
      'id' => $event_id,
      'title' => $event->label(),
      'date' => $this->extractEventDateLabel($event),
      'field_event_summary' => $event->hasField('field_event_summary')
        ? (string) ($event->get('field_event_summary')->value ?? '')
        : '',
      'field_event_type' => $event->hasField('field_event_type')
        ? (string) ($event->get('field_event_type')->value ?? '')
        : '',
      'status' => $this->normalizeModerationState($moderation_state),
      'moderation_state' => $moderation_state,
      'tickets_metric' => $metrics['tickets_sold'],
      'rsvps_metric' => $metrics['rsvp_count'],
      'revenue_metric' => $metrics['revenue'],
      'ticket_percent' => $metrics['ticket_percent'],
      'health_label' => $this->buildHealthLabel($metrics['tickets_sold']),
      'tickets_config' => $this->decodeJsonField($event, 'field_ticket_config'),
      'attendees_config' => $this->extractAttendeeQuestionsPayload($event),
      'promotion_config' => $this->decodeJsonField($event, 'field_promo_config'),
      'settings_config' => [
        'capacity' => $event->hasField('field_event_capacity')
          ? (int) ($event->get('field_event_capacity')->value ?? 0)
          : 0,
        'visibility' => $event->hasField('field_event_visibility')
          ? (string) ($event->get('field_event_visibility')->value ?? '')
          : '',
      ],
      'checklist' => $checklist,
      'quick_actions' => [
        'preview_url' => Url::fromRoute('entity.node.canonical', ['node' => $event_id])->toString(),
        'view_url' => Url::fromRoute('entity.node.canonical', ['node' => $event_id])->toString(),
        'duplicate_url' => NULL,
      ],
      'wizard_url' => Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event_id])->toString(),
      'editor_url' => Url::fromRoute('myeventlane_vendor.console.event_editor', ['event' => $event_id])->toString(),
      'read_url' => $studio_endpoints['overview_read'],
      'save_url' => $studio_endpoints['overview_save'],
      'endpoints' => $studio_endpoints,
    ];
  }

  /**
   * Builds API endpoints for each Studio tab action.
   *
   * @return array{
   *   overview_read:string,
   *   overview_save:string,
   *   tickets_save:string,
   *   attendees_save:string,
   *   promotion_save:string,
   *   settings_save:string,
   *   publish:string
   * }
   *   Endpoint map for the active event.
   */
  protected function buildStudioEndpoints(int $event_id): array {
    return [
      'overview_read' => $this->safeRouteUrl('myeventlane_vendor.console.studio.event_data', ['event' => $event_id]) ?? '',
      'overview_save' => $this->safeRouteUrl('myeventlane_vendor.console.studio.event_overview_save', ['event' => $event_id]) ?? '',
      'tickets_save' => $this->safeRouteUrl('myeventlane_vendor.console.studio.event_tickets_save', ['event' => $event_id]) ?? '',
      'attendees_save' => $this->safeRouteUrl('myeventlane_vendor.console.studio.event_attendees_save', ['event' => $event_id]) ?? '',
      'promotion_save' => $this->safeRouteUrl('myeventlane_vendor.console.studio.event_promotion_save', ['event' => $event_id]) ?? '',
      'settings_save' => $this->safeRouteUrl('myeventlane_vendor.console.studio.event_settings_save', ['event' => $event_id]) ?? '',
      'publish' => $this->safeRouteUrl('myeventlane_vendor.console.studio.event_publish', ['event' => $event_id]) ?? '',
    ];
  }

  /**
   * Safely decodes a JSON text field into an array payload.
   *
   * @return array<string, mixed>
   *   Decoded payload or empty array when unavailable.
   */
  protected function decodeJsonField(NodeInterface $event, string $field_name): array {
    if (!$event->hasField($field_name) || $event->get($field_name)->isEmpty()) {
      return [];
    }

    $raw = (string) ($event->get($field_name)->value ?? '');
    if ($raw === '') {
      return [];
    }

    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($decoded) ? $decoded : [];
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Studio JSON decode failed for @field on event @nid: @message', [
        '@field' => $field_name,
        '@nid' => (int) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Returns the field mapping for attendee paragraph values.
   *
   * @return array{label:string, type:string, required:string}|null
   *   Mapping array or NULL when required fields are unavailable.
   */
  protected function resolveAttendeeQuestionFieldMap(): ?array {
    $mapping = [
      'label' => NULL,
      'type' => NULL,
      'required' => NULL,
    ];

    $label_candidates = ['field_label', 'field_question_label'];
    $type_candidates = ['field_type', 'field_question_type'];
    $required_candidates = ['field_required', 'field_question_required'];

    foreach ($label_candidates as $candidate) {
      if ($this->entityTypeManager->getStorage('field_config')->load("paragraph.attendee_extra_field.$candidate")) {
        $mapping['label'] = $candidate;
        break;
      }
    }
    foreach ($type_candidates as $candidate) {
      if ($this->entityTypeManager->getStorage('field_config')->load("paragraph.attendee_extra_field.$candidate")) {
        $mapping['type'] = $candidate;
        break;
      }
    }
    foreach ($required_candidates as $candidate) {
      if ($this->entityTypeManager->getStorage('field_config')->load("paragraph.attendee_extra_field.$candidate")) {
        $mapping['required'] = $candidate;
        break;
      }
    }

    if ($mapping['label'] === NULL || $mapping['type'] === NULL || $mapping['required'] === NULL) {
      return NULL;
    }

    return [
      'label' => $mapping['label'],
      'type' => $mapping['type'],
      'required' => $mapping['required'],
    ];
  }

  /**
   * Converts UI type tokens to paragraph field allowed values.
   */
  protected function normalizeAttendeeQuestionType(string $type): string {
    return match ($type) {
      'text' => 'textfield',
      default => $type,
    };
  }

  /**
   * Builds attendees payload in Studio JSON schema.
   *
   * @return array{questions: array<int, array{label:string, type:string, required:bool}>}
   *   Attendee questions payload.
   */
  protected function extractAttendeeQuestionsPayload(NodeInterface $event): array {
    $payload = ['questions' => []];
    if (!$event->hasField('field_attendee_questions') || $event->get('field_attendee_questions')->isEmpty()) {
      return $payload;
    }

    foreach ($event->get('field_attendee_questions')->referencedEntities() as $paragraph) {
      $label = '';
      if ($paragraph->hasField('field_label')) {
        $label = trim((string) ($paragraph->get('field_label')->value ?? ''));
      }
      elseif ($paragraph->hasField('field_question_label')) {
        $label = trim((string) ($paragraph->get('field_question_label')->value ?? ''));
      }

      $type = '';
      if ($paragraph->hasField('field_type')) {
        $type = trim((string) ($paragraph->get('field_type')->value ?? ''));
      }
      elseif ($paragraph->hasField('field_question_type')) {
        $type = trim((string) ($paragraph->get('field_question_type')->value ?? ''));
      }

      $required = FALSE;
      if ($paragraph->hasField('field_required')) {
        $required = (bool) ($paragraph->get('field_required')->value ?? FALSE);
      }
      elseif ($paragraph->hasField('field_question_required')) {
        $required = (bool) ($paragraph->get('field_question_required')->value ?? FALSE);
      }

      $payload['questions'][] = [
        'label' => $label,
        'type' => $type === 'textfield' ? 'text' : $type,
        'required' => $required,
      ];
    }

    return $payload;
  }

  /**
   * Builds event-level metrics for Studio endpoint payload.
   *
   * @return array{
   *   tickets_sold:int,
   *   ticket_capacity:int,
   *   ticket_percent:int,
   *   rsvp_count:int,
   *   revenue:string
   * }
   *   Metric payload.
   */
  protected function buildStudioEventMetrics(NodeInterface $event): array {
    $event_id = (int) $event->id();
    $tickets_sold = 0;
    $ticket_capacity = 0;
    $rsvp_count = 0;
    $revenue = 0.0;

    if ($this->ticketSalesService) {
      try {
        $sales_summary = $this->ticketSalesService->getSalesSummary($event);
        $tickets_sold = max(0, (int) ($sales_summary['tickets_sold'] ?? 0));
        $revenue = max(0.0, (float) ($sales_summary['gross_raw'] ?? 0.0));
      }
      catch (\Throwable $exception) {
        $this->logger->error('Studio metrics failed to load ticket sales. nid=@nid message=@message', [
          '@nid' => $event_id,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    if ($this->rsvpStatsService) {
      try {
        $rsvp_count = max(0, (int) $this->rsvpStatsService->getEventRsvpCount($event_id));
      }
      catch (\Throwable $exception) {
        $this->logger->error('Studio metrics failed to load RSVP count. nid=@nid message=@message', [
          '@nid' => $event_id,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    if ($this->eventMetricsService) {
      try {
        $ticket_capacity = max(0, (int) ($this->eventMetricsService->getCapacityTotal($event) ?? 0));
      }
      catch (\Throwable $exception) {
        $this->logger->error('Studio metrics failed to load event capacity. nid=@nid message=@message', [
          '@nid' => $event_id,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    $ticket_percent = $ticket_capacity > 0
      ? min(100, (int) round(($tickets_sold / $ticket_capacity) * 100))
      : 0;

    return [
      'tickets_sold' => $tickets_sold,
      'ticket_capacity' => $ticket_capacity,
      'ticket_percent' => $ticket_percent,
      'rsvp_count' => $rsvp_count,
      'revenue' => number_format($revenue, 0, '.', ','),
    ];
  }

  /**
   * Resolves route URLs safely to avoid hard failures.
   */
  protected function safeRouteUrl(string $route, array $params = [], array $options = []): ?string {
    try {
      return Url::fromRoute($route, $params, $options)->toString();
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Vendor Studio route unavailable: @route. @message', [
        '@route' => $route,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Normalizes moderation states to MEL badge values.
   */
  protected function normalizeModerationState(string $state): string {
    return match ($state) {
      'review', 'needs_review' => 'review',
      'published', 'live' => 'published',
      default => 'draft',
    };
  }

  /**
   * Builds health label from ticket velocity.
   */
  protected function buildHealthLabel(int $tickets_sold): string {
    if ($tickets_sold > 50) {
      return 'Healthy';
    }
    if ($tickets_sold > 10) {
      return 'Needs promotion';
    }
    return 'Low sales';
  }

  /**
   * Display date from MEL event DTO (studio cards).
   */
  protected function extractMelEventDateLabel(MelEventData $event): string {
    if ($event->start_timestamp > 0) {
      return date('D, j M Y - H:i', $event->start_timestamp);
    }
    if ($event->start !== NULL && $event->start !== '') {
      $timestamp = strtotime($event->start);
      if ($timestamp !== FALSE) {
        return date('D, j M Y - H:i', $timestamp);
      }
      return $event->start;
    }
    return (string) $this->t('Date TBD');
  }

  /**
   * Display location from MEL event DTO (studio cards).
   */
  protected function extractMelEventLocationLabel(MelEventData $event): string {
    if ($event->venue_label !== '') {
      return $event->venue_label;
    }
    $loc = $event->location;
    if ($loc !== []) {
      $parts = [];
      $line1 = trim((string) ($loc['address_line1'] ?? ''));
      $locality = trim((string) ($loc['locality'] ?? ''));
      $admin = trim((string) ($loc['administrative_area'] ?? ''));
      if ($line1 !== '') {
        $parts[] = $line1;
      }
      if ($locality !== '') {
        $parts[] = $locality;
      }
      if ($admin !== '') {
        $parts[] = $admin;
      }
      if ($parts !== []) {
        return implode(', ', $parts);
      }
    }
    return (string) $this->t('Location TBD');
  }

  /**
   * Extracts display date for event cards.
   */
  protected function extractEventDateLabel(NodeInterface $event): string {
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $value = (string) $event->get('field_event_start')->value;
      $timestamp = strtotime($value);
      if ($timestamp !== FALSE) {
        return date('D, j M Y - H:i', $timestamp);
      }
      return $value;
    }

    return (string) $this->t('Date TBD');
  }

  /**
   * Extracts display location for event cards.
   */
  protected function extractEventLocationLabel(NodeInterface $event): string {
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $venue_name = trim((string) $event->get('field_venue_name')->value);
      if ($venue_name !== '') {
        return $venue_name;
      }
    }

    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $location_item = $event->get('field_location')->first();
      if ($location_item) {
        $parts = [];
        $address_line1 = trim((string) ($location_item->get('address_line1')->value ?? ''));
        $locality = trim((string) ($location_item->get('locality')->value ?? ''));
        $administrative_area = trim((string) ($location_item->get('administrative_area')->value ?? ''));

        if ($address_line1 !== '') {
          $parts[] = $address_line1;
        }
        if ($locality !== '') {
          $parts[] = $locality;
        }
        if ($administrative_area !== '') {
          $parts[] = $administrative_area;
        }

        if (!empty($parts)) {
          return implode(', ', $parts);
        }
      }
    }

    return (string) $this->t('Location TBD');
  }

  /**
   * Extracts event image URL for cards.
   */
  protected function extractEventImageUrl(NodeInterface $event): ?string {
    if (!$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return NULL;
    }

    $image_item = $event->get('field_event_image')->first();
    if (!$image_item || !isset($image_item->entity) || !$image_item->entity) {
      return NULL;
    }

    $image_entity = $image_item->entity;
    if (method_exists($image_entity, 'createFileUrl')) {
      return $image_entity->createFileUrl();
    }

    if (method_exists($image_entity, 'hasField')
      && $image_entity->hasField('field_media_image')
      && !$image_entity->get('field_media_image')->isEmpty()) {
      $media_image = $image_entity->get('field_media_image')->entity;
      if ($media_image && method_exists($media_image, 'createFileUrl')) {
        return $media_image->createFileUrl();
      }
    }

    return NULL;
  }

}
