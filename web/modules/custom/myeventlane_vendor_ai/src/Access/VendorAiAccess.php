<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_ai\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_escalations_portal\Service\EscalationPartyResolver;
use Symfony\Component\Routing\Route;

/**
 * Access check for vendor AI assistant route.
 */
final class VendorAiAccess {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EscalationPartyResolver $partyResolver,
  ) {}

  /**
   * Checks access to the vendor AI assistant page.
   *
   * User must have "use vendor ai assistant" permission and be able to view
   * the escalation (vendor or staff).
   */
  public function vendorAiAccess(AccountInterface $account, int $escalation): AccessResultInterface {
    if (!$account->hasPermission('use vendor ai assistant')) {
      return AccessResult::forbidden()->cachePerUser();
    }

    $entity = $this->entityTypeManager->getStorage('escalation')->load($escalation);
    if (!$entity) {
      return AccessResult::forbidden('Escalation not found.');
    }

    if ($this->partyResolver->isVendor($account, $entity)) {
      return AccessResult::allowed()->addCacheableDependency($entity)->cachePerUser();
    }

    if ($this->partyResolver->isStaff($account)) {
      return AccessResult::allowed()->cachePerUser();
    }

    return AccessResult::forbidden()->cachePerUser();
  }

}
