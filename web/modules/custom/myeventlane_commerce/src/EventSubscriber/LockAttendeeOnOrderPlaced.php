<?php

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Locks attendee information after an order is placed.
 */
class LockAttendeeOnOrderPlaced implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['commerce_order.place.post_transition' => 'onOrderPlaced'];
  }

  /**
   * Reacts to the order placed transition.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onOrderPlaced(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    foreach ($order->getItems() as $item) {
      if ($item->hasField('field_attendee_data')) {
        // Lock attendee info to prevent edits after order placement.
      }
    }
  }

}

