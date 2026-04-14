<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\EventSubscriber;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\myeventlane_auth\Service\CustomerIdentityBridge;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Updates customer-track onboarding when a Commerce order is placed.
 */
final class CustomerOrderOnboardingSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly CustomerIdentityBridge $customerIdentityBridge,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlaced', -120],
    ];
  }

  /**
   * @param object $event
   *   Workflow transition event when commerce_order is present.
   */
  public function onOrderPlaced(object $event): void {
    if (!$this->moduleHandler->moduleExists('commerce_order')) {
      return;
    }
    if (!class_exists(\Drupal\state_machine\Event\WorkflowTransitionEvent::class)) {
      return;
    }
    if (!$event instanceof \Drupal\state_machine\Event\WorkflowTransitionEvent) {
      return;
    }
    $order = $event->getEntity();
    $this->customerIdentityBridge->onCommerceOrderPlaced($order);
  }

}
