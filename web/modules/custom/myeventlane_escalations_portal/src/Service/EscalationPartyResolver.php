<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Determines the role of a user in relation to a specific escalation.
 *
 * Uses CurrentVendorResolver to check vendor membership.
 */
final class EscalationPartyResolver {

  public function __construct(
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Resolves the user's party role for the given escalation.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param \Drupal\Core\Entity\EntityInterface $escalation
   *   The escalation entity.
   *
   * @return string
   *   One of: 'customer', 'vendor', 'staff', 'none'.
   */
  public function resolve(AccountInterface $account, EntityInterface $escalation): string {
    $uid = (int) $account->id();

    // Check if this user is the customer who submitted the escalation.
    if ($escalation->hasField('user_id') && !$escalation->get('user_id')->isEmpty()) {
      $customer_uid = (int) $escalation->get('user_id')->target_id;
      if ($uid === $customer_uid) {
        return 'customer';
      }
    }

    // Check if this user belongs to the vendor assigned to the escalation.
    if ($escalation->hasField('vendor_id') && !$escalation->get('vendor_id')->isEmpty()) {
      $escalation_vendor_id = (int) $escalation->get('vendor_id')->target_id;
      $user_vendor = $this->vendorResolver->resolveFromUser($account);
      if ($user_vendor && (int) $user_vendor->id() === $escalation_vendor_id) {
        return 'vendor';
      }
    }

    // Check if user has staff/admin permissions.
    if ($account->hasPermission('administer escalations') || $account->hasPermission('access administration pages')) {
      return 'staff';
    }

    return 'none';
  }

  /**
   * Returns TRUE if the user is the customer for this escalation.
   */
  public function isCustomer(AccountInterface $account, EntityInterface $escalation): bool {
    return $this->resolve($account, $escalation) === 'customer';
  }

  /**
   * Returns TRUE if the user is the vendor for this escalation.
   */
  public function isVendor(AccountInterface $account, EntityInterface $escalation): bool {
    return $this->resolve($account, $escalation) === 'vendor';
  }

  /**
   * Returns TRUE if the user is staff for this escalation.
   */
  public function isStaff(AccountInterface $account): bool {
    return $account->hasPermission('administer escalations') || $account->hasPermission('access administration pages');
  }

}
