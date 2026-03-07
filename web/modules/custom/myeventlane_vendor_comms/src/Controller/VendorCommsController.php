<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_comms\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_checkout_flow\Service\VendorOwnershipResolver;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for vendor communications access.
 */
final class VendorCommsController extends ControllerBase {

  /**
   * Constructs VendorCommsController.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ?VendorOwnershipResolver $vendorOwnershipResolver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->has('myeventlane_checkout_flow.vendor_ownership_resolver')
        ? $container->get('myeventlane_checkout_flow.vendor_ownership_resolver')
        : NULL,
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
    $account = $this->currentUser;

    // Admin users always allowed.
    if ($account->hasPermission('administer commerce_order') || $account->hasPermission('bypass node access')) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    // If event provided, verify vendor owns it.
    if ($event) {
      if ($this->vendorOwnershipResolver !== NULL) {
        $store = $this->vendorOwnershipResolver->getStoreForUser($account);
        if ($store && $this->vendorOwnershipResolver->vendorOwnsEvent($store, $event)) {
          return AccessResult::allowed()->addCacheContexts(['user.permissions', 'user']);
        }
      }
      return AccessResult::forbidden('You do not own this event.')->addCacheContexts(['user']);
    }

    return AccessResult::forbidden('Only vendors and administrators can access this page.')->addCacheContexts(['user']);
  }

}
