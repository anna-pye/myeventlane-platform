<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\EventSubscriber;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\commerce_recurring\Event\RecurringEvents;
use Drupal\commerce_recurring\Event\SubscriptionEvent;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Manages Pro Organiser role based on subscription lifecycle.
 *
 * Hybrid logic: subscription-managed role grants coexist with manual admin
 * assignment. The field_pro_subscription_managed flag tracks whether the role
 * was granted by subscription, ensuring manual admin assignments are never
 * revoked on subscription cancellation.
 *
 * Uses commerce entity lifecycle events (not state_machine transitions) because
 * commerce_recurring's OrderSubscriber bypasses the workflow for initial
 * activation via setState() rather than applyTransitionById().
 */
final class ProSubscriptionSubscriber implements EventSubscriberInterface {

  private const PRO_ROLE = 'pro_organiser';
  private const MANAGED_FIELD = 'field_pro_subscription_managed';

  public function __construct(
    private readonly LoggerChannelInterface $logger,
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
    if ($subscription->getState()->getId() === 'active') {
      $this->grantProRole($subscription);
    }
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

    if ($currentState === 'active') {
      $this->grantProRole($subscription);
      return;
    }

    if (in_array($currentState, ['canceled', 'expired'], TRUE)) {
      $this->revokeProRole($subscription);
    }
  }

  /**
   * Grants pro_organiser role and marks it as subscription-managed.
   */
  private function grantProRole(SubscriptionInterface $subscription): void {
    $user = $subscription->getCustomer();
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      $this->logger->warning('Cannot grant Pro role: no valid user on subscription @id.', [
        '@id' => $subscription->id(),
      ]);
      return;
    }

    if (!$user->hasField(self::MANAGED_FIELD)) {
      $this->logger->error('User entity missing @field field. Run database updates.', [
        '@field' => self::MANAGED_FIELD,
      ]);
      return;
    }

    $changed = FALSE;

    if (!$user->hasRole(self::PRO_ROLE)) {
      $user->addRole(self::PRO_ROLE);
      $changed = TRUE;
    }

    if (!(bool) $user->get(self::MANAGED_FIELD)->value) {
      $user->set(self::MANAGED_FIELD, TRUE);
      $changed = TRUE;
    }

    if ($changed) {
      $user->save();
      $this->logger->notice('Granted @role to user @uid via subscription @sid.', [
        '@role' => self::PRO_ROLE,
        '@uid' => $user->id(),
        '@sid' => $subscription->id(),
      ]);
    }
  }

  /**
   * Revokes pro_organiser role only if it was subscription-managed.
   */
  private function revokeProRole(SubscriptionInterface $subscription): void {
    $user = $subscription->getCustomer();
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      return;
    }

    if (!$user->hasField(self::MANAGED_FIELD)) {
      $this->logger->error('User entity missing @field field. Run database updates.', [
        '@field' => self::MANAGED_FIELD,
      ]);
      return;
    }

    if (!(bool) $user->get(self::MANAGED_FIELD)->value) {
      $this->logger->info('Skipping role revocation for user @uid: role not subscription-managed.', [
        '@uid' => $user->id(),
      ]);
      return;
    }

    if ($user->hasRole(self::PRO_ROLE)) {
      $user->removeRole(self::PRO_ROLE);
    }

    $user->set(self::MANAGED_FIELD, FALSE);
    $user->save();

    $this->logger->notice('Revoked @role from user @uid: subscription @sid state changed.', [
      '@role' => self::PRO_ROLE,
      '@uid' => $user->id(),
      '@sid' => $subscription->id(),
    ]);
  }

}
