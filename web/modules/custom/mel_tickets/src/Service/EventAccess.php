<?php

declare(strict_types=1);

namespace Drupal\mel_tickets\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Centralized event access checks for ticket workspace routes.
 */
final class EventAccess {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   *
   */
  public function canManageEventTickets(NodeInterface $event): bool {
    // Admin override.
    if ($this->currentUser->hasPermission('administer all events tickets')) {
      return TRUE;
    }
    if (!$this->currentUser->hasPermission('manage own events tickets')) {
      return FALSE;
    }

    // Check if user is the event owner.
    $is_owner = (int) $event->getOwnerId() === (int) $this->currentUser->id();
    if ($is_owner) {
      return TRUE;
    }

    // Check vendor relationship if field exists (via field_event_vendor -> field_vendor_users).
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_users')) {
        $vendor_users = $vendor->get('field_vendor_users')->getValue();
        foreach ($vendor_users as $item) {
          if (isset($item['target_id']) && (int) $item['target_id'] === (int) $this->currentUser->id()) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

}
