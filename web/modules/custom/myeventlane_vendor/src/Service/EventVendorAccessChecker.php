<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Canonical per-event organiser ownership (workspace parity).
 *
 * TRUE when the account is the event author, the linked vendor entity owner,
 * or a member of that vendor's field_vendor_users.
 *
 * This does not assert vendor domain or global vendor console permissions;
 * those belong on routes or calling code. Staff bypasses are left to callers.
 *
 * @see \Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController::assertEventOwnership()
 * @see docs/adr/ADR-0008-canonical-event-ownership.md
 */
final class EventVendorAccessChecker implements EventVendorAccessCheckerInterface {

  /**
   * {@inheritdoc}
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
      if ($vendor) {
        if (method_exists($vendor, 'getOwnerId')
          && (int) $vendor->getOwnerId() === (int) $account->id()) {
          return TRUE;
        }
        if (method_exists($vendor, 'hasField') && $vendor->hasField('field_vendor_users')) {
          foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
            if (isset($item['target_id']) && (int) $item['target_id'] === (int) $account->id()) {
              return TRUE;
            }
          }
        }
      }
    }

    return FALSE;
  }

}
