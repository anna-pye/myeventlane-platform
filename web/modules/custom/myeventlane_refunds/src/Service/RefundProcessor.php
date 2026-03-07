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
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes refund requests and executes refunds.
 */
final class RefundProcessor {

  /**
   * Constructs RefundProcessor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_refunds\Service\RefundOrderInspector $orderInspector
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
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RefundOrderInspector $orderInspector,
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

    return $requestId;
  }

  /**
   * Approves a buyer refund request (vendor action).
   *
   * Creates refund log, queues worker, sends emails. No Stripe call here.
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

    $this->refundRequestStorage->update($requestId, ['status' => RefundRequestStorage::STATUS_APPROVED]);

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
    $this->refundRequestStorage->update($requestId, ['refund_log_id' => $logId]);

    $ctx = $this->buildRefundEmailContext($order, $event, (int) $req['amount_cents'], $req['currency']);
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
  }

  /**
   * Requests a refund (creates audit log and queues job).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The vendor account.
   * @param array $refund_payload
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
   */
  public function requestRefund(OrderInterface $order, NodeInterface $event, AccountInterface $account, array $refund_payload): int {
    // Validate access.
    if (!$this->accessResolver->vendorCanRefundOrderForEvent($order, $event, $account)) {
      throw new \Exception('Access denied: vendor cannot refund this order.');
    }

    // Validate order state.
    $orderState = $order->getState()->getId();
    if (!in_array($orderState, ['completed', 'fulfilled', 'placed'], TRUE)) {
      throw new \Exception('Order is not in a refundable state.');
    }

    // Calculate refund amount.
    $refundType = $refund_payload['refund_type'] ?? 'full';
    $refundScope = $refund_payload['refund_scope'] ?? 'tickets_only';
    $includeDonation = $refund_payload['include_donation'] ?? FALSE;
    $selectedAttendeeIds = array_values(array_unique(array_map('intval', (array) ($refund_payload['attendee_ids'] ?? []))));
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
      // Partial refund: use provided amount.
      $amountCents = (int) ($refund_payload['amount_cents'] ?? 0);
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

    // Check refundable amount.
    $refundableCents = $this->orderInspector->calculateRefundableAmountCents($order);
    if ($amountCents > $refundableCents) {
      throw new \Exception('Refund amount exceeds refundable amount.');
    }

    // Get currency from order.
    $totalPrice = $order->getTotalPrice();
    $currency = $totalPrice ? strtoupper($totalPrice->getCurrencyCode()) : 'AUD';

    $logFields = [
      'order_id' => $order->id(),
      'event_id' => $event->id(),
      'vendor_uid' => $account->id(),
      'refund_type' => $refundType,
      'refund_scope' => $refundScope,
      'amount_cents' => $amountCents,
      'currency' => strtolower($currency),
      'donation_refunded' => $donationRefunded,
      'status' => 'pending',
      'reason' => $refund_payload['reason'] ?? NULL,
      'created' => $this->time->getRequestTime(),
      'attendee_ids_json' => !empty($selectedAttendeeIds) ? json_encode($selectedAttendeeIds, JSON_UNESCAPED_SLASHES) : NULL,
    ];
    if (isset($refund_payload['refund_request_id'])) {
      $logFields['refund_request_id'] = $refund_payload['refund_request_id'];
    }
    $logId = $this->database->insert('myeventlane_refund_log')
      ->fields($logFields)
      ->execute();

    // Cast to int as database may return string.
    $logId = (int) $logId;

    $this->logger()->info('Refund requested: log_id=@log_id, order_id=@order_id, amount=@amount_cents cents', [
      '@log_id' => $logId,
      '@order_id' => $order->id(),
      '@amount_cents' => $amountCents,
    ]);

    // Queue refund job.
    $queue = $this->queueFactory->get('vendor_refund_worker');
    $queue->createItem(['log_id' => $logId]);

    return $logId;
  }

