<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

/**
 * Writes and reads ticket check-in logs.
 */
final class TicketCheckinLogger {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Logs one check-in validation attempt.
   */
  public function logResult(
    int $event_id,
    ?Ticket $ticket,
    string $device_id,
    string $mode,
    string $result,
    string $message,
    string $payload,
  ): void {
    $safe_device_id = $this->normalizeDeviceId($device_id);
    $safe_mode = in_array($mode, ['online', 'offline'], TRUE) ? $mode : 'online';
    $ticket_code = $ticket ? (string) $ticket->get('ticket_code')->value : $this->extractTicketCode($payload);
    $ticket_code_hash = hash('sha256', $ticket_code);
    $payload_hash = hash('sha256', trim($payload));
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request?->getClientIp();

    try {
      $this->database->insert('myeventlane_ticket_checkin_log')
        ->fields([
          'event_nid' => $event_id,
          'ticket_id' => $ticket?->id(),
          'ticket_code_hash' => $ticket_code_hash,
          'payload_hash' => $payload_hash,
          'ticket_label_masked' => $this->maskTicketCode($ticket_code),
          'scanned_at' => $this->time->getCurrentTime(),
          'scanned_by_uid' => $this->currentUserId(),
          'device_id' => $safe_device_id,
          'mode' => $safe_mode,
          'result' => substr($result, 0, 32),
          'message' => substr($message, 0, 255),
          'synced' => 1,
          'ip' => $ip ? substr($ip, 0, 64) : NULL,
        ])
        ->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Ticket check-in log insert failed for event @event and device @device: @message', [
        '@event' => (string) $event_id,
        '@device' => $safe_device_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Returns most recent check-in attempts for an event.
   *
   * @return array<int, array<string, mixed>>
   *   Recent attempts.
   */
  public function recentByEvent(int $event_id, int $limit = 20): array {
    $query = $this->database->select('myeventlane_ticket_checkin_log', 'l')
      ->fields('l', [
        'id',
        'event_nid',
        'ticket_id',
        'ticket_label_masked',
        'scanned_at',
        'scanned_by_uid',
        'device_id',
        'mode',
        'result',
        'message',
      ])
      ->condition('event_nid', $event_id)
      ->orderBy('scanned_at', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, max(1, $limit));

    return $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Returns aggregated analytics from log table.
   *
   * @return array<string, mixed>
   *   Breakdown by result and unique admitted ticket count.
   */
  public function countsByEvent(int $event_id): array {
    $result_breakdown = [];
    $result_query = $this->database->select('myeventlane_ticket_checkin_log', 'l')
      ->fields('l', ['result']);
    $result_query->addExpression('COUNT(*)', 'count');
    $result_query->condition('event_nid', $event_id);
    $result_query->groupBy('result');

    foreach ($result_query->execute() as $row) {
      $result_breakdown[(string) $row->result] = (int) $row->count;
    }

    $distinct_query = $this->database->select('myeventlane_ticket_checkin_log', 'l');
    $distinct_query->addExpression('COUNT(DISTINCT l.ticket_id)', 'unique_count');
    $distinct_query->condition('event_nid', $event_id);
    $distinct_query->condition('result', 'admitted');
    $distinct_query->condition('ticket_id', NULL, 'IS NOT NULL');
    $unique_checked_in = (int) $distinct_query->execute()->fetchField();

    $device_breakdown = [];
    $device_query = $this->database->select('myeventlane_ticket_checkin_log', 'l')
      ->fields('l', ['device_id']);
    $device_query->addExpression('COUNT(*)', 'count');
    $device_query->condition('event_nid', $event_id);
    $device_query->groupBy('device_id');
    $device_query->orderBy('count', 'DESC');
    $device_query->range(0, 10);
    foreach ($device_query->execute() as $row) {
      $device_breakdown[(string) $row->device_id] = (int) $row->count;
    }

    $user_breakdown = [];
    $user_query = $this->database->select('myeventlane_ticket_checkin_log', 'l')
      ->fields('l', ['scanned_by_uid']);
    $user_query->addExpression('COUNT(*)', 'count');
    $user_query->condition('event_nid', $event_id);
    $user_query->condition('scanned_by_uid', NULL, 'IS NOT NULL');
    $user_query->groupBy('scanned_by_uid');
    $user_query->orderBy('count', 'DESC');
    $user_query->range(0, 10);
    foreach ($user_query->execute() as $row) {
      $user_breakdown[(string) $row->scanned_by_uid] = (int) $row->count;
    }

    return [
      'result_breakdown' => $result_breakdown,
      'unique_tickets_checked_in' => $unique_checked_in,
      'device_breakdown' => $device_breakdown,
      'user_breakdown' => $user_breakdown,
    ];
  }

  /**
   * Masks a ticket code for UI/log display.
   */
  public function maskTicketCode(string $ticket_code): string {
    $code = trim($ticket_code);
    if ($code === '') {
      return '----';
    }
    if (strlen($code) <= 8) {
      return substr($code, 0, 2) . '...' . substr($code, -2);
    }
    return substr($code, 0, 4) . '...' . substr($code, -4);
  }

  /**
   * Extracts ticket code from payload or raw ticket code input.
   */
  private function extractTicketCode(string $payload): string {
    $trimmed = trim($payload);
    if (str_starts_with($trimmed, 'mel:v1:')) {
      $parts = explode(':', $trimmed, 6);
      if (isset($parts[2]) && $parts[2] !== '') {
        return $parts[2];
      }
    }
    return $trimmed;
  }

  /**
   * Loads current account id from request user.
   */
  private function currentUserId(): ?int {
    $id = (int) $this->currentUser->id();
    if ($id > 0) {
      return $id;
    }
    return NULL;
  }

  /**
   * Sanitizes incoming scanner device id.
   */
  private function normalizeDeviceId(string $device_id): string {
    $trimmed = trim($device_id);
    $normalized = preg_replace('/[^a-zA-Z0-9\-_]/', '', $trimmed) ?? '';
    if ($normalized === '') {
      return 'unknown-device';
    }
    return substr($normalized, 0, 64);
  }

}

