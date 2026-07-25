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

    // Vendor membership wins over submitter identity. Organiser-created
    // requests set both user_id and vendor_id on the same person; they must
    // act as vendor on /vendor/support (view, reply, resolve).
    if ($escalation->hasField('vendor_id') && !$escalation->get('vendor_id')->isEmpty()) {
      $escalation_vendor_id = (int) $escalation->get('vendor_id')->target_id;
      $user_vendor = $this->vendorResolver->resolveFromUser($account);
      if ($user_vendor && (int) $user_vendor->id() === $escalation_vendor_id) {
        return 'vendor';
      }
    }

    if ($escalation->hasField('user_id') && !$escalation->get('user_id')->isEmpty()) {
      $customer_uid = (int) $escalation->get('user_id')->target_id;
      if ($uid === $customer_uid) {
        return 'customer';
      }
    }

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

  /**
   * Whether the escalation submitter is also the assigned organiser.
   *
   * Used so organiser-created requests wait on staff (not a phantom customer).
   */
  public function submitterIsAssignedVendor(EntityInterface $escalation): bool {
    if (!$escalation->hasField('user_id') || $escalation->get('user_id')->isEmpty()) {
      return FALSE;
    }
    if (!$escalation->hasField('vendor_id') || $escalation->get('vendor_id')->isEmpty()) {
      return FALSE;
    }
    $submitter = $escalation->get('user_id')->entity;
    if (!$submitter instanceof AccountInterface) {
      return FALSE;
    }
    return $this->isVendor($submitter, $escalation);
  }

}
