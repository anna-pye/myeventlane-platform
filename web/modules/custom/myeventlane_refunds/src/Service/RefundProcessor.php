<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_notifications\Service\RefundNotificationTriggerService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes refund requests and executes refunds.
 */
final class RefundProcessor implements RefundProcessorInterface {

  public const STATUS_PROCESSING = 'processing';
  public const STATUS_COMPLETED = 'completed';
  public const STATUS_PARTIAL = 'partial';
  public const STATUS_FAILED = 'failed';
  public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

  /**
   * Constructs RefundProcessor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_refunds\Service\RefundOrderInspectorInterface $orderInspector
   *   The order inspector.
   * @param \Drupal\myeventlane_refunds\Service\RefundAccessResolver $accessResolver
   *   The access resolver.
   * @param \Drupal\myeventlane_refunds\Service\BuyerRefundEligibilityService $buyerEligibility
   *   The buyer refund eligibility service.
   * @param \Drupal\myeventlane_refunds\Service\RefundRequestStorage $refundRequestStorage
   *   The refund request storage.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domainDetector
   *   The domain detector (for public-domain customer links).
   * @param \Drupal\myeventlane_refunds\Service\RefundAdjustmentNoteService $adjustmentNoteService
   *   Records immutable tax adjustment snapshots after confirmed refunds.
   * @param \Drupal\myeventlane_notifications\Service\RefundNotificationTriggerService|null $refundNotificationTrigger
   *   Optional in-app refund notifications (when myeventlane_notifications is enabled).
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RefundOrderInspectorInterface $orderInspector,
    private readonly RefundAccessResolver $accessResolver,
    private readonly BuyerRefundEligibilityService $buyerEligibility,
    private readonly RefundRequestStorage $refundRequestStorage,
    private readonly MessagingManager $messagingManager,
    private readonly StripeService $stripeService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly QueueFactory $queueFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly DomainDetector $domainDetector,
    private readonly RefundAdjustmentNoteService $adjustmentNoteService,
    private readonly ?RefundNotificationTriggerService $refundNotificationTrigger = NULL,
  ) {}

  /**
   * Gets the logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger.
   */
  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('myeventlane_refunds');
  }

  /**
   * Requests a buyer-initiated self-service refund.
   *
   * Creates refund_request (status=requested), sends emails. No Stripe call.
   * Vendor must approve before refund executes.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $buyer
   *   The buyer (order owner).
   *
   * @return int
   *   The refund request ID.
   *
   * @throws \Exception
   *   If eligibility fails or request creation fails.
   */
  public function requestBuyerRefund(OrderInterface $order, NodeInterface $event, AccountInterface $buyer, array $options = []): int {
    if (!$this->buyerEligibility->isEligible($order, $event, $buyer)) {
      $reason = $this->buyerEligibility->getIneligibilityReason($order, $event, $buyer);
      throw new \Exception($reason ?? 'Refund not eligible.');
    }

    $vendorUid = (int) $event->getOwnerId();
    $eventId = (int) $event->id();
    $buyerUid = (int) $buyer->id();

    if ($this->refundRequestStorage->hasActiveBuyerRequest((int) $order->id(), $eventId, $buyerUid)) {
      throw new \Exception('A pending refund request already exists for this order and event.');
    }

    $selectedAttendeeIds = array_values(array_unique(array_map('intval', (array) ($options['attendee_ids'] ?? []))));
    $selectedAttendeeIds = array_values(array_filter($selectedAttendeeIds, static fn(int $id): bool => $id > 0));

    $amountCents = 0;
    if (!empty($selectedAttendeeIds)) {
      $amountCents = $this->orderInspector->calculateSelectedAttendeeRefundCents($order, $eventId, $selectedAttendeeIds);
    }
    else {
      $amountCents = $this->orderInspector->calculateTicketSubtotalCents($order, $eventId);
    }

    if ($amountCents <= 0) {
      throw new \Exception('Refund amount must be greater than zero.');
    }
    $totalPrice = $order->getTotalPrice();
    $currency = $totalPrice ? strtoupper($totalPrice->getCurrencyCode()) : 'AUD';

    $requestId = $this->refundRequestStorage->create([
      'order_id' => $order->id(),
      'event_id' => $eventId,
      'buyer_uid' => $buyerUid,
      'vendor_uid' => $vendorUid,
      'amount_cents' => $amountCents,
      'currency' => strtolower($currency),
      'status' => RefundRequestStorage::STATUS_REQUESTED,
      'attendee_ids_json' => !empty($selectedAttendeeIds) ? json_encode($selectedAttendeeIds, JSON_UNESCAPED_SLASHES) : NULL,
    ]);

    $this->logger()->info('Buyer refund request: id=@id, order_id=@order_id', [
      '@id' => $requestId,
      '@order_id' => $order->id(),
    ]);

    $ctx = $this->buildRefundEmailContext($order, $event, $amountCents, $currency);
    $buyerEmail = $order->getEmail() ?: $this->getUserEmail((int) $buyer->id());
    $vendorEmail = $this->getUserEmail((int) $vendorUid);

    if ($buyerEmail) {
      $id = $this->messagingManager->queue('refund_requested_buyer', $buyerEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }
    if ($vendorEmail) {
      $id = $this->messagingManager->queue('refund_requested_vendor', $vendorEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }

    $requestRow = $this->refundRequestStorage->load($requestId);
    if ($requestRow !== NULL) {
      $this->refundNotificationTrigger?->onRefundRequested($requestRow, $order, $event);
    }

    return $requestId;
  }

  /**
   * Approves a buyer refund request (vendor action).
   *
   * Creates refund log, runs Stripe refund (via requestRefund → processRefund), sends emails.
   *
   * @param int $requestId
   *   The refund request ID.
   * @param \Drupal\Core\Session\AccountInterface $vendor
   *   The vendor (event owner).
   *
   * @return int
   *   The refund log ID.
   *
   * @throws \Exception
   *   If validation fails.
   */
  public function approveBuyerRefundRequest(int $requestId, AccountInterface $vendor): int {
    $req = $this->refundRequestStorage->load($requestId);
    if (!$req || $req['status'] !== RefundRequestStorage::STATUS_REQUESTED) {
      throw new \Exception('Refund request not found or not pending.');
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load($req['order_id']);
    $event = $this->entityTypeManager->getStorage('node')->load($req['event_id']);
    if (!$order instanceof OrderInterface || !$event instanceof NodeInterface) {
      throw new \Exception('Order or event not found.');
    }

    if (!$this->accessResolver->vendorCanRefundOrderForEvent($order, $event, $vendor)) {
      throw new \Exception('Access denied: vendor cannot approve this refund.');
    }

    $selectedAttendeeIds = $this->decodeAttendeeIds((string) ($req['attendee_ids_json'] ?? ''));
    $payload = [
      'refund_type' => !empty($selectedAttendeeIds) ? 'partial' : 'full',
      'refund_scope' => 'tickets_only',
      'include_donation' => FALSE,
      'reason' => 'Buyer self-service refund (vendor approved)',
      'refund_request_id' => $requestId,
    ];
    if (!empty($selectedAttendeeIds)) {
      $payload['amount_cents'] = (int) ($req['amount_cents'] ?? 0);
      $payload['attendee_ids'] = $selectedAttendeeIds;
    }

    $logId = $this->requestRefund($order, $event, $vendor, $payload);
    $this->refundRequestStorage->update($requestId, [
      'status' => RefundRequestStorage::STATUS_APPROVED,
      'refund_log_id' => $logId,
    ]);

    $ctx = $this->buildRefundEmailContext(
      $order,
      $event,
      (int) $req['amount_cents'],
      (string) ($req['currency'] ?? 'aud')
    );
    $buyerEmail = $order->getEmail() ?: $this->getUserEmail((int) $req['buyer_uid']);
    $vendorEmail = $this->getUserEmail((int) $vendor->id());

    if ($buyerEmail) {
      $id = $this->messagingManager->queue('refund_approved_buyer', $buyerEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }
    if ($vendorEmail) {
      $id = $this->messagingManager->queue('refund_approved_vendor', $vendorEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }

    $this->refundNotificationTrigger?->onRefundApproved($req, $order, $event);

    return $logId;
  }

  /**
   * Rejects a buyer refund request (vendor action).
   *
   * @param int $requestId
   *   The refund request ID.
   * @param \Drupal\Core\Session\AccountInterface $vendor
   *   The vendor.
   * @param string $reason
   *   Rejection reason (required).
   *
   * @throws \Exception
   *   If validation fails.
   */
  public function rejectBuyerRefundRequest(int $requestId, AccountInterface $vendor, string $reason): void {
    $req = $this->refundRequestStorage->load($requestId);
    if (!$req || $req['status'] !== RefundRequestStorage::STATUS_REQUESTED) {
      throw new \Exception('Refund request not found or not pending.');
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load($req['order_id']);
    $event = $this->entityTypeManager->getStorage('node')->load($req['event_id']);
    if (!$order instanceof OrderInterface || !$event instanceof NodeInterface) {
      throw new \Exception('Order or event not found.');
    }

    if (!$this->accessResolver->vendorCanRefundOrderForEvent($order, $event, $vendor)) {
      throw new \Exception('Access denied: vendor cannot reject this refund.');
    }

    $this->refundRequestStorage->update($requestId, [
      'status' => RefundRequestStorage::STATUS_REJECTED,
      'decision_reason' => $reason,
    ]);

    $ctx = $this->buildRefundEmailContext($order, $event, (int) $req['amount_cents'], $req['currency']);
    $ctx['rejection_reason'] = $reason;
    $buyerEmail = $order->getEmail() ?: $this->getUserEmail((int) $req['buyer_uid']);
    $vendorEmail = $this->getUserEmail((int) $vendor->id());

    if ($buyerEmail) {
      $id = $this->messagingManager->queue('refund_rejected_buyer', $buyerEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }
    if ($vendorEmail) {
      $id = $this->messagingManager->queue('refund_rejected_vendor', $vendorEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }

    $this->refundNotificationTrigger?->onRefundRejected($req, $order, $event);
  }

  /**
   * Requests one ticket-only refund for an event cancellation.
   *
   * The persistent audit-log lookup prevents later queue batches from creating
   * another refund. The lock closes the same gap between concurrent workers.
   */
  public function requestEventCancellationRefund(
    OrderInterface $order,
    NodeInterface $event,
    AccountInterface $account,
  ): int {
    $orderId = (int) $order->id();
    $eventId = (int) $event->id();
    $lockId = "event_cancel_refund:$eventId:$orderId";

    if (!$this->lock->acquire($lockId, 30.0)) {
      $this->lock->wait($lockId, 30);
      if (!$this->lock->acquire($lockId, 30.0)) {
        $existingId = $this->findEventCancellationRefundId($orderId, $eventId);
        if ($existingId !== NULL) {
          return $existingId;
        }
        throw new \RuntimeException('An event cancellation refund is already being prepared.');
      }
    }

    try {
      $existingId = $this->findEventCancellationRefundId($orderId, $eventId);
      if ($existingId !== NULL) {
        return $existingId;
      }

      return $this->requestRefund($order, $event, $account, [
        'refund_type' => 'full',
        'refund_scope' => 'tickets_only',
        'include_donation' => FALSE,
        'reason' => 'Event cancelled',
      ]);
    }
    finally {
      $this->lock->release($lockId);
    }
  }

  /**
   * Finds an existing cancellation refund for one order and event.
   */
  private function findEventCancellationRefundId(int $orderId, int $eventId): ?int {
    $id = $this->database->select('myeventlane_refund_log', 'refund')
      ->fields('refund', ['id'])
      ->condition('order_id', $orderId)
      ->condition('event_id', $eventId)
      ->condition('refund_type', 'full')
      ->condition('refund_scope', 'tickets_only')
      ->condition('donation_refunded', 0)
      ->condition('reason', 'Event cancelled')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $id === FALSE ? NULL : (int) $id;
  }

  /**
   * Requests a refund (creates audit log, enqueues worker, runs processing now).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The vendor account.
   * @param array $payload
   *   Refund payload with keys:
   *   - refund_type: 'full' or 'partial'
   *   - refund_scope: 'tickets_only', 'tickets_and_donation', or 'donation_only'
   *   - amount_cents: int (for partial refunds)
   *   - reason: string (optional)
   *   - include_donation: bool (optional, default FALSE)
   *
   * @return int
   *   The refund log ID.
   *
   * @throws \Exception
   *   If validation fails or log creation fails.
   * @throws \RuntimeException
   *   If the log is marked failed after processing.
   */
  public function requestRefund(OrderInterface $order, NodeInterface $event, AccountInterface $account, array $payload): int {
    $logId = $this->createRefundLog($order, $event, $account, $payload);
    $this->logger()->notice('Refund log created: @id', ['@id' => $logId]);
    $this->requestRefundByLogId($logId);
    return $logId;
  }

  /**
   * Creates the refund log row without changing the public API.
   */
  private function createRefundLog(OrderInterface $order, NodeInterface $event, AccountInterface $account, array $payload): int {
    $this->logger()->notice('requestRefund invoked: order_id=@order_id event_id=@event_id vendor_uid=@uid refund_type=@refund_type', [
      '@order_id' => $order->id(),
      '@event_id' => $event->id(),
      '@uid' => $account->id(),
      '@refund_type' => (string) ($payload['refund_type'] ?? ''),
    ]);

    if (!$this->accessResolver->vendorCanRefundOrderForEvent($order, $event, $account)) {
      throw new \Exception('Access denied: vendor cannot refund this order.');
    }

    $orderState = $order->getState()->getId();
    $refundableStates = [
      'completed',
      'fulfilled',
      'placed',
      'fulfillment',
      'partially_refunded',
    ];
    if (!in_array($orderState, $refundableStates, TRUE)) {
      throw new \Exception('Order is not in a refundable state.');
    }

    $refundType = $payload['refund_type'] ?? 'full';
    $refundScope = $payload['refund_scope'] ?? 'tickets_only';
    $includeDonation = $payload['include_donation'] ?? FALSE;
    $selectedAttendeeIds = array_values(array_unique(array_map('intval', (array) ($payload['attendee_ids'] ?? []))));
    $selectedAttendeeIds = array_values(array_filter($selectedAttendeeIds, static fn(int $id): bool => $id > 0));

    $amountCents = 0;
    $donationRefunded = 0;

    if ($refundType === 'full') {
      if ($refundScope === 'tickets_only' || ($refundScope === 'tickets_and_donation' && !$includeDonation)) {
        $amountCents = $this->orderInspector->calculateTicketSubtotalCents($order, (int) $event->id());
      }
      elseif ($refundScope === 'tickets_and_donation' && $includeDonation) {
        $amountCents = $this->orderInspector->calculateTicketSubtotalCents($order, (int) $event->id());
        $donationAmount = $this->orderInspector->calculateDonationTotalCents($order);
        $amountCents += $donationAmount;
        $donationRefunded = 1;
      }
      elseif ($refundScope === 'donation_only') {
        $amountCents = $this->orderInspector->calculateDonationTotalCents($order);
        $donationRefunded = 1;
      }
    }
    else {
      $amountCents = (int) ($payload['amount_cents'] ?? 0);
      if ($includeDonation) {
        $donationRefunded = 1;
      }
      if ($refundScope === 'tickets_only' && !empty($selectedAttendeeIds)) {
        $amountCents = $this->orderInspector->calculateSelectedAttendeeRefundCents($order, (int) $event->id(), $selectedAttendeeIds);
      }
    }

    if ($amountCents <= 0) {
      throw new \Exception('Refund amount must be greater than zero.');
    }

    $refundableCents = $this->orderInspector->calculateRefundableAmountCents($order);
    if ($amountCents > $refundableCents) {
      throw new \Exception('Refund amount exceeds refundable amount.');
    }

    $totalPrice = $order->getTotalPrice();
    $currency = $totalPrice ? strtoupper($totalPrice->getCurrencyCode()) : 'AUD';
    $now = $this->time->getRequestTime();

    $logFields = [
      'order_id' => $order->id(),
      'event_id' => $event->id(),
      'vendor_uid' => $account->id(),
      'refund_type' => $refundType,
      'refund_scope' => $refundScope,
      'amount_cents' => $amountCents,
      'currency' => strtolower($currency),
      'donation_refunded' => $donationRefunded,
      'status' => self::STATUS_PROCESSING,
      'reason' => $payload['reason'] ?? NULL,
      'failure_reason' => NULL,
      'retry_count' => 0,
      'next_attempt_at' => NULL,
      'retry_of' => NULL,
      'actual_refunded_cents' => 0,
      'created' => $now,
      'updated' => $now,
      'attendee_ids_json' => !empty($selectedAttendeeIds) ? json_encode($selectedAttendeeIds, JSON_UNESCAPED_SLASHES) : NULL,
    ];
    if (isset($payload['refund_request_id'])) {
      $logFields['refund_request_id'] = $payload['refund_request_id'];
    }

    $logId = (int) $this->database->insert('myeventlane_refund_log')
      ->fields($logFields)
      ->execute();

    $this->logger()->notice('Vendor refund log created: log_id=@log_id order_id=@order_id amount_cents=@amount_cents.', [
      '@log_id' => $logId,
      '@order_id' => $order->id(),
      '@amount_cents' => $amountCents,
    ]);

    return $logId;
  }

  /**
   * Attempts refund execution immediately, then falls back to queue.
   */
  public function requestRefundByLogId(int $logId): void {
    // Do not move an already completed refund back to processing. This guard
    // also prevents replayed queue items from repeating completion side effects.
    $existing = $this->loadRefundLog($logId);
    if ($existing !== NULL && (
      (string) ($existing['status'] ?? '') === self::STATUS_COMPLETED
      || !empty($existing['stripe_refund_id'])
    )) {
      return;
    }

    $this->setRefundStatus($logId, self::STATUS_PROCESSING);

    try {
      $result = $this->processRefund($logId);

      if ($result !== self::STATUS_COMPLETED) {
        $this->enqueueRefund($logId);
      }
    }
    catch (\Throwable $e) {
      $this->markRefundFailed($logId, $e->getMessage());

      throw new \RuntimeException(
        'Refund failed — no money has been returned. You can retry safely.'
      );
    }
  }

  /**
   * Enqueues a queue retry for the refund log.
   */
  private function enqueueRefund(int $logId): void {
    $this->queueFactory->get('vendor_refund_worker')->createItem([
      'refund_log_id' => $logId,
    ]);
  }

  /**
   * Loads a refund log by ID.
   */
  public function loadRefundLog(int $logId): ?array {
    $row = $this->database->select('myeventlane_refund_log', 'r')
      ->fields('r')
      ->condition('id', $logId)
      ->execute()
      ->fetchAssoc();

    return $row ?: NULL;
  }

  /**
   * Loads the newest refund log for an order, optionally scoped to an event.
   */
  public function getLatestRefundForOrder(int $orderId, ?int $eventId = NULL): ?array {
    $query = $this->database->select('myeventlane_refund_log', 'r')
      ->fields('r')
      ->condition('order_id', $orderId)
      ->range(0, 1)
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC');

    if ($eventId !== NULL) {
      $query->condition('event_id', $eventId);
    }

    $row = $query->execute()->fetchAssoc();

    return $row ?: NULL;
  }

  /**
   * Aggregates refund log status counts for an event.
   */
  public function getEventRefundStats(int $eventId): array {
    $query = $this->database->select('myeventlane_refund_log', 'r');
    $query->fields('r', ['status']);
    $query->addExpression('COUNT(*)', 'refund_count');
    $query->condition('event_id', $eventId);
    $query->groupBy('status');

    $results = $query->execute()->fetchAllAssoc('status');
    $breakdown = [];
    $total = 0;
    $failed = 0;
    $pending = 0;

    foreach ($results as $status => $row) {
      $count = (int) ($row->refund_count ?? 0);
      $breakdown[$status] = $count;
      $total += $count;

      if ($status === self::STATUS_FAILED) {
        $failed += $count;
      }

      if (in_array($status, [self::STATUS_PROCESSING, self::STATUS_PENDING_CONFIRMATION], TRUE)) {
        $pending += $count;
      }
    }

    return [
      'total' => $total,
      'failed' => $failed,
      'pending' => $pending,
      'breakdown' => $breakdown,
    ];
  }

  /**
   * Returns refund log rows for an order, oldest first.
   *
   * @return array<int, \stdClass>
   *   Rows keyed by refund log ID.
   */
  public function getRefundTimelineForOrder(int $orderId): array {
    if (!$this->database->schema()->tableExists('myeventlane_refund_log')) {
      return [];
    }

    return $this->database->select('myeventlane_refund_log', 'r')
      ->fields('r')
      ->condition('order_id', $orderId)
      ->orderBy('created', 'ASC')
      ->execute()
      ->fetchAllAssoc('id');
  }

  /**
   * Whether a refund log row is eligible for a vendor retry.
   *
   * @param array<string, mixed> $log
   *   Refund log row (e.g. from loadRefundLog()).
   */
  public function canRetry(array $log): bool {
    return ($log['status'] ?? '') === self::STATUS_FAILED;
  }

  /**
   * Short, vendor-facing hints derived from event refund log stats.
   *
   * @return string[]
   *   Non-translated English strings for presentation layer to wrap.
   */
  public function getRefundInsights(int $eventId): array {
    $stats = $this->getEventRefundStats($eventId);
    $insights = [];

    if (($stats['failed'] ?? 0) > 0) {
      $insights[] = 'Some refunds are failing — check payment setup.';
    }
    if (($stats['pending'] ?? 0) > 3) {
      $insights[] = 'Multiple refunds are still processing.';
    }
    if (($stats['total'] ?? 0) === 0) {
      $insights[] = 'No refunds yet — your event is performing well.';
    }

    return $insights;
  }

  /**
   * Loads refundable payments for the refund log in deterministic order.
   */
  private function loadRefundablePayments(array $log): array {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load((int) $log['order_id']);
    if (!$order instanceof OrderInterface) {
      throw new \RuntimeException('Order not found.');
    }

    $event = $this->entityTypeManager->getStorage('node')->load((int) $log['event_id']);
    if (!$event instanceof NodeInterface) {
      throw new \RuntimeException('Event not found.');
    }

    $vendor = $this->entityTypeManager->getStorage('user')->load((int) $log['vendor_uid']);
    if (!$vendor instanceof AccountInterface) {
      throw new \RuntimeException('Vendor user not found.');
    }

    if (!$this->accessResolver->vendorCanRefundOrderForEvent($order, $event, $vendor)) {
      throw new \RuntimeException('Access denied: vendor cannot refund this order.');
    }

    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $paymentIds = $paymentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order->id())
      ->condition('state', ['completed', 'partially_refunded'], 'IN')
      ->sort('payment_id', 'ASC')
      ->execute();

    if (empty($paymentIds)) {
      return [];
    }

    $currency = strtoupper((string) ($log['currency'] ?? 'AUD'));
    $payments = [];
    foreach ($paymentStorage->loadMultiple($paymentIds) as $payment) {
      if (!$payment instanceof PaymentInterface) {
        continue;
      }

      $amount = $payment->getAmount();
      $refundedAmount = $payment->getRefundedAmount();
      if (strtoupper($amount->getCurrencyCode()) !== $currency || strtoupper($refundedAmount->getCurrencyCode()) !== $currency) {
        continue;
      }

      $payments[] = $payment;
    }

    return $payments;
  }

  /**
   * Processes a refund with locking and idempotent guards.
   */
  public function processRefund(int $logId): string {
    $lock_id = "refund_process_$logId";

    if (!$this->lock->acquire($lock_id, 30)) {
      return self::STATUS_PROCESSING;
    }

    try {
      $log = $this->loadRefundLog($logId);

      if (!$log) {
        throw new \RuntimeException('Refund log not found');
      }

      if (!empty($log['stripe_refund_id'])) {
        return self::STATUS_COMPLETED;
      }

      $currentStatus = (string) ($log['status'] ?? '');
      $retryableStatuses = [
        self::STATUS_PROCESSING,
        self::STATUS_PARTIAL,
        self::STATUS_PENDING_CONFIRMATION,
      ];
      if (!in_array($currentStatus, $retryableStatuses, TRUE)) {
        if ($currentStatus !== '') {
          return $currentStatus;
        }

        $this->logger()->warning('Skipping refund processing for log @log_id because the status is missing.', [
          '@log_id' => $logId,
        ]);
        return self::STATUS_FAILED;
      }

      $requestedAmount = (int) ($log['amount_cents'] ?? 0);
      $previouslyRefunded = max(0, (int) ($log['actual_refunded_cents'] ?? 0));
      $remaining = max(0, $requestedAmount - $previouslyRefunded);
      $payments = $this->loadRefundablePayments($log);

      if (empty($payments)) {
        throw new \RuntimeException('No refundable payments found.');
      }

      $refunded = $previouslyRefunded;
      $unconfirmed = $remaining === 0 && empty($log['stripe_refund_id']) && $previouslyRefunded > 0;
      $finalRefundId = '';

      foreach ($payments as $payment) {
        if ($remaining <= 0) {
          break;
        }

        $available = $this->getPaymentAvailableBalance($payment);
        if ($available <= 0) {
          continue;
        }

        $amount = min($remaining, $available);

        try {
          $refundId = $this->refundPayment($payment, $amount);

          if ($refundId !== '') {
            $finalRefundId = $refundId;
          }

          $refunded += $amount;
          $remaining -= $amount;

          if (!$this->isPaymentConfirmed($payment)) {
            $unconfirmed = TRUE;
          }
        }
        catch (\Throwable $e) {
          $this->logger()->error('Refund slice failed for log @log_id on payment @payment_id: @message', [
            '@log_id' => $logId,
            '@payment_id' => (int) $payment->id(),
            '@message' => $e->getMessage(),
          ]);
          $this->markRefundFailed($logId, $e->getMessage());
          return self::STATUS_FAILED;
        }
      }

      if ($refunded === 0) {
        $this->markRefundFailed($logId, 'No funds refunded.');
        return self::STATUS_FAILED;
      }

      if ($remaining === 0 && empty($log['stripe_refund_id'])) {
        foreach ($payments as $payment) {
          if (!$payment instanceof PaymentInterface) {
            continue;
          }
          $confirmedRefundId = $this->lookupAnyConfirmedRefundId($payment);
          if ($confirmedRefundId !== NULL) {
            $finalRefundId = $confirmedRefundId;
            $unconfirmed = FALSE;
            break;
          }
        }
      }

      $this->setRefundAmount($logId, $refunded);

      if ($remaining > 0) {
        $this->setRefundStatus($logId, self::STATUS_PARTIAL);
        return self::STATUS_PARTIAL;
      }

      if ($finalRefundId !== '') {
        $this->saveStripeRefundId($logId, $finalRefundId);
      }

      if ($unconfirmed) {
        $this->setRefundStatus($logId, self::STATUS_PENDING_CONFIRMATION);
        return self::STATUS_PENDING_CONFIRMATION;
      }

      $this->setRefundStatus($logId, self::STATUS_COMPLETED);
      $this->finalizeCompletedRefund($logId);
      return self::STATUS_COMPLETED;
    }
    finally {
      $this->lock->release($lock_id);
    }
  }

  /**
   * Marks a refund as failed and preserves legacy fields.
   */
  public function markRefundFailed(int $logId, string $reason): void {
    $now = time();
    $this->database->update('myeventlane_refund_log')
      ->fields([
        'status' => self::STATUS_FAILED,
        'failure_reason' => $reason,
        'error_message' => $reason,
        'updated' => $now,
        'completed' => $now,
      ])
      ->condition('id', $logId)
      ->execute();

    $this->logger()->error('Refund FAILED @id: @reason', [
      '@id' => $logId,
      '@reason' => $reason,
    ]);

    $this->notifyVendor($logId, self::STATUS_FAILED);

    $log = $this->loadRefundLog($logId);
    if ($log === NULL) {
      return;
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load((int) ($log['order_id'] ?? 0));
    $event = $this->entityTypeManager->getStorage('node')->load((int) ($log['event_id'] ?? 0));
    $this->queueRefundFailureEmails(
      $order instanceof OrderInterface ? $order : NULL,
      $event instanceof NodeInterface ? $event : NULL,
      $log,
      $reason
    );
  }

  /**
   * Updates refund status while preserving legacy timestamps.
   */
  private function setRefundStatus(int $logId, string $status): void {
    $fields = [
      'status' => $status,
      'updated' => time(),
    ];

    if ($status === self::STATUS_COMPLETED) {
      $fields['completed'] = time();
    }

    $this->database->update('myeventlane_refund_log')
      ->fields($fields)
      ->condition('id', $logId)
      ->execute();

    $this->notifyVendor($logId, $status);
  }

  /**
   * Vendor-facing notification hook for refund status changes (log today; mail/SMS later).
   */
  private function notifyVendor(int $logId, string $status): void {
    $log = $this->loadRefundLog($logId);
    if ($log === NULL) {
      $this->logger()->warning('Refund notification skipped: log @id not found.', [
        '@id' => $logId,
      ]);
      return;
    }

    $vendorUid = (int) ($log['vendor_uid'] ?? 0);
    $account = $vendorUid > 0
      ? $this->entityTypeManager->getStorage('user')->load($vendorUid)
      : NULL;
    $vendorName = $account instanceof AccountInterface ? $account->getAccountName() : 'unknown';

    $this->logger()->notice('Refund notification: status=@status log=@id order=@order event=@event vendor_uid=@uid (@name)', [
      '@status' => $status,
      '@id' => (string) $logId,
      '@order' => (string) ($log['order_id'] ?? ''),
      '@event' => (string) ($log['event_id'] ?? ''),
      '@uid' => (string) $vendorUid,
      '@name' => $vendorName,
    ]);
  }

  /**
   * Saves the Stripe refund ID when available.
   */
  private function saveStripeRefundId(int $logId, string $refundId): void {
    $this->database->update('myeventlane_refund_log')
      ->fields([
        'stripe_refund_id' => $refundId,
        'updated' => time(),
      ])
      ->condition('id', $logId)
      ->execute();
  }

  /**
   * Stores the actual refunded amount in cents.
   */
  private function setRefundAmount(int $logId, int $amount): void {
    $this->database->update('myeventlane_refund_log')
      ->fields([
        'actual_refunded_cents' => $amount,
        'updated' => time(),
      ])
      ->condition('id', $logId)
      ->execute();
  }

  /**
   * Schedules the next retry attempt with fixed backoff.
   */
  public function scheduleRetry(int $logId, int $retryCount): void {
    $delays = [60, 300, 900, 3600, 21600];

    if ($retryCount >= count($delays)) {
      $this->markRefundFailed($logId, 'Max retries reached');
      return;
    }

    $next = time() + $delays[$retryCount];

    $this->database->update('myeventlane_refund_log')
      ->fields([
        'retry_count' => $retryCount + 1,
        'next_attempt_at' => $next,
        'updated' => time(),
      ])
      ->condition('id', $logId)
      ->execute();
  }

  /**
   * Creates a new retry log and re-runs processing against it.
   */
  public function retryRefund(int $failedLogId): int {
    $failed = $this->loadRefundLog($failedLogId);
    if ($failed === NULL || (string) ($failed['status'] ?? '') !== self::STATUS_FAILED) {
      throw new \RuntimeException('Only failed refunds can be retried.');
    }

    $newLogId = (int) $this->database->insert('myeventlane_refund_log')
      ->fields([
        'order_id' => $failed['order_id'],
        'event_id' => $failed['event_id'],
        'vendor_uid' => $failed['vendor_uid'],
        'refund_type' => $failed['refund_type'],
        'refund_scope' => $failed['refund_scope'],
        'amount_cents' => $failed['amount_cents'],
        'currency' => $failed['currency'],
        'donation_refunded' => $failed['donation_refunded'],
        'status' => self::STATUS_PROCESSING,
        'reason' => $failed['reason'] ?? NULL,
        'failure_reason' => NULL,
        'retry_count' => 0,
        'next_attempt_at' => NULL,
        'retry_of' => $failed['id'],
        'refund_request_id' => $failed['refund_request_id'] ?? NULL,
        'attendee_ids_json' => $failed['attendee_ids_json'] ?? NULL,
        'actual_refunded_cents' => 0,
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    $this->requestRefundByLogId($newLogId);

    return $newLogId;
  }

  /**
   * Finalizes completion side effects once the refund becomes completed.
   */
  private function finalizeCompletedRefund(int $logId): void {
    $log = $this->loadRefundLog($logId);
    if ($log === NULL) {
      return;
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load((int) ($log['order_id'] ?? 0));
    $event = $this->entityTypeManager->getStorage('node')->load((int) ($log['event_id'] ?? 0));
    if (!$order instanceof OrderInterface || !$event instanceof NodeInterface) {
      return;
    }

    // Persist the adjustment snapshot before any hook or notification. A
    // duplicate webhook/retry reads the same note instead of issuing another.
    $log = $this->adjustmentNoteService->record($order, $log);

    $this->moduleHandler->invokeAll('myeventlane_refund_completed', [
      $order,
      $event,
      $log,
    ]);

    $ticketsCancelled = $this->cancelRefundedTicketAttendees($order, $event, $log);
    $customerEmail = $order->getEmail() ?: '';
    $this->queueRefundCompletionEmails($order, $event, $log, $customerEmail, $ticketsCancelled);
  }

  /**
   * Computes the currently refundable balance for a payment.
   */
  private function getPaymentAvailableBalance(PaymentInterface $payment): int {
    if (method_exists($payment, 'getBalance')) {
      $balance = $payment->getBalance();
      if ($balance instanceof Price) {
        return max(0, $this->priceToCents($balance));
      }

      if (is_numeric($balance)) {
        return max(0, (int) $balance);
      }

      $this->logger()->warning('Unexpected payment balance type for payment @payment_id: @type. Falling back to amount minus refunded.', [
        '@payment_id' => (int) $payment->id(),
        '@type' => get_debug_type($balance),
      ]);
    }

    return max(0, $this->priceToCents($payment->getAmount()) - $this->priceToCents($payment->getRefundedAmount()));
  }

  /**
   * Executes the payment gateway refund and verifies the refund actually landed.
   */
  private function refundPayment(PaymentInterface $payment, int $amount): string {
    $paymentId = (int) $payment->id();
    $gateway = $payment->getPaymentGateway();
    if (!$gateway) {
      throw new \RuntimeException(sprintf('Payment gateway missing for payment %d.', $paymentId));
    }

    $plugin = $gateway->getPlugin();
    if (!method_exists($plugin, 'refundPayment')) {
      throw new \RuntimeException(sprintf('Payment gateway plugin does not support refunds for payment %d.', $paymentId));
    }

    $currency = strtolower($payment->getAmount()->getCurrencyCode());
    $windowStart = $this->time->getRequestTime();
    $beforeAvailable = $this->getPaymentAvailableBalance($payment);
    $beforeRefunded = $this->priceToCents($payment->getRefundedAmount());

    $this->logger()->notice('Attempting refund slice for payment @payment_id: amount_cents=@amount currency=@currency available_before=@available refunded_before=@refunded', [
      '@payment_id' => $paymentId,
      '@amount' => $amount,
      '@currency' => $currency,
      '@available' => $beforeAvailable,
      '@refunded' => $beforeRefunded,
    ]);

    $plugin->refundPayment(
      $payment,
      new Price($this->centsToDecimalString($amount), $payment->getAmount()->getCurrencyCode())
    );

    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $paymentStorage->resetCache([$paymentId]);
    $reloadedPayment = $paymentStorage->load($paymentId);

    if (!$reloadedPayment instanceof PaymentInterface) {
      throw new \RuntimeException(sprintf('Refund verification failed: payment %d could not be reloaded.', $paymentId));
    }

    $afterAvailable = $this->getPaymentAvailableBalance($reloadedPayment);
    $afterRefunded = $this->priceToCents($reloadedPayment->getRefundedAmount());
    $balanceChanged = $afterAvailable < $beforeAvailable || $afterRefunded > $beforeRefunded;
    $refundId = $this->confirmStripeRefundId(
      $reloadedPayment,
      $amount,
      $currency,
      $windowStart
    );

    // Important: do not hard-fail here just because verification is delayed.
    // Let the processor treat this as pending confirmation.
    if (!$balanceChanged && $refundId === NULL) {
      $this->logger()->warning('Refund not yet confirmed for payment @payment_id: no balance change and no Stripe refund ID yet.', [
        '@payment_id' => $paymentId,
      ]);
      return '';
    }

    if (!$balanceChanged && $refundId !== NULL) {
      $this->logger()->warning('Refund verified by Stripe but payment state did not change for payment @payment_id. refund_id=@refund_id', [
        '@payment_id' => $paymentId,
        '@refund_id' => $refundId,
      ]);
    }

    if ($balanceChanged) {
      $this->logger()->notice('Refund slice verified for payment @payment_id: amount_cents=@amount available_after=@available refunded_after=@refunded', [
        '@payment_id' => $paymentId,
        '@amount' => $amount,
        '@available' => $afterAvailable,
        '@refunded' => $afterRefunded,
      ]);
    }

    if ($refundId === NULL) {
      $this->logger()->warning('Refund verified without Stripe refund ID for payment @payment_id.', [
        '@payment_id' => $paymentId,
      ]);
      return '';
    }

    return $refundId;
  }

  /**
   * Determines whether a payment appears confirmed as refunded.
   */
  private function isPaymentConfirmed(PaymentInterface $payment): bool {
    $state = $payment->getState()->getId();
    if (in_array($state, ['partially_refunded', 'refunded'], TRUE)) {
      return TRUE;
    }

    if (!$payment->getRefundedAmount()->isZero()) {
      return TRUE;
    }

    return $this->lookupAnyConfirmedRefundId($payment) !== NULL;
  }

  /**
   * Looks for any succeeded Stripe refund attached to the payment.
   */
  private function lookupAnyConfirmedRefundId(PaymentInterface $payment): ?string {
    $remoteId = (string) ($payment->getRemoteId() ?? '');
    if ($remoteId === '') {
      return NULL;
    }

    try {
      $client = $this->stripeService->getPlatformClient();
      $params = ['limit' => 10];
      if (str_starts_with($remoteId, 'pi_')) {
        $params['payment_intent'] = $remoteId;
      }
      else {
        $params['charge'] = $remoteId;
      }

      $refunds = $client->refunds->all($params);
      foreach ($refunds->data as $refund) {
        if ((string) ($refund->status ?? '') === 'succeeded' && !empty($refund->id)) {
          return (string) $refund->id;
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger()->warning('Stripe refund lookup failed for payment @payment_id: @message', [
        '@payment_id' => (int) $payment->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Queues refund completion emails to buyer, vendor, and admin.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event.
   * @param array $log
   *   The refund log data.
   * @param string $customerEmail
   *   The customer email.
   * @param int $ticketsCancelled
   *   Number of attendee records cancelled for this refund.
   */
  private function queueRefundCompletionEmails(
    OrderInterface $order,
    NodeInterface $event,
    array $log,
    string $customerEmail,
    int $ticketsCancelled = 0,
  ): void {
    $ctx = $this->buildRefundEmailContext(
      $order,
      $event,
      (int) ($log['actual_refunded_cents'] ?? $log['amount_cents']),
      $log['currency'],
      (bool) ($log['donation_refunded'] ?? FALSE)
    );
    $ctx['stripe_refund_id'] = (string) ($log['stripe_refund_id'] ?? '');
    $ctx['tickets_cancelled'] = $ticketsCancelled;
    $ctx['adjustment_note_number'] = (string) ($log['adjustment_note_number'] ?? '');
    $ctx['gst_adjustment'] = strtoupper((string) ($log['currency'] ?? 'aud')) . ' ' . number_format(((int) ($log['gst_adjustment_cents'] ?? 0)) / 100, 2);
    $ctx['supplier_name'] = (string) ($log['supplier_name_snapshot'] ?? '');
    $ctx['supplier_abn'] = (string) ($log['supplier_abn_snapshot'] ?? '');
    $ctx['adjustment_note_date'] = !empty($log['adjustment_note_created'])
      ? date('j M Y', (int) $log['adjustment_note_created'])
      : '';
    $ctx['adjustment_reason'] = trim((string) ($log['reason'] ?? 'Refund')) ?: 'Refund';

    if (!empty($customerEmail)) {
      $id = $this->messagingManager->queue('refund_completed_buyer', $customerEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }

    $vendorEmail = $this->getUserEmail((int) ($log['vendor_uid'] ?? 0));
    if ($vendorEmail) {
      $id = $this->messagingManager->queue('refund_completed_vendor', $vendorEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }

    $adminEmail = $this->getAdminEmail();
    if ($adminEmail) {
      $id = $this->messagingManager->queue('refund_completed_admin', $adminEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }
    else {
      $this->logger()->warning('Refund completion admin notification skipped: system.site mail is not configured.');
    }

    $refundRequestId = $log['refund_request_id'] ?? NULL;
    if ($refundRequestId) {
      $this->refundRequestStorage->update((int) $refundRequestId, ['status' => RefundRequestStorage::STATUS_COMPLETED]);
    }

    $vendorUid = (int) ($log['vendor_uid'] ?? $event->getOwnerId());
    $this->refundNotificationTrigger?->onRefundCompleted($order, $event, $vendorUid);
  }

  /**
   * Cancels ticket attendee records when a ticket refund is completed.
   *
   * Only full ticket-inclusive refunds auto-cancel attendees. Partial refunds
   * are intentionally not auto-cancelled because attendee selection is
   * ambiguous without explicit ticket-line targeting.
   */
  private function cancelRefundedTicketAttendees(OrderInterface $order, NodeInterface $event, array $log): int {
    $refundScope = (string) ($log['refund_scope'] ?? '');
    if ($refundScope === 'donation_only') {
      return 0;
    }

    $refundType = (string) ($log['refund_type'] ?? '');
    $selectedAttendeeIds = $this->decodeAttendeeIds((string) ($log['attendee_ids_json'] ?? ''));
    if ($refundType !== 'full' && empty($selectedAttendeeIds)) {
      $this->logger()->warning('Skipped attendee auto-cancellation for refund log @log_id: partial refund without attendee selection.', [
        '@log_id' => (int) ($log['id'] ?? 0),
      ]);
      return 0;
    }

    $eventId = (int) $event->id();
    if ($eventId <= 0) {
      $this->logger()->error('Failed attendee auto-cancellation: invalid event ID for refund log @log_id.', [
        '@log_id' => (int) ($log['id'] ?? 0),
      ]);
      return 0;
    }

    $orderItemIds = [];
    foreach ($order->getItems() as $item) {
      if ($this->orderItemIsForEvent($item, $eventId)) {
        $orderItemIds[] = (int) $item->id();
      }
    }
    $orderItemIds = array_values(array_unique(array_filter($orderItemIds)));
    if (empty($orderItemIds)) {
      $this->logger()->warning('No event-linked order items found for attendee auto-cancellation (refund log @log_id, order @order_id, event @event_id).', [
        '@log_id' => (int) ($log['id'] ?? 0),
        '@order_id' => (int) $order->id(),
        '@event_id' => $eventId,
      ]);
      return 0;
    }

    $attendeeStorage = $this->entityTypeManager->getStorage('event_attendee');
    $query = $attendeeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('source', EventAttendee::SOURCE_TICKET)
      ->condition('order_item', $orderItemIds, 'IN')
      ->condition('status', EventAttendee::STATUS_CANCELLED, '<>');
    if (!empty($selectedAttendeeIds)) {
      $query->condition('id', $selectedAttendeeIds, 'IN');
    }
    $attendeeIds = $query->execute();

    if (empty($attendeeIds)) {
      return 0;
    }

    $cancelled = 0;
    $attendees = $attendeeStorage->loadMultiple($attendeeIds);
    foreach ($attendees as $attendee) {
      if (!$attendee instanceof EventAttendee) {
        continue;
      }
      try {
        $attendee->setStatus(EventAttendee::STATUS_CANCELLED);
        $attendee->save();
        $cancelled++;
      }
      catch (\Throwable $e) {
        $this->logger()->error('Failed to cancel attendee @attendee_id for refund log @log_id: @message', [
          '@attendee_id' => (int) $attendee->id(),
          '@log_id' => (int) ($log['id'] ?? 0),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if ($cancelled > 0) {
      $this->logger()->info('Cancelled @count attendee record(s) for refund log @log_id (order @order_id, event @event_id).', [
        '@count' => $cancelled,
        '@log_id' => (int) ($log['id'] ?? 0),
        '@order_id' => (int) $order->id(),
        '@event_id' => $eventId,
      ]);
    }

    return $cancelled;
  }

  /**
   * Parses attendee IDs from the log JSON field.
   *
   * @param string $raw
   *   Raw JSON string.
   *
   * @return int[]
   *   Clean attendee IDs.
   */
  private function decodeAttendeeIds(string $raw): array {
    if ($raw === '') {
      return [];
    }

    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      return [];
    }

    $ids = array_values(array_unique(array_map('intval', $decoded)));
    return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
  }

  /**
   * Checks if an order item maps to the given event.
   */
  private function orderItemIsForEvent($item, int $eventId): bool {
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      if ((int) $item->get('field_target_event')->target_id === $eventId) {
        return TRUE;
      }
    }

    $variation = $item->getPurchasedEntity();
    if ($variation) {
      if ($variation->hasField('field_event') && !$variation->get('field_event')->isEmpty()) {
        if ((int) $variation->get('field_event')->target_id === $eventId) {
          return TRUE;
        }
      }
      if ($variation->hasField('field_event_ref') && !$variation->get('field_event_ref')->isEmpty()) {
        if ((int) $variation->get('field_event_ref')->target_id === $eventId) {
          return TRUE;
        }
      }
      try {
        $product = $variation->getProduct();
        if ($product && $product->hasField('field_event') && !$product->get('field_event')->isEmpty()) {
          return (int) $product->get('field_event')->target_id === $eventId;
        }
      }
      catch (\Throwable $e) {
        // Product linkage may not exist for all variation types.
      }
    }

    return FALSE;
  }

  /**
   * Queues refund failure notifications to buyer, vendor, and admin.
   */
  private function queueRefundFailureEmails(?OrderInterface $order, ?NodeInterface $event, array $log, string $errorMessage): void {
    $ctx = $this->buildFailureEmailContext($order, $event, $log, $errorMessage);

    $customerEmail = $order?->getEmail();
    if (!empty($customerEmail)) {
      $id = $this->messagingManager->queue('refund_failed_buyer', $customerEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }

    $vendorEmail = $this->getUserEmail((int) ($log['vendor_uid'] ?? 0));
    if ($vendorEmail) {
      $id = $this->messagingManager->queue('refund_failed_vendor', $vendorEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }

    $adminEmail = $this->getAdminEmail();
    if ($adminEmail) {
      $id = $this->messagingManager->queue('refund_failed_admin', $adminEmail, $ctx);
      if ($id) {
        $this->messagingManager->sendMessage($id);
      }
    }
    else {
      $this->logger()->warning('Refund failure admin notification skipped: system.site mail is not configured.');
    }
  }

  /**
   * Builds shared context for refund emails.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event.
   * @param int $amountCents
   *   Amount in cents.
   * @param string $currency
   *   Currency code.
   * @param bool $donationRefunded
   *   Whether donation was included.
   *
   * @return array
   *   Context for message templates.
   */
  private function buildRefundEmailContext(
    OrderInterface $order,
    NodeInterface $event,
    int $amountCents,
    string $currency,
    bool $donationRefunded = FALSE
  ): array {
    $amount = number_format($amountCents / 100, 2);
    $currencyUpper = strtoupper($currency);

    return [
      'order_id' => $order->id(),
      'event_id' => (int) $event->id(),
      'event_title' => $event->label(),
      'event_date' => $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
        ? $event->get('field_event_start')->date->format('F j, Y g:ia')
        : '',
      'event_location' => $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()
        ? $event->get('field_venue_name')->value
        : '',
      'order_number' => $order->getOrderNumber() ?: '#' . $order->id(),
      'refunded_amount' => $currencyUpper . ' ' . $amount,
      'donation_refunded' => $donationRefunded,
      'my_tickets_url' => $this->buildPublicMyTicketsUrl($order->id()),
    ];
  }

  /**
   * Builds the My Tickets order URL on the public domain for customer emails.
   *
   * @param int|string $orderId
   *   Commerce order ID (entity IDs may be string or int).
   */
  private function buildPublicMyTicketsUrl(int|string $orderId): string {
    $orderId = (int) $orderId;
    try {
      return $this->domainDetector->buildDomainUrl('/my-tickets/order/' . $orderId, 'public');
    }
    catch (\Exception $e) {
      return Url::fromRoute('myeventlane_checkout_flow.order_detail', [
        'commerce_order' => $orderId,
      ], ['absolute' => TRUE])->toString();
    }
  }

  /**
   * Gets email for a user ID.
   */
  private function getUserEmail(int $uid): ?string {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    return $user && $user->getEmail() ? $user->getEmail() : NULL;
  }

  /**
   * Gets platform admin email address for operational notifications.
   */
  private function getAdminEmail(): ?string {
    $email = (string) ($this->configFactory->get('system.site')->get('mail') ?? '');
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : NULL;
  }

  /**
   * Builds a context payload for refund failure notifications.
   */
  private function buildFailureEmailContext(?OrderInterface $order, ?NodeInterface $event, array $log, string $errorMessage): array {
    $amountCents = (int) ($log['amount_cents'] ?? 0);
    $currency = (string) ($log['currency'] ?? 'aud');
    $amount = number_format($amountCents / 100, 2);
    $currencyUpper = strtoupper($currency);

    return [
      'order_id' => $order?->id() ?? (int) ($log['order_id'] ?? 0),
      'event_id' => $event?->id() ?? (int) ($log['event_id'] ?? 0),
      'event_title' => $event?->label() ?? 'Unknown event',
      'order_number' => $order?->getOrderNumber() ?: '#' . ((int) ($log['order_id'] ?? 0)),
      'refunded_amount' => $currencyUpper . ' ' . $amount,
      'error_message' => $errorMessage,
      'stripe_refund_id' => (string) ($log['stripe_refund_id'] ?? ''),
      'my_tickets_url' => $order ? $this->buildPublicMyTicketsUrl($order->id()) : '',
    ];
  }

  /**
   * Confirms Stripe refund by resolving a Stripe refund ID for this payment slice.
   */
  private function confirmStripeRefundId(PaymentInterface $payment, int $refundCents, string $currency, int $windowStart): ?string {
    $remoteId = (string) ($payment->getRemoteId() ?? '');
    if ($remoteId === '') {
      $this->logger()->error('Stripe refund confirmation skipped: payment @payment_id has no remote ID.', [
        '@payment_id' => $payment->id(),
      ]);
      return NULL;
    }

    try {
      $client = $this->stripeService->getPlatformClient();
      $params = ['limit' => 10];
      if (str_starts_with($remoteId, 'pi_')) {
        $params['payment_intent'] = $remoteId;
      }
      else {
        $params['charge'] = $remoteId;
      }

      $refunds = $client->refunds->all($params);
      $threshold = max(0, $windowStart - 300);

      foreach ($refunds->data as $refund) {
        $id = (string) ($refund->id ?? '');
        $amount = (int) ($refund->amount ?? 0);
        $created = (int) ($refund->created ?? 0);
        $status = (string) ($refund->status ?? '');
        $refundCurrency = strtolower((string) ($refund->currency ?? ''));

        if ($id === '' || $amount !== $refundCents || $refundCurrency !== strtolower($currency)) {
          continue;
        }
        if ($created > 0 && $created < $threshold) {
          continue;
        }
        if ($status !== 'succeeded') {
          continue;
        }

        return $id;
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Stripe refund confirmation lookup failed for payment @payment_id: @message', [
        '@payment_id' => $payment->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Converts integer cents to a decimal string for Commerce Price.
   */
  private function centsToDecimalString(int $cents): string {
    $major = intdiv($cents, 100);
    $minor = $cents % 100;
    return sprintf('%d.%02d', $major, $minor);
  }

  /**
   * Converts a Price object to integer cents without float math.
   */
  private function priceToCents(Price $price): int {
    $number = $price->getNumber();
    $negative = str_starts_with($number, '-');
    $normalized = ltrim($number, '+-');
    [$major, $minor] = array_pad(explode('.', $normalized, 2), 2, '0');
    $minor = substr(str_pad($minor, 2, '0'), 0, 2);
    $cents = ((int) $major * 100) + (int) $minor;
    return $negative ? -$cents : $cents;
  }

}
