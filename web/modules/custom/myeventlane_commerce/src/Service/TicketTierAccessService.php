<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;

/**
 * Visibility and purchase access for mel_ticket_type in public vs organiser contexts.
 */
final class TicketTierAccessService {

  public const REASON_PUBLIC = 'public';

  public const REASON_HIDDEN = 'hidden';

  public const REASON_ACCESS_CODE_REQUIRED = 'access_code_required';

  public const REASON_GROUP_ONLY = 'group_only';

  public const REASON_GRANTED = 'session_grant';

  /**
   * Whether the tier appears on the standard public booking matrix.
   */
  public function isVisible(
    TicketTypeInterface $ticket,
    ?AccountInterface $account,
    TicketAccessContext $context,
  ): bool {
    $reason = $this->visibilityReason($ticket, $account, $context);
    return in_array($reason, [self::REASON_PUBLIC, self::REASON_GRANTED], TRUE);
  }

  /**
   * Machine-readable visibility outcome.
   *
   * @return self::REASON_*
   */
  public function visibilityReason(
    TicketTypeInterface $ticket,
    ?AccountInterface $account,
    TicketAccessContext $context,
  ): string {
    $source = $context->routeSource;
    if ($source === 'organiser_builder') {
      return self::REASON_PUBLIC;
    }

    $mode = $this->getVisibilityMode($ticket);
    $granted = $this->normaliseGrantedIds($context->grantedTierIds);

    if ($mode === 'public') {
      return self::REASON_PUBLIC;
    }

    if ($mode === 'hidden') {
      return self::REASON_HIDDEN;
    }

    if ($mode === 'access_code') {
      if (in_array((int) $ticket->id(), $granted, TRUE)) {
        return self::REASON_GRANTED;
      }
      return self::REASON_ACCESS_CODE_REQUIRED;
    }

    if ($mode === 'group_only') {
      if (in_array((int) $ticket->id(), $granted, TRUE)) {
        return self::REASON_GRANTED;
      }
      if ($context->groupEligible) {
        return self::REASON_PUBLIC;
      }
      return self::REASON_GROUP_ONLY;
    }

    return self::REASON_HIDDEN;
  }

  public function requiresAccessCode(TicketTypeInterface $ticket): bool {
    return $this->getVisibilityMode($ticket) === 'access_code';
  }

  /**
   * Public purchase path (cart / checkout) — tier must be visible and granted
   * when the mode demands it.
   */
  public function canAccess(
    TicketTypeInterface $ticket,
    ?AccountInterface $account,
    TicketAccessContext $context,
  ): bool {
    if ($context->routeSource === 'organiser_builder') {
      return TRUE;
    }

    $reason = $this->visibilityReason($ticket, $account, $context);
    return $reason === self::REASON_PUBLIC || $reason === self::REASON_GRANTED
      || ($reason === self::REASON_GROUP_ONLY && $context->groupEligible);
  }

  private function getVisibilityMode(TicketTypeInterface $ticket): string {
    if (!$ticket->hasField('visibility_mode') || $ticket->get('visibility_mode')->isEmpty()) {
      return 'public';
    }
    $mode = (string) $ticket->get('visibility_mode')->value;
    return in_array($mode, ['public', 'hidden', 'access_code', 'group_only'], TRUE) ? $mode : 'public';
  }

  /**
   * @param mixed $ids
   *
   * @return list<int>
   */
  private function normaliseGrantedIds(mixed $ids): array {
    if (!is_array($ids)) {
      return [];
    }
    $out = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      if ($id > 0) {
        $out[] = $id;
      }
    }
    return array_values(array_unique($out));
  }

}
