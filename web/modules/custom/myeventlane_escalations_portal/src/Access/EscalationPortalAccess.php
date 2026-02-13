<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_escalations_portal\Service\EscalationPartyResolver;

/**
 * Custom access checks for escalation portal routes.
 */
final class EscalationPortalAccess {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EscalationPartyResolver $partyResolver,
  ) {}

  /**
   * Customer can view their own escalation.
   */
  public function customerViewAccess(AccountInterface $account, int $escalation): AccessResultInterface {
    $entity = $this->loadEscalation($escalation);
    if (!$entity) {
      return AccessResult::forbidden('Escalation not found.');
    }

    if ($this->partyResolver->isCustomer($account, $entity)) {
      return AccessResult::allowed()->addCacheableDependency($entity)->cachePerUser();
    }

    // Staff can also view via customer portal.
    if ($this->partyResolver->isStaff($account)) {
      return AccessResult::allowed()->cachePerUser();
    }

    return AccessResult::forbidden()->cachePerUser();
  }

  /**
   * Customer can reopen their own resolved/closed escalation.
   */
  public function customerReopenAccess(AccountInterface $account, int $escalation): AccessResultInterface {
    $entity = $this->loadEscalation($escalation);
    if (!$entity) {
      return AccessResult::forbidden('Escalation not found.');
    }

    $status = (string) $entity->get('status')->value;
    if (!in_array($status, ['resolved', 'closed'], TRUE)) {
      return AccessResult::forbidden('Escalation is not resolved or closed.');
    }

    if ($this->partyResolver->isCustomer($account, $entity)) {
      return AccessResult::allowed()->addCacheableDependency($entity)->cachePerUser();
    }

    return AccessResult::forbidden()->cachePerUser();
  }

  /**
   * Vendor can view their escalation.
   */
  public function vendorViewAccess(AccountInterface $account, int $escalation): AccessResultInterface {
    $entity = $this->loadEscalation($escalation);
    if (!$entity) {
      return AccessResult::forbidden('Escalation not found.');
    }

    if ($this->partyResolver->isVendor($account, $entity)) {
      return AccessResult::allowed()->addCacheableDependency($entity)->cachePerUser();
    }

    // Staff can also view via vendor portal.
    if ($this->partyResolver->isStaff($account)) {
      return AccessResult::allowed()->cachePerUser();
    }

    return AccessResult::forbidden()->cachePerUser();
  }

  /**
   * Vendor can resolve their escalation (not already resolved/closed).
   */
  public function vendorResolveAccess(AccountInterface $account, int $escalation): AccessResultInterface {
    $entity = $this->loadEscalation($escalation);
    if (!$entity) {
      return AccessResult::forbidden('Escalation not found.');
    }

    $status = (string) $entity->get('status')->value;
    if (in_array($status, ['resolved', 'closed'], TRUE)) {
      return AccessResult::forbidden('Escalation is already resolved or closed.');
    }

    if ($this->partyResolver->isVendor($account, $entity)) {
      return AccessResult::allowed()->addCacheableDependency($entity)->cachePerUser();
    }

    // Staff can also resolve via vendor portal.
    if ($this->partyResolver->isStaff($account)) {
      return AccessResult::allowed()->cachePerUser();
    }

    return AccessResult::forbidden()->cachePerUser();
  }

  /**
   * Loads an escalation entity by ID.
   */
  private function loadEscalation(int $id): ?object {
    return $this->entityTypeManager->getStorage('escalation')->load($id);
  }

}
