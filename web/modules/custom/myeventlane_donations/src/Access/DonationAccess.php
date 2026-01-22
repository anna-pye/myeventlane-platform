<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_core\Service\DomainDetector;

/**
 * Access control for donation routes.
 */
final class DonationAccess {

  /**
   * Constructs DonationAccess.
   *
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domainDetector
   *   The domain detector service.
   */
  public function __construct(
    private readonly DomainDetector $domainDetector,
  ) {}

  /**
   * Checks access for platform donation routes (vendor domain only).
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function platformAccess(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    // Administrators always have access.
    if ($account->id() === 1 || $account->hasPermission('administer site configuration')) {
      if (!$this->domainDetector->isVendorDomain()) {
        return AccessResult::forbidden('Platform donations are only available on the vendor domain.');
      }
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Must be on vendor domain.
    if (!$this->domainDetector->isVendorDomain()) {
      return AccessResult::forbidden('Platform donations are only available on the vendor domain.');
    }

    // Must be logged in.
    if ($account->isAnonymous()) {
      return AccessResult::forbidden('You must be logged in to make a donation.');
    }

    // Check for vendor console permission.
    if (!$account->hasPermission('access vendor console')) {
      return AccessResult::forbidden('You do not have permission to make donations.');
    }

    return AccessResult::allowed()->cachePerPermissions();
  }

  /**
   * Checks access for vendor donation list routes (vendor domain only).
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function vendorAccess(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    // Administrators always have access.
    if ($account->id() === 1 || $account->hasPermission('administer site configuration')) {
      if (!$this->domainDetector->isVendorDomain()) {
        return AccessResult::forbidden('Vendor donation pages are only available on the vendor domain.');
      }
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Must be on vendor domain.
    if (!$this->domainDetector->isVendorDomain()) {
      return AccessResult::forbidden('Vendor donation pages are only available on the vendor domain.');
    }

    // Must be logged in.
    if ($account->isAnonymous()) {
      return AccessResult::forbidden('You must be logged in to view donations.');
    }

    // Check for vendor console permission.
    if (!$account->hasPermission('access vendor console')) {
      return AccessResult::forbidden('You do not have permission to view donations.');
    }

    return AccessResult::allowed()->cachePerPermissions();
  }

}
