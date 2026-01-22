<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Monitors writes to field_attendee_data and logs warnings for deprecated usage.
 *
 * This subscriber detects when code attempts to write attendee data to the
 * deprecated field_attendee_data field (except for metadata-only writes from
 * donation services) and logs a warning.
 */
final class FieldAttendeeDataWriteSubscriber implements EventSubscriberInterface {

  /**
   * Constructs FieldAttendeeDataWriteSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'entity.commerce_order_item.pre_save' => 'onOrderItemPreSave',
    ];
  }

  /**
   * Reacts to order item pre-save to detect field_attendee_data writes.
   *
   * @param \Symfony\Component\EventDispatcher\GenericEvent $event
   *   The entity pre-save event.
   */
  public function onOrderItemPreSave(GenericEvent $event): void {
    $order_item = $event->getSubject();
    if (!$order_item instanceof OrderItemInterface) {
      return;
    }

    // Skip if field doesn't exist.
    if (!$order_item->hasField('field_attendee_data')) {
      return;
    }

    // Check if field_attendee_data is being written to.
    if ($order_item->get('field_attendee_data')->isEmpty()) {
      return;
    }

    $value = $order_item->get('field_attendee_data')->value;
    if (empty($value)) {
      return;
    }

    // Allow metadata-only writes (from donation services).
    // These contain keys like 'donation_type', 'rsvp_submission_id', etc.
    if (is_string($value)) {
      $decoded = json_decode($value, TRUE);
      if (is_array($decoded)) {
        // Check if this looks like metadata (has donation_type or similar keys).
        $metadata_keys = ['donation_type', 'rsvp_submission_id', 'event_id', 'vendor_uid', 'created_at'];
        $has_metadata_keys = !empty(array_intersect(array_keys($decoded), $metadata_keys));
        // Check if it looks like attendee data (has name, email, ticket_1, etc.).
        $attendee_keys = ['name', 'email', 'first_name', 'last_name', 'ticket_1', 'ticket_2'];
        $has_attendee_keys = !empty(array_intersect(array_keys($decoded), $attendee_keys));

        // If it has attendee keys but not metadata keys, it's deprecated usage.
        if ($has_attendee_keys && !$has_metadata_keys) {
          \Drupal::logger('myeventlane_commerce')->warning(
            'Deprecated usage: field_attendee_data written with attendee data on order item @id. Use paragraph-based storage (field_ticket_holder) instead.',
            ['@id' => $order_item->id() ?? 'new']
          );
        }
        // If it's an array with accessibility_needs only, it's from RSVP form (allow for now).
        elseif (count($decoded) === 1 && isset($decoded['accessibility_needs'])) {
          // Allow - this is from RsvpBookingForm for accessibility needs.
          return;
        }
      }
    }
    elseif (is_array($value)) {
      // Direct array value - check for attendee data patterns.
      $attendee_keys = ['name', 'email', 'first_name', 'last_name', 'ticket_1', 'ticket_2'];
      $has_attendee_keys = !empty(array_intersect(array_keys($value), $attendee_keys));
      $metadata_keys = ['donation_type', 'rsvp_submission_id'];
      $has_metadata_keys = !empty(array_intersect(array_keys($value), $metadata_keys));

      if ($has_attendee_keys && !$has_metadata_keys && count($value) > 1) {
        \Drupal::logger('myeventlane_commerce')->warning(
          'Deprecated usage: field_attendee_data written with attendee data on order item @id. Use paragraph-based storage (field_ticket_holder) instead.',
          ['@id' => $order_item->id() ?? 'new']
        );
      }
    }
  }

}
