<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Single implementation for ticket product field_event ownership checks and self-heal.
 */
final class TicketProductEventOwnershipService {

  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * TRUE when field_event is empty (self-healed to this event) or already matches.
   */
  public function ticketProductOwnsEvent(ProductInterface $product, NodeInterface $event): bool {
    if (!$product->hasField('field_event')) {
      return FALSE;
    }
    if ($product->get('field_event')->isEmpty()) {
      $product->set('field_event', ['target_id' => $event->id()]);
      $product->save();
      $this->loggerFactory->get('myeventlane_event')->notice(
        'Set field_event on ticket product @pid to event @eid (was empty).',
        ['@pid' => (string) $product->id(), '@eid' => (string) $event->id()]
      );
      return TRUE;
    }
    return (int) $product->get('field_event')->target_id === (int) $event->id();
  }

}
