<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Interface for {@see EventVendorAccessChecker}.
 *
 * Extracted so MEL Attendee Operations Layer access policy can be unit-tested
 * without doubling the final implementation. The implementation remains
 * `final`; existing consumers that type-hint the concrete class continue to
 * work because the implementation now also implements this interface.
 */
interface EventVendorAccessCheckerInterface {

  /**
   * TRUE when the account is the event author or manages via the linked organiser.
   *
   * Includes organiser entity owner (vendor uid) and field_vendor_users members.
   */
  public function accountHasWorkspaceParityForEvent(NodeInterface $event, AccountInterface $account): bool;

}
