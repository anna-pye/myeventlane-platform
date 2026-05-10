<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Centralizes operational behavior for ticket-backed entitlements.
 */
final class TicketCapabilityManager {

  /**
   * Entitlements that represent a redeemable or collectible unit.
   *
   * @var array<string, bool>
   */
  private const REDEEMABLE_TYPES = [
    Ticket::ENTITLEMENT_MERCH => TRUE,
    Ticket::ENTITLEMENT_DRINK => TRUE,
    Ticket::ENTITLEMENT_FOOD => TRUE,
    Ticket::ENTITLEMENT_ADDON => TRUE,
  ];

  /**
   * Entitlements that require fulfilment workflow state.
   *
   * @var array<string, bool>
   */
  private const FULFILMENT_TYPES = [
    Ticket::ENTITLEMENT_MERCH => TRUE,
    Ticket::ENTITLEMENT_ADDON => TRUE,
  ];

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * TRUE when the ticket is an event admission ticket.
   */
  public function isTicket(Ticket $ticket): bool {
    return $ticket->getEntitlementType() === Ticket::ENTITLEMENT_TICKET;
  }

  /**
   * TRUE when the ticket is a merch pickup entitlement.
   */
  public function isMerchPickup(Ticket $ticket): bool {
    return $ticket->getEntitlementType() === Ticket::ENTITLEMENT_MERCH;
  }

  /**
   * TRUE when the ticket is a parking pass.
   */
  public function isParkingPass(Ticket $ticket): bool {
    return $ticket->getEntitlementType() === Ticket::ENTITLEMENT_PARKING;
  }

  /**
   * TRUE when the entitlement can consume redemption count.
   */
  public function isRedeemable(Ticket $ticket): bool {
    return isset(self::REDEEMABLE_TYPES[$ticket->getEntitlementType()]);
  }

  /**
   * TRUE when fulfilment state matters for the entitlement.
   */
  public function requiresFulfilment(Ticket $ticket): bool {
    return isset(self::FULFILMENT_TYPES[$ticket->getEntitlementType()]);
  }

  /**
   * TRUE when the entitlement allows more than one successful redemption.
   */
  public function supportsMultipleRedemptions(Ticket $ticket): bool {
    return $ticket->getRedemptionLimit() > 1;
  }

  /**
   * TRUE when the entitlement expiry time has passed.
   */
  public function isExpired(Ticket $ticket): bool {
    if (!$ticket->hasField('expires_at') || $ticket->get('expires_at')->isEmpty()) {
      return FALSE;
    }

    $value = (string) $ticket->get('expires_at')->value;
    $timestamp = strtotime($value . ' UTC');
    return $timestamp !== FALSE && $timestamp <= $this->time->getCurrentTime();
  }

  /**
   * TRUE when the entitlement can currently be scanned.
   */
  public function canBeScanned(Ticket $ticket): bool {
    $status = (string) $ticket->get('status')->value;
    if (in_array($status, [Ticket::STATUS_VOID, Ticket::STATUS_REFUNDED], TRUE)) {
      return FALSE;
    }

    if ($this->isExpired($ticket)) {
      return FALSE;
    }

    $fulfilment_status = $ticket->getFulfilmentStatus();
    if (in_array($fulfilment_status, [Ticket::FULFILMENT_CANCELLED, Ticket::FULFILMENT_EXPIRED], TRUE)) {
      return FALSE;
    }

    if ($this->isTicket($ticket)) {
      return $status !== Ticket::STATUS_CHECKED_IN;
    }

    if ($this->isRedeemable($ticket)) {
      return $ticket->getRedemptionCount() < $ticket->getRedemptionLimit();
    }

    return TRUE;
  }

}
