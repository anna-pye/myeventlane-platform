<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
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
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RefundOrderInspector $orderInspector,
    private readonly RefundAccessResolver $accessResolver,
    private readonly BuyerRefundEligibilityService $buyerEligibility,
    private readonly RefundRequestStorage $refundRequestStorage,
    private readonly MessagingManager $messagingManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly QueueFactory $queueFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly Connection $database,
    private readonly TimeInterface $time,
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
  public function requestBuyerRefund(OrderInterface $order, NodeInterface $event, AccountInterface $buyer): int {
    if (!$this->buyerEligibility->isEligible($order, $event, $buyer)) {
      $reason = $this->buyerEligibility->getIneligibilityReason($order, $event, $buyer);
      throw new \Exception($reason ?? 'Refund not eligible.');
    }

    $vendorUid = (int) $event->getOwnerId();
    $eventId = (int) $event->id();
    $amountCents = $this->orderInspector->calculateTicketSubtotalCents($order, $eventId);
    $totalPrice = $order->getTotalPrice();
    $currency = $totalPrice ? strtoupper($totalPrice->getCurrencyCode()) : 'AUD';

    $requestId = $this->refundRequestStorage->create([
      'order_id' => $order->id(),
      'event_id' => $eventId,
      'buyer_uid' => $buyer->id(),
      'vendor_uid' => $vendorUid,
      'amount_cents' => $amountCents,
      'currency' => strtolower($currency),
      'status' => RefundRequestStorage::STATUS_REQUESTED,
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

    $payload = [
      'refund_type' => 'full',
      'refund_scope' => 'tickets_only',
      'include_donation' => FALSE,
      'reason' => 'Buyer self-service refund (vendor approved)',
      'refund_request_id' => $requestId,
    ];

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

    // Get payments for this order.
    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $paymentIds = $paymentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $order->id())
      ->condition('state', ['completed', 'partially_refunded'], 'IN')
      ->execute();

    if (empty($paymentIds)) {
      $this->markRefundFailed($log_id, 'No completed payments found for order.');
      return;
    }

    $payments = $paymentStorage->loadMultiple($paymentIds);
    $refundAmount = new Price((string) ($log['amount_cents'] / 100), strtoupper($log['currency']));

    // Find a payment that can be refunded.
    $refunded = FALSE;
    $stripeRefundId = NULL;

    foreach ($payments as $payment) {
      if (!$payment instanceof PaymentInterface) {
        continue;
      }

      $paymentState = $payment->getState()->getId();
      if (!in_array($paymentState, ['completed', 'partially_refunded'], TRUE)) {
        continue;
      }

      // Check if this payment has enough refundable amount.
      $paymentAmount = $payment->getAmount();
      $refundedAmount = $payment->getRefundedAmount();
      $availableAmount = $paymentAmount->subtract($refundedAmount);

      if ($availableAmount->lessThan($refundAmount)) {
        continue;
      }

      // Execute refund via Commerce payment gateway.
      try {
        $gateway = $payment->getPaymentGateway();
        if (!$gateway) {
          continue;
        }

        $plugin = $gateway->getPlugin();
        if (!method_exists($plugin, 'refundPayment')) {
          continue;
        }

        // Execute refund.
        $plugin->refundPayment($payment, $refundAmount);

        // Get Stripe refund ID from payment if available.
        // Note: This may need adjustment based on actual Commerce Stripe implementation.
        if ($payment->hasField('remote_id') && !$payment->get('remote_id')->isEmpty()) {
          // The refund ID might be in payment metadata or we may need to query Stripe.
          // For now, we'll leave it NULL and update later if needed.
        }

        $refunded = TRUE;
        break;
      }
      catch (\Exception $e) {
        $this->logger()->error('Refund failed for payment @pid: @message', [
          '@pid' => $payment->id(),
          '@message' => $e->getMessage(),
        ]);
        // Try next payment.
        continue;
      }
    }

    if (!$refunded) {
      $this->markRefundFailed($log_id, 'Failed to process refund: no eligible payment found or gateway error.');
      return;
    }

    // Mark refund as completed.
    $this->database->update('myeventlane_refund_log')
      ->fields([
        'status' => 'completed',
        'completed' => $this->time->getRequestTime(),
        'stripe_refund_id' => $stripeRefundId,
      ])
      ->condition('id', $log_id)
      ->execute();

    $this->logger()->info('Refund completed: log_id=@log_id, order_id=@order_id', [
      '@log_id' => $log_id,
      '@order_id' => $order->id(),
    ]);

    // Queue email to customer.
    $customerEmail = $order->getEmail();
    if ($customerEmail) {
      $this->queueRefundEmail($order, $event, $log, $customerEmail);
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
  private function markRefundFailed(int $log_id, string $error_message): void {
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
  }

  /**
   * Queues refund completion emails (buyer and optionally vendor).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\node\NodeInterface $event
   *   The event.
   * @param array $log
   *   The refund log data.
   * @param string $customerEmail
   *   The customer email.
   */
  private function queueRefundEmail(OrderInterface $order, NodeInterface $event, array $log, string $customerEmail): void {
    $ctx = $this->buildRefundEmailContext(
      $order,
      $event,
      (int) $log['amount_cents'],
      $log['currency'],
      (bool) ($log['donation_refunded'] ?? FALSE)
    );

    $id = $this->messagingManager->queue('refund_completed_buyer', $customerEmail, $ctx);
    if ($id) {
      $this->messagingManager->sendMessage($id);
    }

    $refundRequestId = $log['refund_request_id'] ?? NULL;
    if ($refundRequestId) {
      $req = $this->refundRequestStorage->load((int) $refundRequestId);
      if ($req) {
        $this->refundRequestStorage->update((int) $refundRequestId, ['status' => RefundRequestStorage::STATUS_COMPLETED]);
        $vendorEmail = $this->getUserEmail((int) $req['vendor_uid']);
        if ($vendorEmail) {
          $id = $this->messagingManager->queue('refund_completed_vendor', $vendorEmail, $ctx);
          if ($id) {
            $this->messagingManager->sendMessage($id);
          }
        }
      }
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

}
