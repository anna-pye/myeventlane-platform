<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\EventSubscriber;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\commerce_recurring\Event\RecurringEvents;
use Drupal\commerce_recurring\Event\SubscriptionEvent;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
use Drupal\myeventlane_pro\Service\ProSubscriptionStateResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Attributes Pro subscription activation to recent upgrade nudges.
 */
final class GrowthSubscriptionSubscriber implements EventSubscriberInterface {

  private const BILLING_SCHEDULE = 'mel_pro_monthly';

  public function __construct(
    private readonly GrowthTrackingService $tracking,
    private readonly ProSubscriptionStateResolver $stateResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    if (!class_exists(RecurringEvents::class)) {
      return [];
    }
    return [
      RecurringEvents::SUBSCRIPTION_UPDATE => 'onSubscriptionUpdate',
      RecurringEvents::SUBSCRIPTION_INSERT => 'onSubscriptionInsert',
    ];
  }

  public function onSubscriptionInsert(SubscriptionEvent $event): void {
    $this->maybeAttributeProUpgrade($event->getSubscription());
  }

  public function onSubscriptionUpdate(SubscriptionEvent $event): void {
    $subscription = $event->getSubscription();
    $original = $subscription->original ?? NULL;
    if (!$original instanceof SubscriptionInterface) {
      return;
    }
    $prev = $original->getState()->getId();
    $next = $subscription->getState()->getId();
    if ($prev === $next) {
      return;
    }
    if ($this->stateResolver->isActive($subscription) && !$this->stateResolver->isActive($original)) {
      $this->maybeAttributeProUpgrade($subscription);
    }
  }

  private function maybeAttributeProUpgrade(SubscriptionInterface $subscription): void {
    $schedule = $subscription->getBillingSchedule();
    if ($schedule === NULL || $schedule->id() !== self::BILLING_SCHEDULE) {
      return;
    }
    if (!$this->stateResolver->isActive($subscription)) {
      return;
    }
    $uid = (int) $subscription->getCustomerId();
    if ($uid <= 0) {
      return;
    }
    $this->tracking->recordConversionForPrefixes(
      $uid,
      NULL,
      'pro_upgrade',
      ['pro_upgrade_'],
      ['subscription_id' => (int) $subscription->id()],
    );
  }

}
