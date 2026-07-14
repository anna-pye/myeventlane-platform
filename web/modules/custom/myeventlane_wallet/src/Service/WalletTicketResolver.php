<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Resolves issued ticket entities for wallet routes keyed by order item ID.
 *
 * Order items remain a compatibility route surface only; issued
 * myeventlane_ticket rows are the operational authority for wallet artifacts.
 */
final class WalletTicketResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WalletDownloadAccessChecker $downloadAccess,
  ) {}

  /**
   * Loads issued tickets for an order item and picks the operational row.
   *
   * Prefers tickets that are still wallet-eligible (not void, refunded, or
   * fulfilment-cancelled). When multiple eligible rows exist for one order
   * item (quantity > 1), prefers the single row whose holder email matches the
   * active account email when exactly one such row exists; otherwise uses the
   * lowest ticket ID for a deterministic choice. When every row is blocked,
   * returns the lowest-ID blocked row so WalletDownloadAccessChecker can deny
   * with the correct message (avoids the NULL legacy path).
   *
   * @return \Drupal\myeventlane_tickets\Entity\Ticket|null
   *   An issued ticket, or NULL when none exist (legacy compatibility path).
   */
  public function resolvePrimaryTicketForOrderItem(OrderItemInterface $order_item, AccountInterface $account): ?Ticket {
    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_item_id', (int) $order_item->id())
      ->sort('id')
      ->execute();
    if (!$ids) {
      return NULL;
    }

    /** @var \Drupal\myeventlane_tickets\Entity\Ticket[] $tickets */
    $tickets = $storage->loadMultiple($ids);
    $list = array_values($tickets);
    usort($list, static function (Ticket $a, Ticket $b): int {
      return ((int) $a->id()) <=> ((int) $b->id());
    });

    $eligible = [];
    foreach ($list as $ticket) {
      if (!$this->downloadAccess->isWalletBlockedStatus($ticket)) {
        $eligible[] = $ticket;
      }
    }
    // Prefer usable entitlements; keep blocked rows only so access can deny.
    $pool = $eligible !== [] ? $eligible : $list;

    if (count($pool) === 1) {
      return $pool[0];
    }

    $account_email = strtolower(trim((string) $account->getEmail()));
    if ($account_email === '') {
      return $pool[0];
    }

    $matches = [];
    foreach ($pool as $ticket) {
      if ($ticket->get('holder_email')->isEmpty()) {
        continue;
      }
      $holder = strtolower(trim((string) $ticket->get('holder_email')->value));
      if ($holder !== '' && $holder === $account_email) {
        $matches[] = $ticket;
      }
    }

    if (count($matches) === 1) {
      return $matches[0];
    }

    return $pool[0];
  }

}
