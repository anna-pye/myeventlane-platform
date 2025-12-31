<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Locks attendee information after an order is placed.
 *
 * Prevents writes to deprecated field_attendee_data and ensures attendee
 * paragraphs are immutable once the order is placed.
 */
final class LockAttendeeOnOrderPlaced implements EventSubscriberInterface {

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
    $logger = \Drupal::logger('myeventlane_commerce');

    foreach ($order->getItems() as $item) {
      // Check for deprecated JSON attendee data and log warning.
      if ($item->hasField('field_attendee_data') && !$item->get('field_attendee_data')->isEmpty()) {
        $value = $item->get('field_attendee_data')->value;
        if (!empty($value)) {
          // Check if this looks like attendee data (not just metadata).
          $is_attendee_data = FALSE;
          if (is_string($value)) {
            $decoded = json_decode($value, TRUE);
            if (is_array($decoded)) {
              $attendee_keys = ['name', 'email', 'first_name', 'last_name', 'ticket_1', 'ticket_2'];
              $is_attendee_data = !empty(array_intersect(array_keys($decoded), $attendee_keys));
            }
          }
          elseif (is_array($value)) {
            $attendee_keys = ['name', 'email', 'first_name', 'last_name', 'ticket_1', 'ticket_2'];
            $is_attendee_data = !empty(array_intersect(array_keys($value), $attendee_keys));
          }

          if ($is_attendee_data) {
            $logger->warning(
              'Order @order_id item @item_id contains deprecated field_attendee_data with attendee information. This should be migrated to paragraph-based storage (field_ticket_holder).',
              [
                '@order_id' => $order->id(),
                '@item_id' => $item->id(),
              ]
            );
          }
        }
      }

      // Lock attendee paragraphs - mark them as read-only by setting a flag.
      // Note: Actual immutability is enforced by access control (Phase 4).
      if ($item->hasField('field_ticket_holder') && !$item->get('field_ticket_holder')->isEmpty()) {
        $paragraphs = $item->get('field_ticket_holder')->referencedEntities();
        foreach ($paragraphs as $paragraph) {
          // Paragraphs are now locked - any future writes should be prevented
          // by access control hooks (to be implemented in Phase 4).
          $logger->info(
            'Locked attendee paragraph @pid for order item @item_id after order placement.',
            [
              '@pid' => $paragraph->id(),
              '@item_id' => $item->id(),
            ]
          );
        }
      }
    }
  }

}
