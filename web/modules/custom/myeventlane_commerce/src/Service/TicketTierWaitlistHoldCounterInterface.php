<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

/**
 * Active waitlist offer holds against tier capacity pools.
 */
interface TicketTierWaitlistHoldCounterInterface {

  /**
   * Sum of reserved quantities from active waitlist offers for a tier.
   */
  public function sumActiveOfferReserved(int $eventId, int $tierId): int;

}
