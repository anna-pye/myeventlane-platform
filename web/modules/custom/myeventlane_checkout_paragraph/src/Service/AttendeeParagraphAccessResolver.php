<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Resolves access relationships for attendee_answer paragraphs.
 *
 * Resolves the chain: paragraph → order item → order → event → vendor.
 */
final class AttendeeParagraphAccessResolver {

  /**
   * Constructs AttendeeParagraphAccessResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets the parent order item for an attendee paragraph.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The attendee_answer paragraph.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface|null
   *   The parent order item, or NULL if not found.
   */
  public function getParentOrderItem(ParagraphInterface $paragraph): ?OrderItemInterface {
    if ($paragraph->bundle() !== 'attendee_answer') {
      return NULL;
    }

    // Query order items that reference this paragraph.
    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $query = $order_item_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_ticket_holder', $paragraph->id())
      ->range(0, 1)
      ->execute();

    if (empty($query)) {
      return NULL;
    }

    $order_item_id = reset($query);
    $order_item = $order_item_storage->load($order_item_id);
    return $order_item instanceof OrderItemInterface ? $order_item : NULL;
  }

  /**
   * Gets the parent order for an attendee paragraph.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The attendee_answer paragraph.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The parent order, or NULL if not found.
   */
  public function getParentOrder(ParagraphInterface $paragraph): ?OrderInterface {
    $order_item = $this->getParentOrderItem($paragraph);
    if (!$order_item) {
      return NULL;
    }

    try {
      $order = $order_item->getOrder();
      return $order instanceof OrderInterface ? $order : NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the event node for an attendee paragraph.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The attendee_answer paragraph.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The event node, or NULL if not found.
   */
  public function getEvent(ParagraphInterface $paragraph): ?NodeInterface {
    $order_item = $this->getParentOrderItem($paragraph);
    if (!$order_item) {
      return NULL;
    }

    // Try field_target_event on order item.
    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $event = $order_item->get('field_target_event')->entity;
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        return $event;
      }
    }

    // Fallback: try to get event from purchased entity (product variation).
    try {
      $purchased_entity = $order_item->getPurchasedEntity();
      if ($purchased_entity && $purchased_entity->hasField('field_event') && !$purchased_entity->get('field_event')->isEmpty()) {
        $event = $purchased_entity->get('field_event')->entity;
        if ($event instanceof NodeInterface && $event->bundle() === 'event') {
          return $event;
        }
      }
    }
    catch (\Exception $e) {
      // Ignore errors.
    }

    return NULL;
  }

  /**
   * Checks if an order is in a locked state (placed or later).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return bool
   *   TRUE if the order is locked, FALSE otherwise.
   */
  public function isOrderLocked(OrderInterface $order): bool {
    try {
      $state = $order->getState();
      $state_id = $state->getId();

      // Orders in "placed" state or later are locked.
      // Common Commerce order states: draft, validation, placed, completed, fulfilled, canceled.
      $locked_states = ['placed', 'completed', 'fulfilled'];
      return in_array($state_id, $locked_states, TRUE);
    }
    catch (\Exception $e) {
      // If we can't determine state, assume unlocked (safer for creation).
      return FALSE;
    }
  }

  /**
   * Checks if a paragraph belongs to an order owned by a user.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The attendee_answer paragraph.
   * @param int $uid
   *   The user ID.
   *
   * @return bool
   *   TRUE if the paragraph belongs to an order owned by the user.
   */
  public function isOwnedByUser(ParagraphInterface $paragraph, int $uid): bool {
    $order = $this->getParentOrder($paragraph);
    if (!$order) {
      return FALSE;
    }

    // Check order customer ID.
    $customer_id = (int) ($order->getCustomerId() ?? 0);
    return $customer_id === $uid && $uid > 0;
  }

}

