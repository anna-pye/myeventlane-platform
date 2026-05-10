<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;

/**
 * Centralizes operational behavior for ticket-backed entitlements.
 */
class TicketCapabilityManager {

  /**
   * Entitlement types supported by the ticket capability foundation.
   *
   * @var array<string, bool>
   */
  private const SUPPORTED_TYPES = [
    Ticket::ENTITLEMENT_TICKET => TRUE,
    Ticket::ENTITLEMENT_MERCH => TRUE,
    Ticket::ENTITLEMENT_PARKING => TRUE,
    Ticket::ENTITLEMENT_DRINK => TRUE,
    Ticket::ENTITLEMENT_FOOD => TRUE,
    Ticket::ENTITLEMENT_VIP => TRUE,
    Ticket::ENTITLEMENT_ADDON => TRUE,
  ];

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
   * Returns the normalized entitlement type, defaulting legacy rows to ticket.
   */
  public function getEntitlementType(Ticket $ticket): string {
    $type = $this->readStringField($ticket, 'entitlement_type', Ticket::ENTITLEMENT_TICKET);
    return isset(self::SUPPORTED_TYPES[$type]) ? $type : Ticket::ENTITLEMENT_TICKET;
  }

  /**
   * TRUE when the ticket is an event admission ticket.
   */
  public function isTicket(Ticket $ticket): bool {
    return $this->getEntitlementType($ticket) === Ticket::ENTITLEMENT_TICKET;
  }

  /**
   * TRUE when the ticket is a merch pickup entitlement.
   */
  public function isMerchPickup(Ticket $ticket): bool {
    return $this->getEntitlementType($ticket) === Ticket::ENTITLEMENT_MERCH;
  }

  /**
   * TRUE when the ticket is a parking pass.
   */
  public function isParkingPass(Ticket $ticket): bool {
    return $this->getEntitlementType($ticket) === Ticket::ENTITLEMENT_PARKING;
  }

  /**
   * TRUE when the ticket is a VIP access entitlement.
   */
  public function isVipAccess(Ticket $ticket): bool {
    return $this->getEntitlementType($ticket) === Ticket::ENTITLEMENT_VIP;
  }

  /**
   * TRUE when the entitlement can consume redemption count.
   */
  public function isRedeemable(Ticket $ticket): bool {
    return isset(self::REDEEMABLE_TYPES[$this->getEntitlementType($ticket)]);
  }

  /**
   * TRUE when fulfilment state matters for the entitlement.
   */
  public function requiresFulfilment(Ticket $ticket): bool {
    return isset(self::FULFILMENT_TYPES[$this->getEntitlementType($ticket)]);
  }

  /**
   * TRUE when the entitlement allows more than one successful redemption.
   */
  public function supportsMultipleRedemptions(Ticket $ticket): bool {
    return $this->readIntField($ticket, 'redemption_limit', 1) > 1;
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
    $status = $this->readStringField($ticket, 'status', Ticket::STATUS_ISSUED_UNASSIGNED);
    if (in_array($status, [Ticket::STATUS_VOID, Ticket::STATUS_REFUNDED], TRUE)) {
      return FALSE;
    }

    if ($this->isExpired($ticket)) {
      return FALSE;
    }

    $fulfilment_status = $this->readStringField($ticket, 'fulfilment_status', Ticket::FULFILMENT_PENDING);
    if (in_array($fulfilment_status, [Ticket::FULFILMENT_CANCELLED, Ticket::FULFILMENT_EXPIRED], TRUE)) {
      return FALSE;
    }

    if ($this->isTicket($ticket)) {
      return $status !== Ticket::STATUS_CHECKED_IN;
    }

    if ($this->isRedeemable($ticket)) {
      return $this->getRemainingRedemptions($ticket) > 0;
    }

    return TRUE;
  }

  /**
   * Returns remaining successful redemptions for this entitlement.
   */
  public function getRemainingRedemptions(Ticket $ticket): int {
    $limit = $this->readIntField($ticket, 'redemption_limit', 1);
    $count = $this->readIntField($ticket, 'redemption_count', 0);
    return max(0, $limit - $count);
  }

  /**
   * Reads a string base field while preserving legacy rows without new fields.
   */
  private function readStringField(Ticket $ticket, string $field_name, string $default): string {
    if (!$ticket->hasField($field_name) || $ticket->get($field_name)->isEmpty()) {
      return $default;
    }

    $value = trim((string) $ticket->get($field_name)->value);
    return $value !== '' ? $value : $default;
  }

  /**
   * Reads a non-negative integer base field.
   */
  private function readIntField(Ticket $ticket, string $field_name, int $default): int {
    if (!$ticket->hasField($field_name) || $ticket->get($field_name)->isEmpty()) {
      return $default;
    }

    return max(0, (int) $ticket->get($field_name)->value);
  }

}
