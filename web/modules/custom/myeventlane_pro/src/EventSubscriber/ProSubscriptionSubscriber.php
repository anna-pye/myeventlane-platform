<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\EventSubscriber;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\commerce_recurring\Event\RecurringEvents;
use Drupal\commerce_recurring\Event\PaymentDeclinedEvent;
use Drupal\commerce_recurring\Event\SubscriptionEvent;
use Drupal\commerce_recurring\RecurringOrderManagerInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_pro\Service\ProEntitlementReconciler;
use Drupal\myeventlane_pro\Service\ProBoostProvisioner;
use Drupal\myeventlane_pro\Service\ProSubscriptionLifecycleScheduler;
use Drupal\myeventlane_pro\Service\ProSubscriptionStateResolver;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to subscription lifecycle events and delegates to the reconciler.
 *
 * Hybrid logic: subscription-managed role grants coexist with manual admin
 * assignment. All entitlement decisions are made by ProEntitlementReconciler,
 * which checks for remaining active Pro subscriptions before revoking.
 */
final class ProSubscriptionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ProEntitlementReconciler $reconciler,
    private readonly ProSubscriptionStateResolver $stateResolver,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
    private readonly ProBoostProvisioner $proBoostProvisioner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProSubscriptionLifecycleScheduler $lifecycleScheduler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RecurringOrderManagerInterface $recurringOrderManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      RecurringEvents::SUBSCRIPTION_INSERT => 'onSubscriptionInsert',
      RecurringEvents::SUBSCRIPTION_UPDATE => 'onSubscriptionUpdate',
      RecurringEvents::PAYMENT_DECLINED => 'onPaymentDeclined',
      // Run after other order-paid subscribers so a stale user entity saved by
      // confirmation/invoice listeners cannot restore a cleared grace marker.
      OrderEvents::ORDER_PAID => ['onOrderPaid', -100],
    ];
  }

  /**
   * Starts the grace and dunning sequence on the first failed renewal attempt.
   */
  public function onPaymentDeclined(PaymentDeclinedEvent $event): void {
    $handled = FALSE;
    foreach ($this->recurringOrderManager->collectSubscriptions($event->getOrder()) as $subscription) {
      if (!$subscription instanceof SubscriptionInterface || $subscription->getBillingSchedule()->id() !== 'mel_pro_monthly') {
        continue;
      }
      $user = $subscription->getCustomer();
      if (!$user instanceof UserInterface || $user->isAnonymous()) {
        continue;
      }
      if ((int) $event->getNumRetries() === 0) {
        if ($this->lifecycleScheduler->onPaymentFailedImmediate($subscription)) {
          $this->reconciler->setGracePeriod($user, $this->time->getRequestTime() + $this->resolveGraceSeconds());
        }
      }
      $this->reconciler->reconcileUser($user);
      $handled = TRUE;
    }
    if ($handled) {
      // MEL Pro sends its own organiser-specific, idempotent dunning sequence.
      $event->stopPropagation();
    }
  }

  /**
   * Clears Pro grace after a previously declined recurring order is paid.
   */
  public function onOrderPaid(OrderEvent $event): void {
    foreach ($this->recurringOrderManager->collectSubscriptions($event->getOrder()) as $subscription) {
      if (!$subscription instanceof SubscriptionInterface || $subscription->getBillingSchedule()->id() !== 'mel_pro_monthly') {
        continue;
      }
      $user = $subscription->getCustomer();
      if (!$user instanceof UserInterface || $user->isAnonymous()) {
        continue;
      }
      $this->reconciler->clearGracePeriod($user);
      $this->lifecycleScheduler->onPaymentRecovered($subscription);
      $this->reconciler->reconcileUser($user);
    }
  }

  /**
   * Handles new subscription creation.
   *
   * Covers the non-trial path where OrderSubscriber::onPlace() calls
   * setState('active') directly, bypassing workflow transition events.
   */
  public function onSubscriptionInsert(SubscriptionEvent $event): void {
    $subscription = $event->getSubscription();
    $this->reconcileForSubscription($subscription);
  }

  /**
   * Handles subscription state changes on existing subscriptions.
   *
   * Covers both workflow transitions (applyTransitionById) and direct
   * state mutations (setState) for activation, cancellation, and expiration.
   */
  public function onSubscriptionUpdate(SubscriptionEvent $event): void {
    $subscription = $event->getSubscription();
    $currentState = $subscription->getState()->getId();
    $originalState = $subscription->original?->getState()->getId();

    if ($originalState === $currentState) {
      return;
    }

    $this->reconcileForSubscription($subscription);
  }

  /**
   * Delegates entitlement check to the reconciler for the subscription's owner.
   */
  private function reconcileForSubscription(SubscriptionInterface $subscription): void {
    $user = $subscription->getCustomer();
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      $this->logger->warning('Cannot reconcile: no valid user on subscription @id.', [
        '@id' => $subscription->id(),
      ]);
      return;
    }

    if ($this->stateResolver->isPaymentFailure($subscription)) {
      $graceSeconds = $this->resolveGraceSeconds();
      $graceExpiry = $this->time->getRequestTime() + $graceSeconds;

      // Day-0 dunning only when *entering* failure, not on every save while still failed.
      $original = $subscription->original ?? NULL;
      $wasAlreadyPaymentFailure = $original instanceof SubscriptionInterface
        && $this->stateResolver->isPaymentFailure($original);
      if (!$wasAlreadyPaymentFailure) {
        $this->lifecycleScheduler->onPaymentFailedImmediate($subscription);
      }
      // Start dunning before setting grace. The scheduler uses an existing
      // future grace marker to reject replayed failure events.
      $this->reconciler->setGracePeriod($user, $graceExpiry);
    }

    if ($this->stateResolver->isActive($subscription)) {
      $this->reconciler->clearGracePeriod($user);
    }

    $this->reconciler->reconcileUser($user);
    $this->enqueueUserStoreBoostSync($user);
  }

  /**
   * Grace period length in seconds from configuration.
   */
  private function resolveGraceSeconds(): int {
    $days = (int) ($this->configFactory->get('myeventlane_pro.settings')->get('grace_days') ?? 7);
    return max(1, $days) * 86400;
  }

  /**
   * Enqueues Pro boost sync jobs for all stores owned by the user.
   */
  private function enqueueUserStoreBoostSync(UserInterface $user): void {
    $vendorIds = $this->entityTypeManager
      ->getStorage('myeventlane_vendor')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $user->id())
      ->execute();

    if ($vendorIds === []) {
      return;
    }

    $vendors = $this->entityTypeManager->getStorage('myeventlane_vendor')->loadMultiple($vendorIds);
    foreach ($vendors as $vendor) {
      if (!$vendor instanceof Vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
        continue;
      }
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store instanceof \Drupal\commerce_store\Entity\StoreInterface && $store->id() !== NULL) {
        $this->proBoostProvisioner->enqueueStoreSync((int) $store->id());
      }
    }
  }

}
