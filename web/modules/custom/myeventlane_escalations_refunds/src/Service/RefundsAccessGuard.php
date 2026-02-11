<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_refunds\Service;

use Drupal\Core\Session\AccountProxyInterface;

/**
 * Centralised access checks for refund context viewing.
 *
 * Keeps permission checks out of hook code and controllers.
 */
final class RefundsAccessGuard {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Checks if the current user can view escalation refund context.
   */
  public function canViewRefundContext(): bool {
    return $this->currentUser->hasPermission('view escalation refund context');
  }

  /**
   * Checks if the current user can view the vendor refund summary.
   */
  public function canViewVendorSummary(): bool {
    return $this->currentUser->hasPermission('view vendor refund summary');
  }

}
