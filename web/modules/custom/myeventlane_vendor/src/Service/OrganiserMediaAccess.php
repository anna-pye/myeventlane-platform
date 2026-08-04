<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\media\MediaInterface;

/**
 * Defines which Media Library assets an organiser may select.
 *
 * Media entities currently carry a user owner, not a vendor-organisation
 * reference. Non-staff accounts are therefore restricted to their own media.
 * This is intentionally narrower than inferring access from vendor membership.
 */
final class OrganiserMediaAccess {

  public const ACCESS_ALL_PERMISSION = 'access all myeventlane media assets';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Whether the account may browse and select every Media Library asset.
   */
  public function hasUnrestrictedAccess(?AccountInterface $account = NULL): bool {
    $account ??= $this->currentUser;
    return $account->hasPermission(self::ACCESS_ALL_PERMISSION);
  }

  /**
   * Owner IDs allowed in organiser-facing Media Library listings.
   *
   * @return list<int>
   *   One account ID, or an empty list for anonymous accounts.
   */
  public function allowedOwnerIds(?AccountInterface $account = NULL): array {
    $account ??= $this->currentUser;
    $uid = (int) $account->id();
    return $uid > 0 ? [$uid] : [];
  }

  /**
   * Whether an account may newly select the supplied media entity.
   */
  public function canSelect(MediaInterface $media, ?AccountInterface $account = NULL): bool {
    $account ??= $this->currentUser;
    if ($this->hasUnrestrictedAccess($account)) {
      return TRUE;
    }

    return in_array((int) $media->getOwnerId(), $this->allowedOwnerIds($account), TRUE);
  }

}
