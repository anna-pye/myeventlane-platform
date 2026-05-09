<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_checkout_paragraph\Service\CheckInTokenService;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared door-mode JSON validation (search + QR/manual token + paragraph check-in).
 *
 * Used by {@see \Drupal\myeventlane_event\Controller\CheckinController} and the
 * vendor canonical door route so scanner and manual paths share one mutation
 * pipeline via {@see MelAttendeeCheckinManager::checkInForTicketParagraph()}.
 */
final class MelDoorCheckinValidateService {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ?CheckInTokenService $checkInToken,
    private readonly MelAttendeeCheckinManager $checkinManager,
  ) {}

  /**
   * Handles GET (search) and POST (check-in) for a single event door surface.
   */
  public function handleRequest(
    NodeInterface $event,
    Request $request,
    AccountInterface $actor,
    CsrfTokenGenerator $csrfToken,
    string $csrfId,
  ): JsonResponse {
    $eventId = (int) $event->id();

    if ($request->isMethod(Request::METHOD_GET)) {
      $q = trim((string) $request->query->get('q', ''));
      if ($q === '') {
        return new JsonResponse(['status' => 'error', 'message' => 'Missing search query.'], 400);
      }
      try {
        $candidates = $this->searchAttendeeParagraphs($eventId, $q, 25);
      }
      catch (\Throwable $e) {
        $this->logger->error('Check-in search failed for event @eid: @msg', [
          '@eid' => (string) $eventId,
          '@msg' => $e->getMessage(),
        ]);
        return new JsonResponse(['status' => 'error', 'message' => 'Search failed.'], 500);
      }
      return new JsonResponse([
        'status' => 'ok',
        'candidates' => $candidates,
      ]);
    }

    $token = $request->headers->get('X-CSRF-Token', '');
    if (!$csrfToken->validate($token, $csrfId)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token.'], 403);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON body.'], 400);
    }

    $paragraphId = isset($payload['paragraph_id']) ? (int) $payload['paragraph_id'] : 0;
    $code = isset($payload['code']) ? trim((string) $payload['code']) : '';

    try {
      if ($paragraphId > 0) {
        return $this->jsonCheckInParagraph($eventId, $paragraphId, $actor);
      }

      if ($code === '') {
        return new JsonResponse(['status' => 'error', 'message' => 'Missing code or paragraph_id.'], 400);
      }

      if ($this->checkInToken !== NULL && $this->looksLikeQrToken($code)) {
        $decoded = $this->checkInToken->validateToken($code);
        if (!$decoded || empty($decoded['valid'])) {
          return new JsonResponse(['status' => 'error', 'message' => 'Invalid or expired ticket code.'], 400);
        }
        return $this->jsonCheckInParagraph($eventId, (int) $decoded['paragraph_id'], $actor);
      }

      $matches = $this->searchAttendeeParagraphs($eventId, $code, 10);
      if ($matches === []) {
        return new JsonResponse(['status' => 'error', 'message' => 'No matching attendee for this event.'], 404);
      }
      if (count($matches) > 1) {
        return new JsonResponse([
          'status' => 'multiple',
          'message' => 'Multiple matches — pick an attendee.',
          'candidates' => $matches,
        ]);
      }
      return $this->jsonCheckInParagraph($eventId, (int) $matches[0]['paragraph_id'], $actor);
    }
    catch (\Throwable $e) {
      $this->logger->error('Check-in validate failed for event @eid: @msg', [
        '@eid' => (string) $eventId,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['status' => 'error', 'message' => 'Check-in failed.'], 500);
    }
  }

  /**
   * @return array<int, array{paragraph_id: int, name: string, email: string}>
   */
  private function searchAttendeeParagraphs(int $eventId, string $needle, int $limit): array {
    $schema = $this->database->schema();
    if (
      !$schema->tableExists('commerce_order_item__field_ticket_holder') ||
      !$schema->tableExists('commerce_order_item__field_target_event') ||
      !$schema->tableExists('paragraph_field_data')
    ) {
      return [];
    }

    $like = '%' . $this->database->escapeLike($needle) . '%';

    $query = $this->database->select('commerce_order_item__field_ticket_holder', 'th');
    $query->join('commerce_order_item', 'oi', 'oi.order_item_id = th.entity_id');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'te', 'te.entity_id = oi.order_item_id');
    $query->join('paragraph_field_data', 'p', 'p.id = th.field_ticket_holder_target_id');
    $query->leftJoin('paragraph__field_first_name', 'fn', 'fn.entity_id = p.id AND fn.bundle = :bundle', [':bundle' => 'attendee_answer']);
    $query->leftJoin('paragraph__field_last_name', 'ln', 'ln.entity_id = p.id AND ln.bundle = :bundle2', [':bundle2' => 'attendee_answer']);
    $query->leftJoin('paragraph__field_email', 'em', 'em.entity_id = p.id AND em.bundle = :bundle3', [':bundle3' => 'attendee_answer']);
    $query->addField('th', 'field_ticket_holder_target_id', 'paragraph_id');
    $query->addExpression("TRIM(CONCAT(COALESCE(fn.field_first_name_value, ''), ' ', COALESCE(ln.field_last_name_value, '')))", 'full_name');
    $query->addField('em', 'field_email_value', 'email');
    $query->condition('o.state', 'completed');
    $query->condition('te.field_target_event_target_id', $eventId);
    $query->condition('p.type', 'attendee_answer');
    $query->condition('p.status', 1);
    $query->where("(fn.field_first_name_value LIKE :like OR ln.field_last_name_value LIKE :like OR em.field_email_value LIKE :like OR CONCAT(COALESCE(fn.field_first_name_value, ''), ' ', COALESCE(ln.field_last_name_value, '')) LIKE :like)", [
      ':like' => $like,
    ]);

    $query->range(0, $limit);

    $out = [];
    foreach ($query->execute() as $row) {
      $pid = (int) ($row->paragraph_id ?? 0);
      if ($pid <= 0) {
        continue;
      }
      $name = trim((string) ($row->full_name ?? ''));
      $email = (string) ($row->email ?? '');
      $out[] = [
        'paragraph_id' => $pid,
        'name' => $name,
        'email' => $email,
      ];
    }

    return $out;
  }

  private function jsonCheckInParagraph(int $eventId, int $paragraphId, AccountInterface $actor): JsonResponse {
    if (!$this->paragraphBelongsToCompletedEventOrder($paragraphId, $eventId)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Attendee not found for this event.'], 404);
    }

    $storage = $this->entityTypeManager->getStorage('paragraph');
    $paragraph = $storage->load($paragraphId);
    if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_answer') {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid attendee record.'], 400);
    }

    $accessHandler = $this->entityTypeManager->getAccessControlHandler('paragraph');
    if (!$accessHandler->access($paragraph, 'update', $actor)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return new JsonResponse(['status' => 'error', 'message' => 'Event not found.'], 404);
    }

    $result = $this->checkinManager->checkInForTicketParagraph(
      $paragraph,
      $event,
      $actor,
      MelAttendeeCheckinManager::SOURCE_DOOR_JSON,
    );

    if (!$result['success'] && $result['reason'] === 'forbidden') {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    if ($result['success'] && !$result['transitioned'] && $result['reason'] === 'already_checked_in') {
      $this->logger->notice('Duplicate check-in attempt for paragraph @pid at event @eid by user @uid.', [
        '@pid' => (string) $paragraphId,
        '@eid' => (string) $eventId,
        '@uid' => (string) $actor->id(),
      ]);
      $enriched = $this->buildDuplicatePayload($paragraph, $eventId);
      return new JsonResponse(array_merge([
        'status' => 'duplicate',
        'message' => 'Already checked in.',
        'checked_in' => TRUE,
      ], $enriched));
    }

    if (!$result['success']) {
      $this->logger->warning('Door check-in blocked: paragraph @pid event @eid reason @reason.', [
        '@pid' => (string) $paragraphId,
        '@eid' => (string) $eventId,
        '@reason' => (string) ($result['reason'] ?? ''),
      ]);
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Check-in not allowed for this attendee.',
        'checked_in' => FALSE,
      ], 400);
    }

    $this->logger->notice('Check-in success paragraph @pid event @eid by user @uid.', [
      '@pid' => (string) $paragraphId,
      '@eid' => (string) $eventId,
      '@uid' => (string) $actor->id(),
    ]);

    $successPayload = [
      'status' => 'success',
      'message' => 'Checked in',
      'checked_in' => TRUE,
      'paragraph_id' => $paragraphId,
    ];

    return new JsonResponse(array_merge($successPayload, $this->buildSuccessPayload($paragraph, $eventId, (int) ($result['attendee_id'] ?? 0))));
  }

  /**
   * @return array<string, mixed>
   */
  private function buildSuccessPayload(ParagraphInterface $paragraph, int $eventId, int $attendeeId): array {
    $out = [
      'attendee' => [
        'name' => $this->paragraphDisplayName($paragraph),
        'ticket_type' => '',
        'checked_in_at' => '',
        'accessibility' => [],
      ],
    ];
    if ($attendeeId > 0) {
      $attendee = $this->entityTypeManager->getStorage('event_attendee')->load($attendeeId);
      if ($attendee instanceof EventAttendee && (int) $attendee->getEventId() === $eventId) {
        $out['attendee']['name'] = $attendee->getName();
        if ($attendee->hasField('checked_in_at') && !$attendee->get('checked_in_at')->isEmpty()) {
          $ts = (int) $attendee->get('checked_in_at')->value;
          if ($ts > 0) {
            $out['attendee']['checked_in_at'] = $this->dateFormatter->format($ts, 'short');
          }
        }
        $out['attendee']['ticket_type'] = $this->resolveTicketTypeLabel($attendee);
        $out['attendee']['accessibility'] = $this->resolveAccessibilityLabels($attendee);
      }
    }
    return $out;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildDuplicatePayload(ParagraphInterface $paragraph, int $eventId): array {
    $checkedInAt = '';
    if ($paragraph->hasField('field_checked_in_timestamp') && !$paragraph->get('field_checked_in_timestamp')->isEmpty()) {
      $ts = (int) $paragraph->get('field_checked_in_timestamp')->value;
      if ($ts > 0) {
        $checkedInAt = $this->dateFormatter->format($ts, 'short');
      }
    }
    return [
      'attendee' => [
        'name' => $this->paragraphDisplayName($paragraph),
        'ticket_type' => '',
        'checked_in_at' => $checkedInAt,
        'accessibility' => [],
      ],
    ];
  }

  private function paragraphDisplayName(ParagraphInterface $paragraph): string {
    $first = '';
    $last = '';
    if ($paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()) {
      $first = trim((string) $paragraph->get('field_first_name')->value);
    }
    if ($paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()) {
      $last = trim((string) $paragraph->get('field_last_name')->value);
    }
    $name = trim($first . ' ' . $last);
    return $name !== '' ? $name : 'Attendee';
  }

  private function resolveTicketTypeLabel(EventAttendee $attendee): string {
    if (!$attendee->hasField('order_item') || $attendee->get('order_item')->isEmpty()) {
      return '';
    }
    $oi = $attendee->get('order_item')->entity;
    if (!is_object($oi) || !method_exists($oi, 'getPurchasedEntity')) {
      return '';
    }
    try {
      $purchased = $oi->getPurchasedEntity();
      return $purchased ? (string) $purchased->label() : '';
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * @return list<string>
   */
  private function resolveAccessibilityLabels(EventAttendee $attendee): array {
    $labels = [];
    if (!$attendee->hasField('extra_data') || $attendee->get('extra_data')->isEmpty()) {
      return $labels;
    }
    $raw = $attendee->get('extra_data')->value;
    if (!is_string($raw) || $raw === '') {
      return $labels;
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      return $labels;
    }
    foreach (['accessibility', 'accessibility_needs', 'mobility', 'dietary'] as $key) {
      if (!empty($decoded[$key]) && is_string($decoded[$key])) {
        $labels[] = (string) $decoded[$key];
      }
    }
    return $labels;
  }

  private function paragraphBelongsToCompletedEventOrder(int $paragraphId, int $eventId): bool {
    $schema = $this->database->schema();
    if (
      !$schema->tableExists('commerce_order_item__field_ticket_holder') ||
      !$schema->tableExists('commerce_order_item__field_target_event')
    ) {
      return FALSE;
    }

    $query = $this->database->select('commerce_order_item__field_ticket_holder', 'th');
    $query->join('commerce_order_item', 'oi', 'oi.order_item_id = th.entity_id');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'te', 'te.entity_id = oi.order_item_id');
    $query->addExpression('1');
    $query->condition('th.field_ticket_holder_target_id', $paragraphId);
    $query->condition('o.state', 'completed');
    $query->condition('te.field_target_event_target_id', $eventId);
    $query->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  private function looksLikeQrToken(string $code): bool {
    return strlen($code) >= 32 && base64_decode($code, TRUE) !== FALSE;
  }

}
