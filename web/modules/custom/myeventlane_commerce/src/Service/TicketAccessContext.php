<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\Session\AccountInterface;

/**
 * Immutable access context for ticket visibility and purchase checks.
 */
final class TicketAccessContext {

  /**
   * @param list<int> $grantedTierIds
   */
  public function __construct(
    public readonly int $eventId,
    public readonly ?AccountInterface $account,
    public readonly array $grantedTierIds,
    public readonly ?int $waitlistClaimEntryId,
    public readonly bool $groupEligible,
    public readonly string $routeSource,
  ) {}

}
