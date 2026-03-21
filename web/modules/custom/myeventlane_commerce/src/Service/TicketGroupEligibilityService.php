<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Determines whether the current buyer may see and purchase group-only tiers.
 *
 * Repository rule (minimal, fail-closed): the account must be linked to the
 * event’s organising vendor the same way vendor-console access works — event
 * owner, vendor entity owner, or member of field_vendor_users on the vendor
 * referenced from field_event_vendor. This covers organiser team / partner
 * accounts on the vendor profile; it does not model third-party sponsors who
 * are not vendor users (those should use access-code tiers until a partner
 * domain exists).
 */
final class TicketGroupEligibilityService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function isEligible(AccountInterface $account, NodeInterface $event): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }

    $uid = (int) $account->id();
    if ($uid < 1) {
      return FALSE;
    }

    if ((int) $event->getOwnerId() === $uid) {
      return TRUE;
    }

    if (!$event->hasField('field_event_vendor') || $event->get('field_event_vendor')->isEmpty()) {
      return FALSE;
    }

    $vendor = $event->get('field_event_vendor')->entity;
    if (!$vendor instanceof EntityInterface) {
      return FALSE;
    }

    if ((int) $vendor->getOwnerId() === $uid) {
      return TRUE;
    }

    if ($vendor->hasField('field_vendor_users') && !$vendor->get('field_vendor_users')->isEmpty()) {
      foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
        if (isset($item['target_id']) && (int) $item['target_id'] === $uid) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
