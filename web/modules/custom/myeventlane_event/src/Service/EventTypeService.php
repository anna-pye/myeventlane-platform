<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\node\NodeInterface;

/**
 * Simple event type detection service for templates.
 *
 * This service provides a simplified interface for detecting event types,
 * primarily for use in Twig templates via preprocess functions.
 *
 * Event Types:
 * - 'rsvp': Free RSVP events
 * - 'paid': Paid ticketed events
 * - 'both': Events with both free RSVP and paid ticket options
 * - 'external': Events with external booking link
 * - 'none': Unknown or unconfigured events
 */
final class EventTypeService {

  /**
   * Event type constants.
   */
  public const TYPE_RSVP = 'rsvp';
  public const TYPE_PAID = 'paid';
  public const TYPE_BOTH = 'both';
  public const TYPE_EXTERNAL = 'external';
  public const TYPE_NONE = 'none';

  /**
   * Constructs EventTypeService.
   */
  public function __construct(
    private readonly EventModeManager $modeManager,
  ) {}

  /**
   * Gets the event type for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   One of: 'rsvp', 'paid', 'both', 'external', 'none'.
   */
  public function getEventType(NodeInterface $node): string {
    if ($node->bundle() !== 'event') {
      return self::TYPE_NONE;
    }

    return $this->modeManager->getEffectiveMode($node);
  }

  /**
   * Gets the human-readable label for the event type.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   Human-readable label: 'RSVP', 'Tickets', 'RSVP & Tickets',
   *   'External', or 'Unavailable'.
   */
  public function getEventTypeLabel(NodeInterface $node): string {
    $type = $this->getEventType($node);

    return match ($type) {
      self::TYPE_RSVP => 'RSVP',
      self::TYPE_PAID => 'Tickets',
      self::TYPE_BOTH => 'RSVP & Tickets',
      self::TYPE_EXTERNAL => 'External',
      self::TYPE_NONE => 'Unavailable',
      default => 'Unavailable',
    };
  }

  /**
   * Gets the CTA label for the event type.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   CTA label: 'RSVP Now', 'Buy Tickets', 'Get Tickets', or 'View Event'.
   */
  public function getCtaLabel(NodeInterface $node): string {
    $type = $this->getEventType($node);

    return match ($type) {
      self::TYPE_RSVP => 'RSVP Now',
      self::TYPE_PAID, self::TYPE_BOTH => 'Buy Tickets',
      self::TYPE_EXTERNAL => 'Get Tickets',
      default => 'View Event',
    };
  }

  /**
   * Gets the short CTA label for compact display.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   Short label: 'RSVP', 'Tickets', or 'View'.
   */
  public function getShortCtaLabel(NodeInterface $node): string {
    $type = $this->getEventType($node);

    return match ($type) {
      self::TYPE_RSVP => 'RSVP',
      self::TYPE_PAID, self::TYPE_BOTH, self::TYPE_EXTERNAL => 'Tickets',
      default => 'View',
    };
  }

  /**
   * Checks if the event is free (RSVP only).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return bool
   *   TRUE if the event is free RSVP only.
   */
  public function isFree(NodeInterface $node): bool {
    return $this->getEventType($node) === self::TYPE_RSVP;
  }

  /**
   * Checks if the event has paid tickets.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return bool
   *   TRUE if the event has paid ticket options.
   */
  public function hasPaidTickets(NodeInterface $node): bool {
    $type = $this->getEventType($node);
    return in_array($type, [self::TYPE_PAID, self::TYPE_BOTH], TRUE);
  }

  /**
   * Checks if the event is bookable.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return bool
   *   TRUE if the event can be booked.
   */
  public function isBookable(NodeInterface $node): bool {
    return $this->modeManager->isBookable($node);
  }

  /**
   * Gets all template variables for an event.
   *
   * This is a convenience method that returns all event type information
   * in a single array, suitable for passing to Twig templates.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array
   *   Array with keys:
   *   - 'event_type': The event type constant
   *   - 'event_type_label': Human-readable label
   *   - 'event_cta_label': CTA button label
   *   - 'event_cta_short': Short CTA label
   *   - 'event_is_free': Whether event is free
   *   - 'event_has_paid_tickets': Whether event has paid options
   *   - 'event_is_bookable': Whether event can be booked
   *   - 'event_is_rsvp': Whether event is RSVP type
   *   - 'event_is_paid': Whether event is paid ticket type
   *   - 'event_is_external': Whether event uses external link
   */
  public function getTemplateVariables(NodeInterface $node): array {
    $type = $this->getEventType($node);

    return [
      'event_type' => $type,
      'event_type_label' => $this->getEventTypeLabel($node),
      'event_cta_label' => $this->getCtaLabel($node),
      'event_cta_short' => $this->getShortCtaLabel($node),
      'event_is_free' => $type === self::TYPE_RSVP,
      'event_has_paid_tickets' => in_array($type, [self::TYPE_PAID, self::TYPE_BOTH], TRUE),
      'event_is_bookable' => $this->isBookable($node),
      'event_is_rsvp' => in_array($type, [self::TYPE_RSVP, self::TYPE_BOTH], TRUE),
      'event_is_paid' => in_array($type, [self::TYPE_PAID, self::TYPE_BOTH], TRUE),
      'event_is_external' => $type === self::TYPE_EXTERNAL,
    ];
  }

}















