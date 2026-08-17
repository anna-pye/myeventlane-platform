<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\myeventlane_core\Service\StripeService;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Transfer;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles Stripe payout/transfer webhooks for ledger reconciliation.
 *
 * Listens for Stripe's real transfer.created and transfer.reversed events
 * and reconciles the internal payout ledger accordingly. Never modifies
 * commerce_order or payment entities — only the payout ledger table.
 */
final class StripeWebhookController extends ControllerBase {

  private const TABLE = 'myeventlane_payout_ledger';

  private const CACHE_TAGS = ['myeventlane_payout_ledger'];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected LoggerInterface $payoutLogger,
    ConfigFactoryInterface $configFactory,
    protected StripeService $stripeService,
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_admin_dashboard'),
      $container->get('config.factory'),
      $container->get('myeventlane_core.stripe'),
    );
  }

  /**
   * Main webhook entry point. Verifies signature and dispatches by event type.
   */
  public function handle(Request $request): Response {
    $payload = (string) $request->getContent();
    $sigHeader = (string) $request->headers->get('Stripe-Signature', '');

    $webhookSecret = (string) $this->configFactory
      ->get('myeventlane_admin_dashboard.settings')
      ->get('stripe_webhook_secret');

    if (empty($webhookSecret)) {
      $this->payoutLogger->error('Stripe payout webhook secret is not configured. Rejecting request.');
      return new JsonResponse(['error' => 'Webhook not configured'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    try {
      $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    }
    catch (SignatureVerificationException $e) {
      $this->payoutLogger->warning('Stripe payout webhook signature verification failed: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
    }
    catch (UnexpectedValueException $e) {
      $this->payoutLogger->warning('Stripe payout webhook received invalid payload: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
    }

    return $this->dispatch($event);
  }

  /**
   * Routes the verified event to the appropriate handler.
   */
  private function dispatch(Event $event): Response {
    return match ($event->type) {
      'transfer.created' => $this->handleTransferCreated($event),
      'transfer.reversed' => $this->handleTransferReversed($event),
      default => $this->handleUnknown($event),
    };
  }

  /**
   * Reconciles a created transfer to the ledger's paid state.
   */
  private function handleTransferCreated(Event $event): Response {
    $transfer = $event->data['object'] ?? NULL;
    if (!$transfer instanceof Transfer) {
      return $this->handleInvalidTransferPayload($event);
    }
    $transferId = $transfer->id ?? NULL;

    $orderId = $this->resolveOrderId($transfer);

    if ($orderId === NULL) {
      $this->payoutLogger->error(
        'transfer.created webhook: Could not resolve order_id. Transfer @tid has no order_id in metadata or source charge. Skipping.',
        ['@tid' => $transferId ?? 'unknown']
      );
      return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
    }

    if (!$this->database->schema()->tableExists(self::TABLE)) {
      $this->payoutLogger->error('transfer.created webhook: Payout ledger table does not exist.');
      return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
    }

    $row = $this->database->select(self::TABLE, 'l')
      ->fields('l')
      ->condition('l.order_id', $orderId)
      ->execute()
      ->fetchObject();

    if (!$row) {
      $this->payoutLogger->warning(
        'transfer.created webhook: No ledger row for order @oid (transfer @tid). Ignoring.',
        ['@oid' => $orderId, '@tid' => $transferId]
      );
      return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
    }

    if ($row->status === 'paid') {
      if ($row->transfer_id === $transferId) {
        $this->payoutLogger->info(
          'transfer.created webhook: Ledger @lid (order @oid) already paid with same transfer @tid. Idempotent skip.',
          ['@lid' => $row->id, '@oid' => $orderId, '@tid' => $transferId]
        );
      }
      else {
        $this->payoutLogger->critical(
          'transfer.created webhook: MISMATCH — Ledger @lid (order @oid) already paid with transfer @existing but received @incoming.',
          ['@lid' => $row->id, '@oid' => $orderId, '@existing' => $row->transfer_id, '@incoming' => $transferId]
        );
      }
      return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
    }

    $this->database->update(self::TABLE)
      ->fields([
        'status' => 'paid',
        'transfer_id' => $transferId,
        'paid_at' => $this->time->getRequestTime(),
      ])
      ->condition('order_id', $orderId)
      ->execute();

    Cache::invalidateTags(self::CACHE_TAGS);

    $this->payoutLogger->info(
      'Payout synced from transfer.created. Order @oid marked paid via transfer @tid (ledger @lid, store @sid).',
      [
        '@oid' => $orderId,
        '@tid' => $transferId,
        '@lid' => $row->id,
        '@sid' => $row->store_id,
      ]
    );

    return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
  }

  /**
   * Flags a fully reversed transfer for manual payout review.
   */
  private function handleTransferReversed(Event $event): Response {
    $transfer = $event->data['object'] ?? NULL;
    if (!$transfer instanceof Transfer) {
      return $this->handleInvalidTransferPayload($event);
    }
    $transferId = $transfer->id ?? 'unknown';
    $amount = (int) ($transfer->amount ?? 0);
    $amountReversed = (int) ($transfer->amount_reversed ?? 0);
    $fullyReversed = (bool) ($transfer->reversed ?? FALSE)
      || ($amount > 0 && $amountReversed >= $amount);

    if (!$fullyReversed) {
      $this->payoutLogger->warning(
        'transfer.reversed webhook: Transfer @tid was partially reversed (@reversed of @amount). Ledger remains paid for manual review.',
        ['@tid' => $transferId, '@reversed' => $amountReversed, '@amount' => $amount],
      );
      return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
    }

    if (!$this->database->schema()->tableExists(self::TABLE)) {
      $this->payoutLogger->error('transfer.reversed webhook: Payout ledger table does not exist.');
      return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
    }

    $updated = $this->database->update(self::TABLE)
      ->fields([
        'status' => 'pending',
        'paid_at' => NULL,
      ])
      ->condition('transfer_id', $transferId)
      ->condition('status', 'paid')
      ->execute();

    if ($updated > 0) {
      Cache::invalidateTags(self::CACHE_TAGS);
      $this->payoutLogger->critical(
        'Transfer @tid was fully reversed. @count payout ledger row(s) moved to pending for manual review.',
        ['@tid' => $transferId, '@count' => $updated],
      );
    }
    else {
      $this->payoutLogger->warning(
        'transfer.reversed webhook: No paid payout ledger rows matched transfer @tid.',
        ['@tid' => $transferId],
      );
    }

    return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
  }

  /**
   * Handles unrecognised event types.
   */
  private function handleUnknown(Event $event): Response {
    $this->payoutLogger->info(
      'Stripe payout webhook received unhandled event type: @type',
      ['@type' => $event->type]
    );
    return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
  }

  /**
   * Acknowledges a transfer event whose verified payload has the wrong shape.
   */
  private function handleInvalidTransferPayload(Event $event): Response {
    $this->payoutLogger->error(
      'Stripe payout webhook @type did not contain a transfer object.',
      ['@type' => $event->type]
    );
    return new JsonResponse(['received' => TRUE], Response::HTTP_OK);
  }

  /**
   * Resolves order_id from a transfer object.
   *
   * Strategy:
   *   1. Check transfer->metadata['order_id'] (present if set explicitly).
   *   2. Fall back to source charge metadata via transfer->source_transaction.
   *
   * For destination charges, Stripe auto-creates transfers whose metadata is
   * empty. The order_id lives on the PaymentIntent/Charge metadata instead,
   * accessible via source_transaction.
   */
  private function resolveOrderId(Transfer $transfer): ?string {
    // 1. Direct transfer metadata (best case).
    $metadata = $transfer->metadata ?? NULL;
    if ($metadata !== NULL) {
      $orderId = $metadata['order_id'] ?? ($metadata->order_id ?? NULL);
      if (!empty($orderId)) {
        return (string) $orderId;
      }
    }

    // 2. Fall back to source charge metadata.
    $sourceTransaction = $transfer->source_transaction ?? NULL;
    if (empty($sourceTransaction)) {
      return NULL;
    }

    try {
      $client = $this->stripeService->getPlatformClient();
      $charge = $client->charges->retrieve((string) $sourceTransaction);
      $chargeOrderId = $charge->metadata['order_id'] ?? ($charge->metadata->order_id ?? NULL);
      if (!empty($chargeOrderId)) {
        return (string) $chargeOrderId;
      }
    }
    catch (\Exception $e) {
      $this->payoutLogger->warning(
        'Could not retrieve source charge @cid for transfer @tid: @msg',
        [
          '@cid' => $sourceTransaction,
          '@tid' => $transfer->id ?? 'unknown',
          '@msg' => $e->getMessage(),
        ]
      );
    }

    return NULL;
  }

}
