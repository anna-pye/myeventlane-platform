<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketQrPayload;
use Psr\Log\LoggerInterface;

/**
 * Shared validation and mutation workflow for ticket check-in.
 */
final class TicketCheckinService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    private readonly TicketQrPayload $ticketQrPayload,
    private readonly TicketCheckinLogger $checkinLogger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Checks in one ticket using raw code or signed payload input.
   *
   * @return array<string, mixed>
   *   Structured outcome for form/API/scanner callers.
   */
  public function checkIn(int $route_event_id, string $input, string $device_id = 'web-form', string $mode = 'online'): array {
    // Reserved for optional policy checks (e.g. event check-in windows).
    $this->configFactory->get('myeventlane_tickets.settings');

    $normalized_input = trim($input);
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

    $ticket = $this->loadTicketByCode((string) $parsed['ticket_code']);
    if (!$ticket instanceof Ticket) {
      $result = $this->result(FALSE, 'invalid', 'Ticket not found.', (string) $parsed['ticket_code']);
      $this->checkinLogger->logResult($route_event_id, NULL, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }

    $ticket_event_id = (int) $ticket->get('event_id')->target_id;
    if ($ticket_event_id !== $route_event_id) {
      $result = $this->result(FALSE, 'wrong_event', 'Ticket is not valid for this event.', (string) $ticket->get('ticket_code')->value);
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }

    $status = (string) $ticket->get('status')->value;
    if ($status === Ticket::STATUS_VOID) {
      $result = $this->result(FALSE, 'void', 'Ticket is void and cannot be checked in.', (string) $ticket->get('ticket_code')->value);
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }
    if ($status === Ticket::STATUS_REFUNDED) {
      $result = $this->result(FALSE, 'refunded', 'Ticket was refunded and cannot be checked in.', (string) $ticket->get('ticket_code')->value);
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }
    if ($status === Ticket::STATUS_CHECKED_IN) {
      $checked_in_at = $ticket->hasField('checked_in_at') ? (int) $ticket->get('checked_in_at')->value : 0;
      $result = $this->result(FALSE, 'already_checked_in', 'Ticket is already checked in.', (string) $ticket->get('ticket_code')->value, $checked_in_at, (int) $ticket->id());
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }

    try {
      $now = $this->time->getCurrentTime();
      $ticket->set('status', Ticket::STATUS_CHECKED_IN);
      if ($ticket->hasField('checked_in_at')) {
        $ticket->set('checked_in_at', $now);
      }
      if ($ticket->hasField('checked_in_by')) {
        $ticket->set('checked_in_by', (int) $this->currentUser->id());
      }
      $ticket->save();

      $result = $this->result(TRUE, 'admitted', 'Admitted.', (string) $ticket->get('ticket_code')->value, $now, (int) $ticket->id());
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }
    catch (\Throwable $e) {
      $this->logger->error('Ticket check-in save failed for ticket @ticket and event @event: @message', [
        '@ticket' => (string) $ticket->id(),
        '@event' => (string) $route_event_id,
        '@message' => $e->getMessage(),
      ]);
      $result = $this->result(FALSE, 'error', 'A server error occurred while checking in this ticket.', (string) $ticket->get('ticket_code')->value);
      $this->checkinLogger->logResult($route_event_id, $ticket, $device_id, $mode, $result['result'], $result['message'], $normalized_input);
      return $result;
    }
  }

  /**
   * Loads a ticket entity by its exact ticket code.
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
   * Builds canonical service response payload.
   *
   * @return array<string, mixed>
   *   Check-in response.
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

}

