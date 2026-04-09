<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Access;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\node\NodeInterface;

/**
 * Admin permission OR vendor manage-all-events-on-order for resend.
 */
final class ResendOrderConfirmationAccess implements AccessInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventAccess $eventAccess,
  ) {}

  /**
   * Route requirement callback for resend order confirmation.
   */
  public function access(OrderInterface $commerce_order, AccountInterface $account): AccessResultInterface {
    return $this->check($commerce_order, $account);
  }

  /**
   * Access for resending order confirmation for a commerce order.
   */
  public function check(OrderInterface $order, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('resend order confirmation emails')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $eventIds = $this->getOrderEventIds($order);
    if ($eventIds === []) {
      return AccessResult::forbidden()->cachePerPermissions();
    }

    foreach ($eventIds as $nid) {
      $event = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        return AccessResult::forbidden()->addCacheableDependency($order);
      }
      if (!$this->eventAccess->canManageEventTickets($event)) {
        return AccessResult::forbidden()
          ->cachePerUser()
          ->addCacheableDependency($event);
      }
    }

    return AccessResult::allowed()
      ->cachePerUser()
      ->addCacheableDependency($order);
  }

  /**
   * @return int[]
   *   Unique event node IDs referenced by ticket lines on the order.
   */
  private function getOrderEventIds(OrderInterface $order): array {
    $ids = [];
    foreach ($order->getItems() as $item) {
      $bundle = $item->bundle();
      if (in_array($bundle, ['checkout_donation', 'platform_donation', 'rsvp_donation', 'boost'], TRUE)) {
        continue;
      }
      if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
        if ($event instanceof NodeInterface && $event->bundle() === 'event') {
          $ids[] = (int) $event->id();
        }
      }
    }

    return array_values(array_unique($ids));
  }

}