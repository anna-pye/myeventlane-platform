<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\advancedqueue\Exception\DuplicateJobException;
use Drupal\advancedqueue\Job;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Updates Pro payment methods and schedules safe Commerce renewal retries.
 */
final class ProPaymentRecoveryService {

  private const PAYMENT_GATEWAY = 'stripe_pe_recurring';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
    private readonly LockBackendInterface $lock,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Attaches a newly saved recurring method to the canonical subscription.
   */
  public function replacePaymentMethod(AccountInterface $account, PaymentMethodInterface $paymentMethod): void {
    if ((int) $paymentMethod->getOwnerId() !== (int) $account->id()) {
      throw new \InvalidArgumentException('The payment method does not belong to this organiser.');
    }
    $gatewayId = (string) $paymentMethod->getPaymentGatewayId();
    if ($gatewayId !== self::PAYMENT_GATEWAY) {
      throw new \InvalidArgumentException('A MEL Pro payment method is required.');
    }
    if (!$paymentMethod->isReusable()) {
      throw new \InvalidArgumentException('A reusable MEL Pro payment method is required.');
    }

    $subscription = $this->loadLatestSubscription((int) $account->id());
    if (!$subscription instanceof SubscriptionInterface) {
      throw new \RuntimeException('No MEL Pro subscription was found.');
    }

    $subscription->setPaymentMethod($paymentMethod);
    $subscription->save();

    // Keep the unpaid renewal aligned with the subscription. The old method is
    // deliberately retained in Commerce until the new method is proven saved.
    foreach ($subscription->getOrders() as $order) {
      if (!$order instanceof OrderInterface
        || $order->isPaid()
        || in_array($order->getState()->getId(), ['canceled', 'completed'], TRUE)) {
        continue;
      }
      $order->set('payment_method', $paymentMethod);
      $order->set('payment_gateway', self::PAYMENT_GATEWAY);
      $order->save();
    }

    $paymentMethod->setDefault(TRUE);
    $paymentMethod->save();
    $this->logger->notice('MEL Pro payment method updated by organiser @uid for subscription @sid using Commerce payment method @pmid.', [
      '@uid' => (string) $account->id(),
      '@sid' => (string) $subscription->id(),
      '@pmid' => (string) $paymentMethod->id(),
    ]);
  }

  /**
   * Queues the outstanding renewal through Commerce Recurring.
   *
   * @return string
   *   "queued", "duplicate", or "not_due".
   */
  public function queueRetry(AccountInterface $account): string {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    $graceExpiry = ($user instanceof UserInterface && $user->hasField('field_pro_grace_expires'))
      ? (int) ($user->get('field_pro_grace_expires')->value ?? 0)
      : 0;
    if ($graceExpiry < $this->time->getRequestTime()) {
      throw new \RuntimeException('A payment retry is only available during an active Pro grace period.');
    }

    $subscription = $this->loadLatestSubscription((int) $account->id());
    if (!$subscription instanceof SubscriptionInterface) {
      throw new \RuntimeException('No MEL Pro subscription was found.');
    }

    $order = $this->findOutstandingOrder($subscription);
    if (!$order instanceof OrderInterface) {
      return 'not_due';
    }

    $method = $subscription->getPaymentMethod();
    if ($method === NULL || (string) $method->getPaymentGatewayId() !== self::PAYMENT_GATEWAY) {
      throw new \RuntimeException('Update your MEL Pro payment method before retrying.');
    }

    $order->set('payment_method', $method);
    $order->set('payment_gateway', self::PAYMENT_GATEWAY);
    $order->save();

    $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load('commerce_recurring');
    if ($queue === NULL) {
      throw new \RuntimeException('The recurring payment queue is unavailable.');
    }

    $retryKey = 'order:' . (string) $order->id();
    $lockName = 'myeventlane_pro.payment_retry.' . (string) $order->id();
    if (!$this->lock->acquire($lockName, 10.0)) {
      return 'duplicate';
    }
    $retryGuard = $this->keyValueExpirable->get('myeventlane_pro.payment_retry');
    try {
      if ($retryGuard->has($retryKey)) {
        return 'duplicate';
      }
      // Suppress double clicks and concurrent staff/organiser retries while
      // the asynchronous Commerce job is accepted and begins processing.
      $retryGuard->setWithExpire($retryKey, TRUE, 300);
      $queue->enqueueJob(Job::create('myeventlane_pro_payment_retry', [
        'order_id' => (int) $order->id(),
      ]));
    }
    catch (DuplicateJobException) {
      return 'duplicate';
    }
    catch (\Throwable $exception) {
      $retryGuard->delete($retryKey);
      throw $exception;
    }
    finally {
      $this->lock->release($lockName);
    }

    $this->logger->notice('MEL Pro renewal retry requested for organiser @uid, subscription @sid and order @oid.', [
      '@uid' => (string) $account->id(),
      '@sid' => (string) $subscription->id(),
      '@oid' => (string) $order->id(),
    ]);
    return 'queued';
  }

  /**
   * Returns whether the organiser is currently inside payment grace.
   */
  public function isInActiveGracePeriod(AccountInterface $account): bool {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    $graceExpiry = ($user instanceof UserInterface && $user->hasField('field_pro_grace_expires'))
      ? (int) ($user->get('field_pro_grace_expires')->value ?? 0)
      : 0;
    return $graceExpiry >= $this->time->getRequestTime();
  }

  /**
   * Loads the latest managed Pro subscription for an organiser.
   */
  private function loadLatestSubscription(int $uid): ?SubscriptionInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_subscription');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('billing_schedule', ProBillingSchedule::ALL, 'IN')
      ->sort('subscription_id', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $subscription = $storage->load(reset($ids));
    return $subscription instanceof SubscriptionInterface ? $subscription : NULL;
  }

  /**
   * Finds the newest unpaid, non-terminal renewal order.
   */
  private function findOutstandingOrder(SubscriptionInterface $subscription): ?OrderInterface {
    $orders = $subscription->getOrders();
    usort($orders, static fn(OrderInterface $a, OrderInterface $b): int => (int) $b->id() <=> (int) $a->id());
    foreach ($orders as $order) {
      if (!$order->isPaid() && !in_array($order->getState()->getId(), ['canceled', 'completed'], TRUE)) {
        return $order;
      }
    }
    return NULL;
  }

}
