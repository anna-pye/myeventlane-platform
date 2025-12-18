<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\EventSubscriber;

use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sends an order receipt when an order is placed.
 */
final class OrderPlacedSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => 'onPlace',
    ];
  }

  /**
   * Queues the order receipt email.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();

    $mail = $order->getEmail();
    if (!$mail) {
      $customer = $order->getCustomer();
      $mail = $customer ? $customer->getEmail() : NULL;
    }

    if (!$mail) {
      return;
    }

    $customer = $order->getCustomer();
    $first_name = $customer ? $customer->getDisplayName() : 'there';

    \Drupal::service('myeventlane_messaging.manager')->queue('order_receipt', $mail, [
      'first_name' => $first_name,
      'order_number' => $order->label(),
      'order_url' => $order->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      // 'unsubscribe_url' => UnsubscribeController::buildUnsubUrl($customer),
    ], ['langcode' => $order->language()->getId()]);
  }

}
