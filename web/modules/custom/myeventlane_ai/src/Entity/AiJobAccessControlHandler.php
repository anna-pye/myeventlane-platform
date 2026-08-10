<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access control for AI Job entities.
 *
 * - Owner (uid) can view own job.
 * - Admin/staff with "administer ai jobs" can view all.
 * - Vendors can view jobs when vendor_id matches their vendor.
 * - No anonymous access.
 */
final class AiJobAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * Constructs the AI job access handler.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden('Anonymous users cannot access AI jobs.');
    }

    if ($operation === 'view') {
      // Admin/staff.
      if ($account->hasPermission('administer ai jobs')) {
        return AccessResult::allowed();
      }

      /** @var \Drupal\myeventlane_ai\Entity\AiJob $entity */
      $owner_id = (int) $entity->getOwnerId();
      if ($owner_id === (int) $account->id()) {
        return AccessResult::allowed();
      }

      // Vendor: check if account is owner or member of vendor that owns this job.
      $vendor_id = $entity->get('vendor_id')->value;
      if ($vendor_id !== NULL && $vendor_id !== '') {
        $vid = (int) $vendor_id;
        $user_vendor_id = $this->getVendorForUser((int) $account->id());
        if ($user_vendor_id !== NULL && $user_vendor_id === $vid) {
          return AccessResult::allowed();
        }
      }

      // View own ai jobs permission (for non-vendor owner case).
      if ($account->hasPermission('view own ai jobs')) {
        if ($owner_id === (int) $account->id()) {
          return AccessResult::allowed();
        }
      }

      if ($account->hasPermission('view vendor ai jobs') && $vendor_id !== NULL && $vendor_id !== '') {
        $user_vendor_id = $this->getVendorForUser((int) $account->id());
        if ($user_vendor_id !== NULL && $user_vendor_id === (int) $vendor_id) {
          return AccessResult::allowed();
        }
      }

      return AccessResult::forbidden('You do not have permission to view this AI job.');
    }

    return parent::checkAccess($entity, $operation, $account);
  }

  /**
   * Gets the vendor ID for a user (owner or member).
   *
   * @return int|null
   *   Vendor ID or NULL.
   */
  private function getVendorForUser(int $uid): ?int {
    try {
      if (!$this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
        return NULL;
      }
      $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $owner_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->range(0, 1)
        ->execute();
      if (!empty($owner_ids)) {
        return (int) reset($owner_ids);
      }
      $member_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_vendor_users', $uid)
        ->range(0, 1)
        ->execute();
      if (!empty($member_ids)) {
        return (int) reset($member_ids);
      }
    }
    catch (\Throwable $e) {
      return NULL;
    }
    return NULL;
  }

}
