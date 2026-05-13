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
  ) {}

  /**
   * Loads issued tickets for an order item and picks the operational row.
   *
   * When multiple ticket rows exist for one order item (quantity > 1), prefers
   * the single row whose holder email matches the active account email when
   * exactly one such row exists; otherwise uses the lowest ticket ID for a
   * deterministic choice. This mirrors inward-only compatibility used on PDF
   * surfaces without changing public wallet URLs.
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

    if (count($list) === 1) {
      return $list[0];
    }

    $account_email = strtolower(trim((string) $account->getEmail()));
    if ($account_email === '') {
      return $list[0];
    }

    $matches = [];
    foreach ($list as $ticket) {
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

    return $list[0];
  }

}
