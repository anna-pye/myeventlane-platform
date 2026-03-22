<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\EventSubscriber;

use Drupal\myeventlane_pro\Event\ProFeatureAccessDeniedEvent;
use Drupal\myeventlane_pro\Event\ProGracePeriodClearedEvent;
use Drupal\myeventlane_growth\Service\GrowthOrganiserSignals;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records organiser signals from Pro access and billing events.
 */
final class GrowthProSignalSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly GrowthOrganiserSignals $signals,
    private readonly GrowthTrackingService $tracking,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ProFeatureAccessDeniedEvent::class => 'onProDenied',
      ProGracePeriodClearedEvent::class => 'onGraceCleared',
    ];
  }

  public function onProDenied(ProFeatureAccessDeniedEvent $event): void {
    $this->signals->recordProFeatureDenied((int) $event->getUser()->id(), $event->getRouteName());
  }

  public function onGraceCleared(ProGracePeriodClearedEvent $event): void {
    $uid = (int) $event->getUser()->id();
    $this->signals->flagBillingRecoveredCelebration($uid);
    $this->tracking->recordConversionForPrefixes($uid, NULL, 'billing_recovery', ['pro_grace_access_risk'], []);
  }

}
