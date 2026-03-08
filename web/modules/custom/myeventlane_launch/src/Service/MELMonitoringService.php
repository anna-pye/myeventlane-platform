<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Service;

use Psr\Log\LoggerInterface;

/**
 * Central monitoring service for launch-critical failures.
 */
final class MELMonitoringService {

  public function __construct(
    private readonly LoggerInterface $monitoringLogger,
  ) {}

  /**
   * Logs a critical platform failure with normalized context.
   */
  public function monitorCriticalFailure(string $failureType, array $context = [], ?\Throwable $exception = NULL): void {
    $payload = [
      'failure_type' => $failureType,
      'context' => $context,
      'exception_message' => $exception?->getMessage(),
      'trace' => $exception?->getTraceAsString(),
    ];

    $this->monitoringLogger->critical(
      'Platform critical failure detected: {failure_type}',
      $payload
    );
  }

  /**
   * Logs a checkout failure as a critical monitoring event.
   */
  public function monitorCheckoutFailure(array $context = [], ?\Throwable $exception = NULL): void {
    $this->monitorCriticalFailure('checkout_failure', $context, $exception);
  }

  /**
   * Logs a webhook failure as a critical monitoring event.
   */
  public function monitorWebhookFailure(array $context = [], ?\Throwable $exception = NULL): void {
    $this->monitorCriticalFailure('webhook_failure', $context, $exception);
  }

  /**
   * Logs a ticket issuance failure as a critical monitoring event.
   */
  public function monitorTicketIssuanceFailure(array $context = [], ?\Throwable $exception = NULL): void {
    $this->monitorCriticalFailure('ticket_issuance_failure', $context, $exception);
  }

}