  /**
   * Processes a refund (executes the refund via Commerce).
   *
   * @param int $log_id
   *   The refund log ID.
   *
   * @throws \Exception
   *   If processing fails.
   */
  public function processRefund(int $log_id): void {
    $lockName = 'myeventlane_refunds:process_refund:' . $log_id;
    if (!$this->lock->acquire($lockName, 120.0)) {
      $this->logger()->warning('Skipping refund log @log_id because another worker holds the processing lock.', [
        '@log_id' => $log_id,
      ]);
      return;
    }

    try {
      $log = $this->database->select('myeventlane_refund_log', 'r')
        ->fields('r')
        ->condition('id', $log_id)
        ->execute()
        ->fetchAssoc();

      if (!$log) {
        throw new \Exception("Refund log ID $log_id not found.");
      }

      if ($log['status'] !== 'pending') {
        $this->logger()->warning('Refund log @log_id is not pending (status: @status)', [
          '@log_id' => $log_id,
          '@status' => $log['status'],
        ]);
        return;
      }

      if (!empty($log['stripe_refund_id'])) {
        $this->logger()->warning('Skipping refund log @log_id because stripe_refund_id is already set (@stripe_refund_id).', [
          '@log_id' => $log_id,
          '@stripe_refund_id' => $log['stripe_refund_id'],
        ]);
        return;
      }

      // Load order and event.
      $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
      $order = $orderStorage->load($log['order_id']);
      if (!$order instanceof OrderInterface) {
        $this->markRefundFailed($log_id, 'Order not found.');
        return;
      }

      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $event = $nodeStorage->load($log['event_id']);
      if (!$event instanceof NodeInterface) {
        $this->markRefundFailed($log_id, 'Event not found.');
        return;
      }

      // Re-validate access (in case ownership changed).
      $vendor = $this->entityTypeManager->getStorage('user')->load($log['vendor_uid']);
      if (!$vendor) {
        $this->markRefundFailed($log_id, 'Vendor user not found.');
        return;
      }

      if (!$this->accessResolver->vendorCanRefundOrderForEvent($order, $event, $vendor)) {
        $this->markRefundFailed($log_id, 'Access denied: vendor cannot refund this order.');
        return;
      }

      // Get payments for this order in deterministic order.
      $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
      $paymentIds = $paymentStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('order_id', $order->id())
        ->condition('state', ['completed', 'partially_refunded'], 'IN')
        ->sort('payment_id', 'ASC')
        ->execute();

      if (empty($paymentIds)) {
        $this->markRefundFailed($log_id, 'No completed payments found for order.');
        return;
      }

      $payments = $paymentStorage->loadMultiple($paymentIds);
      $requestedCents = (int) $log['amount_cents'];
      $currency = strtoupper((string) $log['currency']);
      $remainingCents = $requestedCents;
      $refundedCents = 0;
      $stripeRefundIds = [];
      $unconfirmedPayments = [];
      $confirmationWindowStart = $this->time->getRequestTime();

      foreach ($payments as $payment) {
        if (!$payment instanceof PaymentInterface || $remainingCents <= 0) {
          continue;
        }

        $paymentState = $payment->getState()->getId();
        if (!in_array($paymentState, ['completed', 'partially_refunded'], TRUE)) {
          continue;
        }

        $paymentAmount = $payment->getAmount();
        $refundedAmount = $payment->getRefundedAmount();
        if (strtoupper($paymentAmount->getCurrencyCode()) !== $currency || strtoupper($refundedAmount->getCurrencyCode()) !== $currency) {
          $this->logger()->error('Refund currency mismatch for log_id=@log_id payment_id=@payment_id log_currency=@log_currency payment_currency=@payment_currency.', [
            '@log_id' => $log_id,
            '@payment_id' => $payment->id(),
            '@log_currency' => $currency,
            '@payment_currency' => strtoupper($paymentAmount->getCurrencyCode()),
          ]);
          continue;
        }

        $paymentAmountCents = $this->priceToCents($paymentAmount);
        $refundedAmountCents = $this->priceToCents($refundedAmount);
        $availableCents = max(0, $paymentAmountCents - $refundedAmountCents);
        if ($availableCents <= 0) {
          continue;
        }

        $refundCentsForPayment = min($remainingCents, $availableCents);
        $refundAmount = new Price($this->centsToDecimalString($refundCentsForPayment), $currency);

        $gateway = $payment->getPaymentGateway();
        if (!$gateway) {
          $this->logger()->error('Refund gateway missing for log_id=@log_id payment_id=@payment_id requested_cents=@requested_cents.', [
            '@log_id' => $log_id,
            '@payment_id' => $payment->id(),
            '@requested_cents' => $refundCentsForPayment,
          ]);
          continue;
        }

        $plugin = $gateway->getPlugin();
        $pluginId = method_exists($plugin, 'getPluginId') ? (string) $plugin->getPluginId() : get_class($plugin);
        if (!method_exists($plugin, 'refundPayment')) {
          $this->logger()->error('Refund plugin does not support refundPayment for log_id=@log_id payment_id=@payment_id plugin_id=@plugin_id.', [
            '@log_id' => $log_id,
            '@payment_id' => $payment->id(),
            '@plugin_id' => $pluginId,
          ]);
          continue;
        }

        try {
          $plugin->refundPayment($payment, $refundAmount);
          $remainingCents -= $refundCentsForPayment;
          $refundedCents += $refundCentsForPayment;

          $stripeRefundId = $this->confirmStripeRefundId($payment, $refundCentsForPayment, strtolower($currency), $confirmationWindowStart);
          if ($stripeRefundId !== NULL) {
            $stripeRefundIds[] = $stripeRefundId;
          }
          else {
            $unconfirmedPayments[] = [
              'payment_id' => (int) $payment->id(),
              'remote_id' => (string) ($payment->getRemoteId() ?? ''),
              'refund_cents' => $refundCentsForPayment,
            ];
          }

          $this->logger()->info('Refunded payment slice for log_id=@log_id payment_id=@payment_id plugin_id=@plugin_id requested_cents=@requested_cents refunded_cents=@refunded_cents remaining_cents=@remaining_cents.', [
            '@log_id' => $log_id,
            '@payment_id' => $payment->id(),
            '@plugin_id' => $pluginId,
            '@requested_cents' => $refundCentsForPayment,
            '@refunded_cents' => $refundCentsForPayment,
            '@remaining_cents' => max(0, $remainingCents),
          ]);
        }
        catch (\Exception $e) {
          $this->logger()->error('Refund failed for log_id=@log_id payment_id=@payment_id plugin_id=@plugin_id requested_cents=@requested_cents refunded_cents=@refunded_cents: @message', [
            '@log_id' => $log_id,
            '@payment_id' => $payment->id(),
            '@plugin_id' => $pluginId,
            '@requested_cents' => $refundCentsForPayment,
            '@refunded_cents' => 0,
            '@message' => $e->getMessage(),
          ]);
          continue;
        }
      }

      if ($remainingCents > 0) {
        $this->markRefundFailed(
          $log_id,
          sprintf(
            'Failed to process full refund. requested_cents=%d, refunded_cents=%d, remaining_cents=%d',
            $requestedCents,
            $refundedCents,
            $remainingCents
          )
        );
        return;
      }

      if (!empty($unconfirmedPayments)) {
        $this->markRefundFailed(
          $log_id,
          sprintf(
            'Stripe confirmation missing for one or more refund slices. unconfirmed=%s',
            json_encode($unconfirmedPayments, JSON_UNESCAPED_SLASHES)
          ),
          $log,
          $order,
          $event
        );
        return;
      }

      // Mark refund as completed.
      $stripeRefundIds = array_values(array_unique(array_filter($stripeRefundIds)));
      $primaryStripeRefundId = $stripeRefundIds[0] ?? NULL;
      $this->database->update('myeventlane_refund_log')
        ->fields([
          'status' => 'completed',
          'completed' => $this->time->getRequestTime(),
          'stripe_refund_id' => $primaryStripeRefundId,
        ])
        ->condition('id', $log_id)
        ->execute();

      $this->logger()->info('Refund completed: log_id=@log_id, order_id=@order_id, requested_cents=@requested_cents, refunded_cents=@refunded_cents, stripe_refund_ids=@stripe_refund_ids', [
        '@log_id' => $log_id,
        '@order_id' => $order->id(),
        '@requested_cents' => $requestedCents,
        '@refunded_cents' => $refundedCents,
        '@stripe_refund_ids' => implode(',', $stripeRefundIds),
      ]);

      $log['stripe_refund_id'] = $primaryStripeRefundId;
      $ticketsCancelled = $this->cancelRefundedTicketAttendees($order, $event, $log);

      // Queue notifications to buyer, vendor, and admin.
      $customerEmail = $order->getEmail();
      if ($customerEmail) {
        $this->queueRefundCompletionEmails($order, $event, $log, $customerEmail, $ticketsCancelled);
      }
      else {
        $this->queueRefundCompletionEmails($order, $event, $log, '', $ticketsCancelled);
      }
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Marks a refund as failed.
   *
   * @param int $log_id
   *   The refund log ID.
   * @param string $error_message
   *   The error message.
   */
  private function markRefundFailed(
    int $log_id,
    string $error_message,
    ?array $log = NULL,
    ?OrderInterface $order = NULL,
    ?NodeInterface $event = NULL
  ): void {
    $this->database->update('myeventlane_refund_log')
      ->fields([
        'status' => 'failed',
        'error_message' => $error_message,
        'completed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $log_id)
      ->execute();

    $this->logger()->error('Refund failed: log_id=@log_id, error=@error', [
      '@log_id' => $log_id,
      '@error' => $error_message,
    ]);

    $logData = $log;
    if ($logData === NULL) {
      $logData = $this->database->select('myeventlane_refund_log', 'r')
        ->fields('r')
        ->condition('id', $log_id)
        ->execute()
        ->fetchAssoc() ?: NULL;
    }

    if ($logData !== NULL) {
      $this->queueRefundFailureEmails($order, $event, $logData, $error_message);
    }
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
    int $ticketsCancelled = 0
  ): void {
    $ctx = $this->buildRefundEmailContext(
      $order,
      $event,
      (int) $log['amount_cents'],
      $log['currency'],
      (bool) ($log['donation_refunded'] ?? FALSE)
    );
    $ctx['stripe_refund_id'] = (string) ($log['stripe_refund_id'] ?? '');
    $ctx['tickets_cancelled'] = $ticketsCancelled;

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
      'my_tickets_url' => Url::fromRoute('myeventlane_checkout_flow.order_detail', [
        'commerce_order' => $order->id(),
      ], ['absolute' => TRUE])->toString(),
    ];
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
      'my_tickets_url' => $order
        ? Url::fromRoute('myeventlane_checkout_flow.order_detail', ['commerce_order' => $order->id()], ['absolute' => TRUE])->toString()
        : '',
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
