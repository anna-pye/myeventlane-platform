<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Event access aligned with VendorConsoleBaseController::assertEventOwnership.
 *
 * Use for route access and API checks so vendor team members (field_event_vendor
 * → field_vendor_users) have the same event reach as the node author.
 *
 * This does not assert vendor domain or global vendor console permissions; those
 * belong on routes or calling code. Admin/staff bypasses are left to callers.
 */
final class EventVendorAccessChecker implements EventVendorAccessCheckerInterface {

  /**
   * TRUE when the account is the event author or listed on the linked vendor.
   */
  public function accountHasWorkspaceParityForEvent(NodeInterface $event, AccountInterface $account): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    if ((int) $event->getOwnerId() === (int) $account->id()) {
      return TRUE;
    }

    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_users')) {
        foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
          if (isset($item['target_id']) && (int) $item['target_id'] === (int) $account->id()) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

}
