<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Psr\Log\LoggerInterface;

/**
 * Manages vendor follow relationships.
 */
final class VendorFollowService {

  /**
   * Constructs a VendorFollowService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Follows a vendor for a user.
   */
  public function follow(AccountInterface $account, Vendor $vendor): void {
    $uid = (int) $account->id();
    $vendor_id = (int) $vendor->id();
    if ($uid <= 0) {
      throw new EntityStorageException('Anonymous users cannot follow vendors.');
    }

    if ($this->isFollowing($account, $vendor)) {
      return;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('myeventlane_vendor_follow');
      $storage->create([
        'user_id' => $uid,
        'vendor_id' => $vendor_id,
      ])->save();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to follow vendor @vendor_id for user @uid: @message', [
        '@vendor_id' => $vendor_id,
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Unfollows a vendor for a user.
   */
  public function unfollow(AccountInterface $account, Vendor $vendor): void {
    $ids = $this->followIds($account, $vendor);
    if (empty($ids)) {
      return;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('myeventlane_vendor_follow');
      $storage->delete($storage->loadMultiple($ids));
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to unfollow vendor @vendor_id for user @uid: @message', [
        '@vendor_id' => $vendor->id(),
        '@uid' => $account->id(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Checks whether a user follows a vendor.
   */
  public function isFollowing(AccountInterface $account, Vendor $vendor): bool {
    if ((int) $account->id() <= 0) {
      return FALSE;
    }

    return !empty($this->followIds($account, $vendor));
  }

  /**
   * Counts followers for a vendor.
   */
  public function countFollowers(Vendor $vendor): int {
    return (int) $this->entityTypeManager
      ->getStorage('myeventlane_vendor_follow')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('vendor_id', (int) $vendor->id())
      ->count()
      ->execute();
  }

  /**
   * Loads follow entity IDs for a user/vendor pair.
   *
   * @return int[]
   *   Follow entity IDs.
   */
  private function followIds(AccountInterface $account, Vendor $vendor): array {
    return array_map('intval', $this->entityTypeManager
      ->getStorage('myeventlane_vendor_follow')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('user_id', (int) $account->id())
      ->condition('vendor_id', (int) $vendor->id())
      ->execute());
  }

}
