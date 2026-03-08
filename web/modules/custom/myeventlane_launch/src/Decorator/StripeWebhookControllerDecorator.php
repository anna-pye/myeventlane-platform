<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Decorator;

use Drupal\myeventlane_launch\Service\MELMonitoringService;
use Drupal\myeventlane_launch\Service\StripeWebhookProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decorates Stripe webhook controller with idempotency and retries.
 */
final class StripeWebhookControllerDecorator {

  private const MAX_ATTEMPTS = 3;

  public function __construct(
    private readonly object $innerController,
    private readonly StripeWebhookProcessor $webhookProcessor,
    private readonly MELMonitoringService $monitoringService,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Handles webhook requests with duplicate prevention and retry safety.
   */
  public function handle(Request $request): Response {
    return $this->invokeWithIdempotency('handle', [$request]);
  }

  /**
   * Proxies unknown method calls to the decorated controller.
   */
  public function __call(string $name, array $arguments): mixed {
    if ($this->isWebhookRequestInvocation($arguments)) {
      return $this->invokeWithIdempotency($name, $arguments);
    }

    return $this->delegateToInner($name, $arguments);
  }

  /**
   * Executes controller method with webhook idempotency protections.
   */
  private function invokeWithIdempotency(string $method, array $arguments): Response {
    $request = $arguments[0] ?? NULL;
    if (!$request instanceof Request) {
      $response = $this->delegateToInner($method, $arguments);
      if (!$response instanceof Response) {
        throw new \UnexpectedValueException('Webhook controller method did not return a Response.');
      }
      return $response;
    }

    $eventId = $this->webhookProcessor->extractEventId($request);
    if (is_string($eventId) && $this->webhookProcessor->isProcessed($eventId)) {
      $this->logger->info('Skipping duplicate Stripe webhook event {event_id}.', [
        'event_id' => $eventId,
      ]);
      return new JsonResponse(['received' => TRUE, 'duplicate' => TRUE], Response::HTTP_OK);
    }

    for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
      try {
        $response = $this->delegateToInner($method, $arguments);
        if (!$response instanceof Response) {
          throw new \UnexpectedValueException('Webhook controller method did not return a Response.');
        }
        if ($response->getStatusCode() < 500) {
          if (is_string($eventId)) {
            $this->webhookProcessor->markProcessed($eventId, [
              'status_code' => $response->getStatusCode(),
              'attempt' => $attempt,
            ]);
          }
          return $response;
        }

        $message = 'Webhook controller returned a server error response.';
        if (is_string($eventId)) {
          $this->webhookProcessor->recordFailure($eventId, $attempt, $message);
        }
        $this->monitoringService->monitorWebhookFailure([
          'event_id' => $eventId,
          'attempt' => $attempt,
          'status_code' => $response->getStatusCode(),
        ]);
      }
      catch (\Throwable $e) {
        if (is_string($eventId)) {
          $this->webhookProcessor->recordFailure($eventId, $attempt, $e->getMessage());
        }
        $this->monitoringService->monitorWebhookFailure([
          'event_id' => $eventId,
          'attempt' => $attempt,
        ], $e);
      }
    }

    return new JsonResponse(['error' => 'Webhook processing failed after retries.'], Response::HTTP_INTERNAL_SERVER_ERROR);
  }

  /**
   * Delegates a method call to the decorated controller safely.
   */
  private function delegateToInner(string $method, array $arguments): mixed {
    if (method_exists($this->innerController, $method)) {
      return $this->innerController->{$method}(...$arguments);
    }

    if (is_callable($this->innerController)) {
      return ($this->innerController)(...$arguments);
    }

    throw new \BadMethodCallException(sprintf('Method "%s" is not callable on decorated webhook controller.', $method));
  }

  /**
   * Determines whether invocation is for a webhook request.
   */
  private function isWebhookRequestInvocation(array $arguments): bool {
    return isset($arguments[0]) && $arguments[0] instanceof Request;
  }

}
