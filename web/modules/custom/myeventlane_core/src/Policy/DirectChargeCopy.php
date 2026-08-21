<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Policy;

/**
 * Approved source wording for organiser direct-charge payment surfaces.
 *
 * Context-specific variants may be shorter, but must retain the same meaning.
 */
final class DirectChargeCopy {

  public const CUSTOMER_SELLER = 'For each paid event, the event organiser is the seller. MyEventLane provides the marketplace and booking workflow. Stripe processes your ticket payment through the organiser\'s connected Stripe account.';

  public const PAYMENT = 'Ticket payments are processed through your connected Stripe account. Your ticket revenue belongs to you and is managed through Stripe. Stripe sends available funds to your nominated bank account according to your Stripe payout schedule. MyEventLane does not hold or manually release your ticket-sale funds.';

  public const REFUND = 'You remain responsible for refunds for your event. MyEventLane can help you process a refund through the booking system, but the refunded money comes from your connected Stripe account. Make sure sufficient funds are available to cover refunds, disputes or event cancellations.';

  public const STRIPE_PAYOUT = "Stripe controls when available funds are sent from your Stripe account to your nominated bank account. You can view your Stripe balance and manage your payout schedule and bank details in Stripe. MyEventLane can show payment information reported by Stripe, but cannot release a payout or change Stripe's payout timing.";

  public const PUBLISH_GATE = 'To accept event payments, finish your Stripe setup. Ticket payments go to your connected Stripe account, and Stripe sends available funds to your bank.';

  public const RECONNECT_GATE = 'Paid ticket sales stay blocked while you reconnect Stripe. Finish the Stripe steps so direct ticket payments use the approved connected-account configuration.';

  private function __construct() {}

}
