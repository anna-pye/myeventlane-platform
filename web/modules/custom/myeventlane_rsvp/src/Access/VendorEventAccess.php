<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Access check for vendor RSVP management routes.
 *
 * Allows access when the user can manage RSVPs for the event:
 * - Event owner (node author)
 * - Vendor user (field_event_vendor -> field_vendor_users)
 * - Admin override (administer rsvps or administer nodes)
 */
final class VendorEventAccess implements AccessInterface {

  /**
   * Checks access for vendor event RSVP routes.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function checkAccess(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $event = $route_match->getParameter('event');
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return AccessResult::forbidden()->addCacheableDependency($event ?? new \stdClass());
    }

    // Admin override.
    if ($account->hasPermission('administer rsvps') || $account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($event);
    }

    // Manage own event RSVPs.
    if ($account->hasPermission('manage own event rsvps')) {
      $is_owner = (int) $event->getOwnerId() === (int) $account->id();
      if ($is_owner) {
        return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
      }

      // Vendor membership via field_event_vendor -> field_vendor_users.
      if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
        $vendor = $event->get('field_event_vendor')->entity;
        if ($vendor && $vendor->hasField('field_vendor_users')) {
          foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
            if (isset($item['target_id']) && (int) $item['target_id'] === (int) $account->id()) {
              return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
            }
          }
        }
      }
    }

    return AccessResult::forbidden()->addCacheableDependency($event);
  }

}
