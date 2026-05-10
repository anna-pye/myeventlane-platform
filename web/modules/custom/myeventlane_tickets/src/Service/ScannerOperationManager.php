<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_tickets\Entity\RedemptionLog;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketQrPayload;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Routes scanner operations for ticket-backed entitlements.
 */
final class ScannerOperationManager {

  /**
   * Maps entitlement types to scanner operation actions.
   *
   * @var array<string, string>
   */
  private const ACTION_BY_TYPE = [
    Ticket::ENTITLEMENT_TICKET => RedemptionLog::ACTION_ADMIT,
    Ticket::ENTITLEMENT_MERCH => RedemptionLog::ACTION_COLLECT,
    Ticket::ENTITLEMENT_PARKING => RedemptionLog::ACTION_VALIDATE,
    Ticket::ENTITLEMENT_DRINK => RedemptionLog::ACTION_REDEEM,
    Ticket::ENTITLEMENT_FOOD => RedemptionLog::ACTION_REDEEM,
    Ticket::ENTITLEMENT_VIP => RedemptionLog::ACTION_VERIFY,
    Ticket::ENTITLEMENT_ADDON => RedemptionLog::ACTION_REDEEM,
  ];

  /**
   * Successful result token by operation action.
   *
   * @var array<string, string>
   */
  private const SUCCESS_RESULT_BY_ACTION = [
    RedemptionLog::ACTION_ADMIT => 'admitted',
    RedemptionLog::ACTION_COLLECT => 'collected',
    RedemptionLog::ACTION_VALIDATE => 'validated',
    RedemptionLog::ACTION_REDEEM => 'redeemed',
    RedemptionLog::ACTION_VERIFY => 'verified',
  ];

