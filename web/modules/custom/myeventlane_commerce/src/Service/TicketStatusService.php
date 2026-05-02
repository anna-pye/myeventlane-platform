<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;

/**
 * Service facade for mel_ticket_type status (UI, API); logic lives in TicketStatusEvaluator.
 */
final class TicketStatusService {

  public const STATUS_ACTIVE = TicketStatusEvaluator::STATUS_ACTIVE;

  public const STATUS_UPCOMING = TicketStatusEvaluator::STATUS_UPCOMING;

  public const STATUS_ENDED = TicketStatusEvaluator::STATUS_ENDED;

  public const STATUS_SOLD_OUT = TicketStatusEvaluator::STATUS_SOLD_OUT;

  public const STATUS_INACTIVE = TicketStatusEvaluator::STATUS_INACTIVE;

  public const STATUS_ARCHIVED = TicketStatusEvaluator::STATUS_ARCHIVED;

  public function __construct(
    private readonly TicketVariationSoldService $variationSold,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Computes lifecycle status for a ticket type (UI, validation, availability).
   *
   * @return TicketStatusEvaluator::STATUS_*
   */
  public function getStatus(TicketTypeInterface $ticket): string {
    return TicketStatusEvaluator::evaluate($ticket, $this->variationSold, $this->time, NULL);
  }

}
