<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe subscription webhook endpoint for audit and optional reconcile.
 *
 * Logs subscription.* and invoice.payment_failed events. Does not create
 * parallel subscription state. Commerce Recurring remains source of truth.
 */
final class ProSubscriptionWebhookController extends ControllerBase {

  public function __construct(
    private readonly ConfigFactoryInterface $config,
    private readonly LoggerInterface $logger,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.channel.myeventlane_pro'),
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Handles incoming Stripe subscription webhook events.
   */
  public function handle(Request $request): Response {
    $payload = (string) $request->getContent();
    $sigHeader = (string) $request->headers->get('Stripe-Signature', '');

    $secret = trim((string) ($this->config->get('myeventlane_pro.settings')->get('subscription_webhook_secret') ?? ''));
    $auditEnabled = (bool) ($this->config->get('myeventlane_pro.settings')->get('webhook_audit_enabled') ?? FALSE);

    if ($secret === '') {
      $this->logger->warning('Pro subscription webhook: secret not configured. Rejecting.');
      return new Response('Webhook not configured', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    try {
      $event = Webhook::constructEvent($payload, $sigHeader, $secret);
    }
    catch (SignatureVerificationException $e) {
      $this->logger->warning('Pro subscription webhook signature verification failed: @msg', ['@msg' => $e->getMessage()]);
      return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
    }
    catch (UnexpectedValueException $e) {
      $this->logger->warning('Pro subscription webhook invalid payload: @msg', ['@msg' => $e->getMessage()]);
      return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
    }

    $eventId = trim((string) ($event->id ?? ''));
    $eventType = trim((string) ($event->type ?? 'unknown'));
    if ($eventId === '') {
      return new Response('Missing event id', Response::HTTP_BAD_REQUEST);
    }

    if (!$this->recordEventOnce($eventId, $eventType)) {
      return new Response('Already processed', Response::HTTP_OK);
    }

    if ($auditEnabled) {
      $this->logEvent($event);
    }

    return new Response('OK', Response::HTTP_OK);
  }

  /**
   * Records a verified event exactly once without retaining its payload.
   */
  private function recordEventOnce(string $eventId, string $eventType): bool {
    if (!$this->database->schema()->tableExists('myeventlane_pro_webhook_event')) {
      $this->logger->error('Pro subscription webhook idempotency ledger is missing.');
      return FALSE;
    }

    try {
      $this->database->insert('myeventlane_pro_webhook_event')->fields([
        'event_id' => $eventId,
        'event_type' => $eventType,
        'received_at' => $this->time->getRequestTime(),
      ])->execute();
      return TRUE;
    }
    catch (IntegrityConstraintViolationException) {
      return FALSE;
    }
  }

  /**
   * Logs the event for audit purposes.
   */
  private function logEvent(object $event): void {
    $type = $event->type ?? 'unknown';
    $id = $event->id ?? 'unknown';

    $this->logger->info('Pro subscription webhook: @type id=@id', [
      '@type' => $type,
      '@id' => $id,
    ]);
  }

}
