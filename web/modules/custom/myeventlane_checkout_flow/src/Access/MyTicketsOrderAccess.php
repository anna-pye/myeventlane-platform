<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Access;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access for /my-tickets/order/{commerce_order}.
 *
 * Delegates to Commerce entity access, then allows guest-checkout orders
 * (customer uid 0) when the order email matches the current user's email.
 */
final class MyTicketsOrderAccess {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks whether the current user may view this order on My Tickets.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $commerce_order = $route_match->getParameter('commerce_order');
    if (!$commerce_order instanceof OrderInterface) {
      return AccessResult::neutral();
    }

    $handler = $this->entityTypeManager->getAccessControlHandler('commerce_order');
    $commerce = $handler->access($commerce_order, 'view', $account, TRUE);
    if ($commerce->isAllowed()) {
      return AccessResult::allowed()
        ->addCacheableDependency($commerce_order)
        ->addCacheContexts(['user']);
    }

    if ($account->isAuthenticated() && $commerce_order->getCustomerId() === 0) {
      $orderMail = $this->normalizeEmail($commerce_order->getEmail());
      $userMail = $this->normalizeEmail($account->getEmail());
      if ($orderMail !== '' && $orderMail === $userMail) {
        return AccessResult::allowed()
          ->addCacheContexts(['user'])
          ->addCacheableDependency($commerce_order);
      }
    }

    return AccessResult::forbidden()
      ->addCacheableDependency($commerce_order)
      ->addCacheContexts(['user']);
  }

  /**
   * Normalizes an email for comparison.
   */
  private function normalizeEmail(?string $email): string {
    return strtolower(trim((string) $email));
  }

}
