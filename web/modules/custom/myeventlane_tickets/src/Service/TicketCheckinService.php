<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Backwards-compatible entry point for ticket scanner callers.
 */
final class TicketCheckinService {

  public function __construct(
    private readonly ScannerOperationManager $operationManager,
  ) {}

  /**
   * Processes one ticket-backed scanner operation.
   *
   * @return array<string, mixed>
   *   Structured outcome for form/API/scanner callers.
   */
  public function checkIn(int $route_event_id, string $input, string $device_id = 'web-form', string $mode = 'online'): array {
    return $this->operationManager->process($route_event_id, $input, $device_id, $mode);
  }

}

