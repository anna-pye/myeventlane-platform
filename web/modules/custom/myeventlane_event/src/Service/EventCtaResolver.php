<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Url;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves mutually exclusive CTA type and display for events.
 *
 * Mutual exclusivity rule:
 * - paid: Render Paid Ticket UI only. Do NOT render RSVP.
 * - rsvp: Render RSVP UI only. Do NOT render Paid Ticket UI.
 * - none: Neutral placeholder. No CTA.
 *
 * Logic lives in controller/service. Twig receives only cta_type and
 * a single resolved event_cta (label, url, disabled, helper, remaining).
 *
 * INVARIANT:
 * Ticket capacity MUST be reflected here (sold-out, remaining, helper from
 * EventModeManager/EventCapacityService). Do not rely on node edit form or UI state.
 * This protects Ticket UX (Phase 3A).
 */
final class EventCtaResolver {

  /**
   * Threshold below which we show "Only X left" helper.
   */
  private const LOW_AVAILABILITY_THRESHOLD = 10;

  public const CTA_PAID = 'paid';
  public const CTA_RSVP = 'rsvp';
  public const CTA_NONE = 'none';

  /**
   * Constructs EventCtaResolver.
   *
   * @param \Drupal\myeventlane_event\Service\EventModeManager $modeManager
   *   The event mode manager.
   * @param \Drupal\myeventlane_event_state\Service\EventStateResolverInterface $stateResolver
   *   The event state resolver.
   */
  public function __construct(
    private readonly EventModeManager $modeManager,
    private readonly EventStateResolverInterface $stateResolver,
  ) {}

  /**
   * Gets the mutually exclusive CTA type for an event.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return string
   *   One of: self::CTA_PAID, self::CTA_RSVP, self::CTA_NONE.
   */
  public function getCtaType(NodeInterface $event): string {
    if ($event->bundle() !== 'event') {
      return self::CTA_NONE;
    }

    $mode = $this->modeManager->getEffectiveMode($event);

    // Mutual exclusivity: one active CTA only.
    if (in_array($mode, [EventModeManager::MODE_PAID, EventModeManager::MODE_BOTH], TRUE)) {
      return self::CTA_PAID;
    }
    if ($mode === EventModeManager::MODE_RSVP) {
      return self::CTA_RSVP;
    }

    return self::CTA_NONE;
  }

  /**
   * Gets the resolved CTA for Twig (label, url, disabled, helper).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array{cta_type: string, label: string, url: string|null, disabled: bool, helper: string|null}
   *   Single CTA structure. Twig uses this only; no logic in templates.
   */
  public function getResolvedCta(NodeInterface $event): array {
    $ctaType = $this->getCtaType($event);
    $state = $this->stateResolver->resolveState($event);

    $base = [
      'cta_type' => $ctaType,
      'label' => '',
      'url' => NULL,
      'disabled' => TRUE,
      'helper' => NULL,
      'remaining' => NULL,
    ];

    if ($state === 'cancelled' || $state === 'ended') {
      $base['helper'] = NULL;
      return $base;
    }

    if ($state === 'sold_out') {
      if ($ctaType === self::CTA_RSVP) {
        $avail = $this->modeManager->getRsvpAvailability($event);
        if (isset($avail['spots_remaining']) && $avail['spots_remaining'] === 0) {
          $base['label'] = 'Join Waitlist';
          $base['url'] = Url::fromRoute('myeventlane_event_attendees.waitlist_signup', ['node' => $event->id()])->toString();
          $base['disabled'] = FALSE;
        }
        else {
          $base['label'] = 'Sold Out';
        }
      }
      else {
        $base['label'] = 'Sold Out';
      }
      return $base;
    }

    if ($state === 'scheduled') {
      $salesStart = $this->stateResolver->getSalesStart($event);
      $formatted = $salesStart ? date('F j, Y g:ia', $salesStart) : NULL;
      $base['helper'] = $formatted;
      $base['label'] = $formatted ? 'Sales open on ' . $formatted : 'Sales opening soon';
      return $base;
    }

    if ($ctaType === self::CTA_NONE) {
      $base['label'] = '';
      return $base;
    }

    $bookUrl = Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event->id()]);

    if ($ctaType === self::CTA_PAID) {
      $avail = $this->modeManager->getTicketAvailability($event);
      $base['remaining'] = $avail['remaining'] ?? NULL;
      if ($avail['available']) {
        $base['label'] = (string) \t('Buy Tickets');
        $base['url'] = $bookUrl->toString();
        $base['disabled'] = FALSE;
        if ($base['remaining'] !== NULL && $base['remaining'] > 0 && $base['remaining'] <= self::LOW_AVAILABILITY_THRESHOLD) {
          $base['helper'] = (string) \t('Only @count tickets remaining', ['@count' => $base['remaining']]);
        }
      }
      else {
        $base['label'] = (string) \t('Tickets');
        $base['url'] = $bookUrl->toString();
        $base['disabled'] = FALSE;
      }
      return $base;
    }

    if ($ctaType === self::CTA_RSVP) {
      $avail = $this->modeManager->getRsvpAvailability($event);
      $base['remaining'] = $avail['spots_remaining'] ?? NULL;
      if ($avail['available']) {
        $base['label'] = (string) \t('RSVP Now');
        $base['url'] = $bookUrl->toString();
        $base['disabled'] = FALSE;
        if ($base['remaining'] !== NULL && $base['remaining'] > 0 && $base['remaining'] <= self::LOW_AVAILABILITY_THRESHOLD) {
          $base['helper'] = (string) \t('Only @count spots left', ['@count' => $base['remaining']]);
        }
      }
      elseif (isset($avail['spots_remaining']) && $avail['spots_remaining'] === 0) {
        $base['label'] = (string) \t('Join Waitlist');
        $base['url'] = Url::fromRoute('myeventlane_event_attendees.waitlist_signup', ['node' => $event->id()])->toString();
        $base['disabled'] = FALSE;
      }
      else {
        $base['label'] = (string) \t('RSVP');
        $base['url'] = $bookUrl->toString();
        $base['disabled'] = FALSE;
      }
      return $base;
    }

    return $base;
  }

  /**
   * Checks if the event is bookable for the active CTA type.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if a CTA is available (paid, rsvp, or waitlist).
   */
  public function isBookable(NodeInterface $event): bool {
    $cta = $this->getResolvedCta($event);
    if ($cta['cta_type'] === self::CTA_NONE) {
      return FALSE;
    }
    return !$cta['disabled'] && $cta['url'] !== NULL;
  }

}
