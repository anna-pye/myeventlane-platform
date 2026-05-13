<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Server-side access for wallet routes aligned with ticket PDF entitlement rules.
 *
 * When a canonical issued ticket is resolved, access follows the same rules as
 * ticket PDF download for that row. Legacy routes without issued tickets keep
 * order-scoped checks only (compatibility), without issuing or mutating tickets.
 */
final class WalletDownloadAccessChecker {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Asserts the account may request wallet artifacts for this order item route.
   *
   * @param \Drupal\myeventlane_tickets\Entity\Ticket|null $ticket
   *   Issued ticket from inward-only resolution, or NULL for legacy mode.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When PDF/wallet expiry window has elapsed (matches ticket PDF behaviour).
   */
  public function assertAuthorized(OrderItemInterface $order_item, ?Ticket $ticket, AccountInterface $account): void {
    if ($account->hasPermission('administer myeventlane wallet') || $account->hasPermission('administer commerce_order')) {
      return;
    }

    if ($ticket instanceof Ticket) {
      if ((int) $ticket->get('order_item_id')->target_id !== (int) $order_item->id()) {
        throw new AccessDeniedHttpException();
      }
      if (!$this->canAccessTicket($ticket, $account)) {
        throw new AccessDeniedHttpException();
      }
      if ($this->isExpired((int) $ticket->getCreatedTime())) {
        throw new NotFoundHttpException();
      }
      return;
    }

    $order = $order_item->getOrder();
    if (!$order instanceof OrderInterface || !$this->orderBelongsToAccount($order, $account)) {
      throw new AccessDeniedHttpException();
    }

    if ($this->isExpired((int) $order_item->getCreatedTime())) {
      throw new NotFoundHttpException();
    }
  }

  /**
   * Whether the account may access wallet data for this issued ticket.
   */
  public function canAccessTicket(Ticket $ticket, AccountInterface $account): bool {
    if ($account->hasPermission('administer myeventlane tickets')) {
      return TRUE;
    }

    $purchaser_uid = (int) $ticket->get('purchaser_uid')->target_id;
    // Anonymous accounts share uid 0 with guest-checkout purchasers; never match on id alone.
    if ($purchaser_uid > 0 && $purchaser_uid === (int) $account->id()) {
      return TRUE;
    }

    if ($account->isAuthenticated() && $purchaser_uid === 0 && !$ticket->get('order_id')->isEmpty()) {
      $order = $ticket->get('order_id')->entity;
      if ($order instanceof OrderInterface && $this->orderBelongsToAccount($order, $account)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether the commerce order is visible to the account (including guest).
   */
  public function orderBelongsToAccount(OrderInterface $order, AccountInterface $account): bool {
    $customer_id = (int) $order->getCustomerId();
    $account_id = (int) $account->id();
    // Guest orders use customer uid 0; anonymous users are also uid 0 — require a real uid match.
    if ($customer_id > 0 && $customer_id === $account_id) {
      return TRUE;
    }
    if ($account->isAuthenticated() && $customer_id === 0) {
      $order_email = strtolower(trim((string) $order->getEmail()));
      $user_email = strtolower(trim((string) $account->getEmail()));
      if ($order_email !== '' && $user_email !== '' && $order_email === $user_email) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE when configured ticket PDF expiry has elapsed.
   */
  public function isExpired(int $created_timestamp): bool {
    $days = (int) ($this->configFactory->get('myeventlane_tickets.settings')->get('pdf_expiry_days') ?? 0);
    if ($days <= 0 || $created_timestamp <= 0) {
      return FALSE;
    }
    $expires_at = $created_timestamp + ($days * 86400);
    return time() > $expires_at;
  }

}
