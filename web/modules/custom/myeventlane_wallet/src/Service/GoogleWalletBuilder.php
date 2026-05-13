<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use InvalidArgumentException;

/**
 * Builds Google Wallet pass links.
 *
 * Save URLs remain the frozen placeholder contract; issued tickets are accepted so
 * future JWT work can derive from canonical ticket rows without changing routes.
 */
final class GoogleWalletBuilder {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Generates a Google Wallet save link for an order item route.
   *
   * @param \Drupal\myeventlane_tickets\Entity\Ticket|null $ticket
   *   Issued ticket when inward resolution succeeded; NULL for legacy mode.
   *
   * @return string
   *   The Google Wallet save URL.
   */
  public function generateSaveLink(OrderItemInterface $orderItem, ?Ticket $ticket = NULL): string {
    if ($ticket instanceof Ticket
      && !$ticket->get('order_item_id')->isEmpty()
      && (int) $ticket->get('order_item_id')->target_id !== (int) $orderItem->id()) {
      throw new InvalidArgumentException('Ticket order item mismatch for wallet link generation.');
    }

    // @todo Implement actual Google Wallet JWT generation from canonical ticket context.
    return 'https://pay.google.com/gp/v/save/placeholder';
  }

}