  /**
   * Successful user-facing message by operation action.
   *
   * @var array<string, string>
   */
  private const SUCCESS_MESSAGE_BY_ACTION = [
    RedemptionLog::ACTION_ADMIT => 'Admitted.',
    RedemptionLog::ACTION_COLLECT => 'Collected.',
    RedemptionLog::ACTION_VALIDATE => 'Validated.',
    RedemptionLog::ACTION_REDEEM => 'Redeemed.',
    RedemptionLog::ACTION_VERIFY => 'Verified.',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    private readonly TicketQrPayload $ticketQrPayload,
    private readonly TicketCheckinLogger $checkinLogger,
    private readonly TicketCapabilityManager $capabilityManager,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Processes one scanner/manual input through the canonical ticket path.
   *
   * @return array<string, mixed>
   *   Structured outcome for form/API/scanner callers.
   */
  public function process(int $route_event_id, string $input, string $device_id = 'web-form', string $mode = 'online'): array {
    $normalized_input = $this->normalizeInput($input);
    if ($normalized_input === '') {
      $result = $this->result(FALSE, 'invalid', 'Ticket code is required.', '');
      $this->checkinLogger->logResult($route_event_id, NULL, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }

    $parsed = $this->ticketQrPayload->parseAndValidate($normalized_input);
    if ($parsed === NULL) {
      $result = $this->result(FALSE, 'invalid', 'Invalid ticket code or QR signature.', $normalized_input);
      $this->checkinLogger->logResult($route_event_id, NULL, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }

    $parsed_event_id = isset($parsed['event_id']) ? (int) $parsed['event_id'] : NULL;
    if ($parsed_event_id !== NULL && $parsed_event_id !== $route_event_id) {
      $result = $this->result(FALSE, 'wrong_event', 'Ticket is not valid for this event.', $parsed['ticket_code']);
      $this->checkinLogger->logResult($route_event_id, NULL, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }

    $ticket = $this->loadTicketFromParsedData($parsed);
    if (!$ticket instanceof Ticket && (string) $parsed['ticket_code'] !== '') {
      $ticket = $this->resolveLegacyOrderItemTicket($route_event_id, (string) $parsed['ticket_code']);
    }
    if (!$ticket instanceof Ticket) {
      $result = $this->result(FALSE, 'invalid', 'Ticket not found.', (string) $parsed['ticket_code']);
      $this->checkinLogger->logResult($route_event_id, NULL, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }

    $ticket_event_id = (int) $ticket->get('event_id')->target_id;
    if ($ticket_event_id !== $route_event_id) {
      $result = $this->result(FALSE, 'wrong_event', 'Ticket is not valid for this event.', (string) $ticket->get('ticket_code')->value);
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      $this->logRedemptionAudit($ticket, $this->resolveAction($ticket), $device_id, FALSE, $result['result'], $result['message']);
      return $result;
    }

    if (isset($parsed['expires_at']) && (int) $parsed['expires_at'] <= $this->time->getCurrentTime()) {
      $result = $this->result(FALSE, 'expired', 'Entitlement has expired.', (string) $ticket->get('ticket_code')->value);
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      $this->logRedemptionAudit($ticket, $this->resolveAction($ticket), $device_id, FALSE, $result['result'], $result['message']);
      return $result;
    }

    $status = (string) $ticket->get('status')->value;
    if ($status === Ticket::STATUS_VOID) {
      $result = $this->result(FALSE, 'void', 'Ticket is void and cannot be scanned.', (string) $ticket->get('ticket_code')->value);
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      $this->logRedemptionAudit($ticket, $this->resolveAction($ticket), $device_id, FALSE, $result['result'], $result['message']);
      return $result;
    }
    if ($status === Ticket::STATUS_REFUNDED) {
      $result = $this->result(FALSE, 'refunded', 'Ticket was refunded and cannot be scanned.', (string) $ticket->get('ticket_code')->value);
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      $this->logRedemptionAudit($ticket, $this->resolveAction($ticket), $device_id, FALSE, $result['result'], $result['message']);
      return $result;
    }
    if ($this->capabilityManager->isTicket($ticket) && $status === Ticket::STATUS_CHECKED_IN) {
      $checked_in_at = $ticket->hasField('checked_in_at') ? (int) $ticket->get('checked_in_at')->value : 0;
      $result = $this->result(FALSE, 'already_checked_in', 'Ticket is already checked in.', (string) $ticket->get('ticket_code')->value, $checked_in_at, (int) $ticket->id());
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      $this->logRedemptionAudit($ticket, $this->resolveAction($ticket), $device_id, FALSE, $result['result'], $result['message']);
      return $result;
    }
    if (!$this->capabilityManager->canBeScanned($ticket)) {
      $result_token = $this->capabilityManager->isExpired($ticket) ? 'expired' : 'redemption_limit_reached';
      $message = $result_token === 'expired' ? 'Entitlement has expired.' : 'Redemption limit has already been reached.';
      $result = $this->result(FALSE, $result_token, $message, (string) $ticket->get('ticket_code')->value, 0, (int) $ticket->id());
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      $this->logRedemptionAudit($ticket, $this->resolveAction($ticket), $device_id, FALSE, $result['result'], $result['message']);
      return $result;
    }

    return $this->executeOperation($route_event_id, $ticket, $device_id, $mode, $normalized_input);
  }

  /**
   * Executes the routed scanner operation.
   *
   * @return array<string, mixed>
   *   Scanner response.
   */
  private function executeOperation(int $route_event_id, Ticket $ticket, string $device_id, string $mode, string $normalized_input): array {
    $action = $this->resolveAction($ticket);

    try {
      $now = $this->time->getCurrentTime();
      if ($action === RedemptionLog::ACTION_ADMIT) {
        $ticket->set('status', Ticket::STATUS_CHECKED_IN);
        if ($ticket->hasField('checked_in_at')) {
          $ticket->set('checked_in_at', $now);
        }
        if ($ticket->hasField('checked_in_by')) {
          $ticket->set('checked_in_by', (int) $this->currentUser->id());
        }
      }
      else {
        $this->applyOperationalState($ticket, $action);
      }

      $ticket->save();

      $result_token = self::SUCCESS_RESULT_BY_ACTION[$action] ?? 'scanned';
      $message = self::SUCCESS_MESSAGE_BY_ACTION[$action] ?? 'Scanned.';
      $result = $this->result(TRUE, $result_token, $message, (string) $ticket->get('ticket_code')->value, $now, (int) $ticket->id());
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      $this->logRedemptionAudit($ticket, $action, $device_id, TRUE, $result['result'], $result['message']);
      return $result;
    }
    catch (\Throwable $e) {
      $this->logger->error('Scanner operation @action failed for ticket @ticket and event @event: @message', [
        '@action' => $action,
        '@ticket' => (string) $ticket->id(),
        '@event' => (string) $route_event_id,
        '@message' => $e->getMessage(),
      ]);
      $result = $this->result(FALSE, 'error', "Scan couldn't be completed. Try again or use manual review.", (string) $ticket->get('ticket_code')->value);
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      $this->logRedemptionAudit($ticket, $action, $device_id, FALSE, $result['result'], $result['message']);
      return $result;
    }
  }

  /**
   * Applies non-admission fulfilment and redemption state.
   */
  private function applyOperationalState(Ticket $ticket, string $action): void {
    if ($this->capabilityManager->isRedeemable($ticket)) {
      $ticket->set('redemption_count', $ticket->getRedemptionCount() + 1);
    }

    if ($action === RedemptionLog::ACTION_COLLECT) {
      $ticket->set('fulfilment_status', Ticket::FULFILMENT_COLLECTED);
      return;
    }

    if ($action === RedemptionLog::ACTION_REDEEM) {
      $ticket->set('fulfilment_status', Ticket::FULFILMENT_REDEEMED);
    }
  }

  /**
   * Logs one append-only redemption audit row.
   */
  private function logRedemptionAudit(Ticket $ticket, string $action, string $device_id, bool $ok, string $result, string $message): void {
    if (!$this->entityTypeManager->hasDefinition('mel_redemption_log')) {
      return;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('mel_redemption_log');
      $base_table = $storage->getEntityType()->getBaseTable();
      if ($base_table && !$this->database->schema()->tableExists($base_table)) {
        return;
      }
      $values = [
        'ticket_id' => (int) $ticket->id(),
        'staff_uid' => (int) $this->currentUser->id() ?: NULL,
        'vendor_id' => $this->resolveVendorId($ticket),
        'event_id' => (int) $ticket->get('event_id')->target_id,
        'action_type' => $action,
        'device_identifier' => $this->normalizeDeviceId($device_id),
        'notes' => $message,
        'metadata_json' => [
          'ok' => $ok,
          'result' => $result,
          'entitlement_type' => $ticket->getEntitlementType(),
          'redemption_count' => $ticket->getRedemptionCount(),
          'redemption_limit' => $ticket->getRedemptionLimit(),
        ],
      ];
      $storage->create($values)->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Redemption audit log insert failed for ticket @ticket: @message', [
        '@ticket' => (string) $ticket->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Resolves the scanner action for a ticket-backed entitlement.
   */
  private function resolveAction(Ticket $ticket): string {
    return self::ACTION_BY_TYPE[$ticket->getEntitlementType()] ?? RedemptionLog::ACTION_ADMIT;
  }

  /**
   * Loads a ticket from parsed QR data.
   *
   * @param array<string, mixed> $parsed
   *   Parsed QR/code data.
   */
  private function loadTicketFromParsedData(array $parsed): ?Ticket {
    $ticket_uuid = trim((string) ($parsed['ticket_uuid'] ?? ''));
    if ($ticket_uuid !== '') {
      return $this->loadTicketByUuid($ticket_uuid);
    }

    $ticket_code = trim((string) ($parsed['ticket_code'] ?? ''));
    return $ticket_code !== '' ? $this->loadTicketByCode($ticket_code) : NULL;
  }

  /**
   * Loads a ticket entity by exact ticket code.
   */
  private function loadTicketByCode(string $ticket_code): ?Ticket {
    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('ticket_code', $ticket_code)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $ticket = $storage->load((int) reset($ids));
    return $ticket instanceof Ticket ? $ticket : NULL;
  }

  /**
   * Loads a ticket entity by UUID from structured QR payloads.
   */
  private function loadTicketByUuid(string $ticket_uuid): ?Ticket {
    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $ticket_uuid)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $ticket = $storage->load((int) reset($ids));
    return $ticket instanceof Ticket ? $ticket : NULL;
  }

  /**
   * Resolves legacy MEL-{event}-{order}-{order_item}-{hash} codes.
   */
  private function resolveLegacyOrderItemTicket(int $route_event_id, string $input): ?Ticket {
    $legacy = $this->parseLegacyOrderItemCode($input);
    if ($legacy === NULL) {
      return NULL;
    }

    if ($legacy['event_id'] !== $route_event_id) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $candidate_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $route_event_id)
      ->condition('order_item_id', $legacy['order_item_id'])
      ->condition('status', [Ticket::STATUS_VOID, Ticket::STATUS_REFUNDED, Ticket::STATUS_CHECKED_IN], 'NOT IN')
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();

    if (!$candidate_ids) {
      $candidate_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $route_event_id)
        ->condition('order_item_id', $legacy['order_item_id'])
        ->sort('id', 'ASC')
        ->range(0, 1)
        ->execute();
    }

    if (!$candidate_ids) {
      return NULL;
    }

    $ticket = $storage->load((int) reset($candidate_ids));
    return $ticket instanceof Ticket ? $ticket : NULL;
  }

  /**
   * Parses a legacy order-item ticket code.
   *
   * @return array{event_id:int,order_id:int,order_item_id:int}|null
   *   Parsed segments, or NULL for non-legacy input.
   */
  private function parseLegacyOrderItemCode(string $input): ?array {
    $code = strtoupper(trim($input));
    if (!preg_match('/^MEL-(\d+)-(\d+)-(\d+)-[A-Z0-9]+$/', $code, $matches)) {
      return NULL;
    }

    return [
      'event_id' => (int) $matches[1],
      'order_id' => (int) $matches[2],
      'order_item_id' => (int) $matches[3],
    ];
  }

  /**
   * Normalizes scanner/manual input into a ticket token.
   */
  private function normalizeInput(string $input): string {
    $value = trim($input);

    if (str_starts_with(strtolower($value), 'ticket code:')) {
      $value = trim(substr($value, strlen('Ticket Code:')));
    }

    if (filter_var($value, FILTER_VALIDATE_URL)) {
      $path = (string) parse_url($value, PHP_URL_PATH);
      if (preg_match('#^/ticket/([^/]+)/pdf$#', $path, $matches)) {
        $value = urldecode((string) $matches[1]);
      }
    }

    return trim($value);
  }

  /**
   * Resolves vendor scope from the ticket or linked event.
   */
  private function resolveVendorId(Ticket $ticket): ?int {
    if ($ticket->hasField('vendor_reference') && !$ticket->get('vendor_reference')->isEmpty()) {
      return (int) $ticket->get('vendor_reference')->target_id;
    }

    $event = $ticket->get('event_id')->entity;
    if ($event instanceof NodeInterface && $event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      return (int) $event->get('field_event_vendor')->target_id;
    }

    return NULL;
  }

  /**
   * Builds canonical service response payload.
   *
   * @return array<string, mixed>
   *   Scanner response.
   */
  private function result(
    bool $ok,
    string $result,
    string $message,
    string $ticket_code,
    int $checked_in_at = 0,
    int $ticket_id = 0,
  ): array {
    return [
      'ok' => $ok,
      'result' => $result,
      'message' => $message,
      'ticket_label' => $this->checkinLogger->maskTicketCode($ticket_code),
      'checked_in_at' => $checked_in_at,
      'ticket_id' => $ticket_id > 0 ? $ticket_id : NULL,
    ];
  }

  /**
   * Sanitizes incoming scanner device id.
   */
  private function normalizeDeviceId(string $device_id): string {
    $normalized = preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($device_id)) ?? '';
    return $normalized !== '' ? substr($normalized, 0, 128) : 'unknown-device';
  }

}
