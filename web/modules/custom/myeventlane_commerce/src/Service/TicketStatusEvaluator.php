<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;

/**
 * Pure status evaluation shared by TicketStatusService and TicketAvailabilityService.
 *
 * Keeps one algorithm without service-to-service cycles in the container.
 */
final class TicketStatusEvaluator {

  public const STATUS_ACTIVE = 'active';

  public const STATUS_UPCOMING = 'upcoming';

  public const STATUS_ENDED = 'ended';

  public const STATUS_SOLD_OUT = 'sold_out';

  public const STATUS_INACTIVE = 'inactive';

  public const STATUS_ARCHIVED = 'archived';

  private function __construct() {}

  /**
   * @return self::STATUS_*
   */
  public static function evaluate(
    TicketTypeInterface $ticket,
    TicketVariationSoldService $variationSold,
    TimeInterface $time,
    ?NodeInterface $event = NULL,
  ): string {
    if ($ticket->isArchived()) {
      return self::STATUS_ARCHIVED;
    }

    if ((int) ($ticket->get('status')->value ?? 0) === 0) {
      return self::STATUS_INACTIVE;
    }

    $tzEvent = $event;
    if (!$tzEvent instanceof NodeInterface
      && $ticket->hasField('event')
      && !$ticket->get('event')->isEmpty()) {
      $ref = $ticket->get('event')->entity;
      $tzEvent = $ref instanceof NodeInterface ? $ref : NULL;
    }

    $tzName = EventTimezoneResolver::getTimezoneId($tzEvent);
    $now = new \DateTime('now', new \DateTimeZone($tzName));
    $nowTs = $now->getTimestamp();

    if (!$ticket->get('sale_start')->isEmpty()) {
      /** @var \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem|null $startItem */
      $startItem = $ticket->get('sale_start')->first();
      if ($startItem && $startItem->date && $startItem->date->getTimestamp() > $nowTs) {
        return self::STATUS_UPCOMING;
      }
    }

    if (!$ticket->get('sale_end')->isEmpty()) {
      /** @var \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem|null $endItem */
      $endItem = $ticket->get('sale_end')->first();
      if ($endItem && $endItem->date && $endItem->date->getTimestamp() < $nowTs) {
        return self::STATUS_ENDED;
      }
    }

    if ($ticket->getTicketKind() === 'paid') {
      $variation_id = $ticket->get('commerce_variation')->isEmpty()
        ? 0
        : (int) $ticket->get('commerce_variation')->target_id;
      $event_id = $ticket->get('event')->isEmpty()
        ? 0
        : (int) $ticket->get('event')->target_id;

      $sold = ($variation_id > 0 && $event_id > 0)
        ? $variationSold->countCompletedSoldForVariation($event_id, $variation_id)
        : 0;

      if (!$ticket->get('capacity')->isEmpty()) {
        $capacity = (int) $ticket->get('capacity')->value;
        if ($capacity > 0 && $sold >= $capacity) {
          return self::STATUS_SOLD_OUT;
        }
      }
    }

    return self::STATUS_ACTIVE;
  }

}
