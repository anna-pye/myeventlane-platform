<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves attendee email addresses from orders and RSVP submissions.
 *
 * Deduplicates and validates emails. Returns count and iterable.
 */
final class AttendeeRecipientResolver {

  /**
   * Constructs AttendeeRecipientResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolves unique email addresses for an event's attendees.
   *
   * Includes emails from:
   * - Completed Commerce orders
   * - RSVP submissions
   *
   * Deduplicates and validates emails.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array of unique, validated email addresses.
   */
  public function resolveEmails(NodeInterface $event): array {
    $emails = [];
    $eventId = (int) $event->id();

    // Get emails from Commerce orders.
    $orderEmails = $this->getOrderEmails($eventId);
    foreach ($orderEmails as $email) {
      if ($this->isValidEmail($email)) {
        $emails[strtolower($email)] = $email;
      }
    }

    // Get emails from RSVP submissions.
    $rsvpEmails = $this->getRsvpEmails($eventId);
    foreach ($rsvpEmails as $email) {
      if ($this->isValidEmail($email)) {
        $emails[strtolower($email)] = $email;
      }
    }

    // Return unique emails as array (preserve original case).
    return array_values($emails);
  }

  /**
   * Gets recipient count for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int
   *   Number of unique recipients.
   */
  public function getCount(NodeInterface $event): int {
    return count($this->resolveEmails($event));
  }

  /**
   * Gets email addresses from Commerce orders for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array of email addresses.
   */
  private function getOrderEmails(int $eventId): array {
    $emails = [];

    // Find order items for this event.
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $orderItemIds = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', $eventId)
      ->execute();

    if (empty($orderItemIds)) {
      return $emails;
    }

    $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
    $processedOrders = [];

    foreach ($orderItems as $orderItem) {
      if (!$orderItem instanceof OrderItemInterface) {
        continue;
      }

      // Get order.
      try {
        $order = $orderItem->getOrder();
        if (!$order instanceof OrderInterface) {
          continue;
        }
      }
      catch (\Exception $e) {
        continue;
      }

      // Skip if order already processed (multiple items per order).
      $orderId = $order->id();
      if (isset($processedOrders[$orderId])) {
        continue;
      }

      // Only include completed/placed/fulfilled orders.
      $orderState = $order->getState()->getId();
      if (!in_array($orderState, ['completed', 'placed', 'fulfilled'], TRUE)) {
        continue;
      }

      // Exclude canceled/refunded orders.
      if (in_array($orderState, ['canceled', 'refunded'], TRUE)) {
        continue;
      }

      // Get order email.
      $orderEmail = $order->getEmail();
      if (!empty($orderEmail)) {
        $emails[] = $orderEmail;
      }

      $processedOrders[$orderId] = TRUE;
    }

    return $emails;
  }

  /**
   * Gets email addresses from RSVP submissions for an event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array of email addresses.
   */
  private function getRsvpEmails(int $eventId): array {
    $emails = [];

    if (!$this->entityTypeManager->hasDefinition('rsvp_submission')) {
      return $emails;
    }

    $storage = $this->entityTypeManager->getStorage('rsvp_submission');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $eventId)
      ->condition('status', 'confirmed');

    $ids = $query->execute();
    if (empty($ids)) {
      return $emails;
    }

    $submissions = $storage->loadMultiple($ids);
    foreach ($submissions as $submission) {
      // Try different field names for email.
      $email = NULL;
      if ($submission->hasField('attendee_email') && !$submission->get('attendee_email')->isEmpty()) {
        $email = $submission->get('attendee_email')->value;
      }
      elseif ($submission->hasField('email') && !$submission->get('email')->isEmpty()) {
        $email = $submission->get('email')->value;
      }
      elseif ($submission->hasField('field_attendee_email') && !$submission->get('field_attendee_email')->isEmpty()) {
        $email = $submission->get('field_attendee_email')->value;
      }

      if (!empty($email)) {
        $emails[] = $email;
      }
    }

    return $emails;
  }

  /**
   * Validates an email address.
   *
   * @param string $email
   *   The email address.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function isValidEmail(string $email): bool {
    return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE;
  }

}
