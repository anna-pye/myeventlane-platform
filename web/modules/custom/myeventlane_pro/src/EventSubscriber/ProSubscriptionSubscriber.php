<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\EventSubscriber;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\commerce_recurring\Event\RecurringEvents;
use Drupal\commerce_recurring\Event\SubscriptionEvent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_pro\Service\ProEntitlementReconciler;
use Drupal\myeventlane_pro\Service\ProBoostProvisioner;
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

  private const GRACE_SECONDS = 604800;

  public function __construct(
    private readonly ProEntitlementReconciler $reconciler,
    private readonly ProSubscriptionStateResolver $stateResolver,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
    private readonly ProBoostProvisioner $proBoostProvisioner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      RecurringEvents::SUBSCRIPTION_INSERT => 'onSubscriptionInsert',
      RecurringEvents::SUBSCRIPTION_UPDATE => 'onSubscriptionUpdate',
    ];
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
      $graceExpiry = $this->time->getRequestTime() + self::GRACE_SECONDS;
      $this->reconciler->setGracePeriod($user, $graceExpiry);
    }

    if ($this->stateResolver->isActive($subscription)) {
      $this->reconciler->clearGracePeriod($user);
    }

    $this->reconciler->reconcileUser($user);
    $this->enqueueUserStoreBoostSync($user);
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
