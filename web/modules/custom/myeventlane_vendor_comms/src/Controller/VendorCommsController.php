<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_comms\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_checkout_flow\Service\VendorOwnershipResolver;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor communications access.
 */
final class VendorCommsController extends ControllerBase {

  /**
   * Constructs VendorCommsController.
   *
   * @param \Drupal\myeventlane_checkout_flow\Service\VendorOwnershipResolver|null $vendorOwnershipResolver
   *   Optional resolver for store/event ownership checks.
   * @param \Drupal\myeventlane_vendor\Service\EventVendorAccessChecker $eventVendorAccessChecker
   *   Workspace parity for organiser team members.
   */
  public function __construct(
    private readonly ?VendorOwnershipResolver $vendorOwnershipResolver,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->has('myeventlane_checkout_flow.vendor_ownership_resolver')
        ? $container->get('myeventlane_checkout_flow.vendor_ownership_resolver')
        : NULL,
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  /**
   * Access callback for vendor communications pages.
   *
   * @param \Drupal\node\NodeInterface|null $event
   *   The event node.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function checkAccess(?NodeInterface $event = NULL): AccessResult {
    $account = $this->currentUser();

    if ($account->hasPermission('administer commerce_order')
      || $account->hasPermission('bypass node access')
      || $account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    if ($event) {
      $contexts = ['user.permissions', 'user'];
      if ($this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
        return AccessResult::allowed()
          ->addCacheContexts($contexts)
          ->addCacheableDependency($event);
      }
      if ($this->vendorOwnershipResolver !== NULL) {
        $store = $this->vendorOwnershipResolver->getStoreForUser($account);
        if ($store && $this->vendorOwnershipResolver->vendorOwnsEvent($store, $event)) {
          return AccessResult::allowed()
            ->addCacheContexts($contexts)
            ->addCacheableDependency($event);
        }
      }
      return AccessResult::forbidden('You do not own this event.')->addCacheContexts(['user']);
    }

    return AccessResult::forbidden('Only vendors and administrators can access this page.')->addCacheContexts(['user']);
  }

}
