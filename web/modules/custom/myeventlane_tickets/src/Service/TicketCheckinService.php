<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

/**
 * Backwards-compatible entry point for ticket scanner callers.
 *
 * Scanner behaviour is implemented by mel_scanner.operation_manager
 * (ScannerOperationManager), which composes EntitlementCapabilityRegistry,
 * VenueOperationPolicyManager (including TimedEntryPolicyManager), and gate
 * policy for audit integrity metadata.
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
  public function checkIn(int $route_event_id, string $input, string $device_id = 'web-form', string $mode = 'online', ?array $operational_context = NULL): array {
    return $this->operationManager->process($route_event_id, $input, $device_id, $mode, $operational_context);
  }

}

