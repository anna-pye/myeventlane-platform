<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\node\NodeInterface;

/**
 * Resolves mutually exclusive CTA type and display for events.
 *
 * Mutual exclusivity rule:
 * - paid: Render Paid Ticket UI only. Do NOT render RSVP.
 * - rsvp: Render RSVP UI only. Do NOT render Paid Ticket UI.
 * - external: External booking link only.
 * - none: Neutral placeholder. No CTA.
 *
 * Logic lives in controller/service. Twig receives only cta_type and
 * a single resolved event_cta (label, url, type, disabled, reason).
 *
 * INVARIANT:
 * Ticket capacity MUST be reflected by BookingFlowResolver availability. Do not
 * rely on node edit form or UI state.
 * This protects Ticket UX (Phase 3A).
 *
 * @deprecated in myeventlane_event:11.x and is kept as a compatibility wrapper.
 *   Use BookingFlowResolver::getBookingMode() and
 *   BookingFlowResolver::getPrimaryCta() for booking decisions.
 */
final class EventCtaResolver {

  public const CTA_PAID = 'paid';
  public const CTA_RSVP = 'rsvp';
  public const CTA_EXTERNAL = 'external';
  public const CTA_NONE = 'none';

  /**
   * Constructs EventCtaResolver.
   *
   * @param \Drupal\myeventlane_event\Service\BookingFlowResolver $bookingFlowResolver
   *   The canonical booking flow resolver.
   */
  public function __construct(
    private readonly BookingFlowResolver $bookingFlowResolver,
  ) {}

  /**
   * Gets the mutually exclusive CTA type for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return string
   *   One of: self::CTA_PAID, self::CTA_RSVP, self::CTA_EXTERNAL, self::CTA_NONE.
   */
  public function getCtaType(NodeInterface $event): string {
    $mode = $this->bookingFlowResolver->getBookingMode($event);
    return $mode === BookingFlowResolver::MODE_UNAVAILABLE ? self::CTA_NONE : $mode;
  }

  /**
   * Gets the resolved CTA for Twig (label, url, disabled, helper).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array{label: string, url: string, type: string, disabled: bool, reason: string|null}
   *   Single CTA structure. Twig uses this only; no logic in templates.
   */
  public function getResolvedCta(NodeInterface $event): array {
    return $this->bookingFlowResolver->getPrimaryCta($event);
  }

  /**
   * Checks if the event is bookable for the active CTA type.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if a CTA is available (paid, rsvp, or external).
   */
  public function isBookable(NodeInterface $event): bool {
    $cta = $this->bookingFlowResolver->getPrimaryCta($event);
    if ($this->getCtaType($event) === self::CTA_NONE) {
      return FALSE;
    }
    return !$cta['disabled'] && $cta['type'] !== 'disabled' && $cta['url'] !== '';
  }

}
