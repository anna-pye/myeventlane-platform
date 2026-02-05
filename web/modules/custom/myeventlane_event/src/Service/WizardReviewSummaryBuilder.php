<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Builds read-only review summary and warnings for the wizard review step.
 *
 * Does not save or alter the event. Used by EventWizardReviewForm only.
 */
final class WizardReviewSummaryBuilder {

  use StringTranslationTrait;

  /**
   * Constructs the service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Route names for "Fix" links per wizard step.
   */
  private const FIX_ROUTES = [
    'basics' => 'myeventlane_event.wizard.basics',
    'when_where' => 'myeventlane_event.wizard.when_where',
    'tickets' => 'myeventlane_event.wizard.tickets',
    'details' => 'myeventlane_event.wizard.details',
  ];

  /**
   * Builds the full review payload: groups, warnings, and ready flags.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Keys: groups (summary sections), warnings (fix links), ready (booleans).
   */
  public function build(NodeInterface $event): array {
    $groups = $this->buildGroups($event);
    $warnings = $this->buildWarnings($event);
    $ready = $this->buildReady($event);

    return [
      'groups' => $groups,
      'warnings' => $warnings,
      'ready' => $ready,
    ];
  }

  /**
   * Builds summary groups (Basics, When & Where, Tickets, Details).
   *
   * @return array
   *   Summary sections keyed by group id, each with title and items.
   */
  private function buildGroups(NodeInterface $event): array {
    $groups = [];

    // Group 1: Basics.
    $groups['basics'] = [
      'title' => $this->t('Event Basics'),
      'items' => [],
    ];
    $groups['basics']['items'][] = [
      'label' => $this->t('Event name'),
      'value' => $event->label() ?: $this->t('Not set'),
    ];
    if ($event->hasField('field_event_intro')) {
      $intro = $event->get('field_event_intro')->value;
      $groups['basics']['items'][] = [
        'label' => $this->t('Intro'),
        'value' => $intro ? $this->truncate($intro, 80) : $this->t('Not set'),
      ];
    }
    if ($event->hasField('field_category')) {
      $cat = $event->get('field_category')->isEmpty()
        ? $this->t('Not set')
        : ($event->get('field_category')->entity?->label() ?? $this->t('Not set'));
      $groups['basics']['items'][] = [
        'label' => $this->t('Category'),
        'value' => $cat,
      ];
    }
    $groups['basics']['items'][] = [
      'label' => $this->t('Image'),
      'value' => $event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()
        ? $this->t('Yes')
        : $this->t('No image'),
    ];

    // Group 2: When & Where.
    $groups['when_where'] = [
      'title' => $this->t('When & Where'),
      'items' => [],
    ];
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $start_date = $event->get('field_event_start')->date;
      $groups['when_where']['items'][] = [
        'label' => $this->t('Start'),
        'value' => $start_date ? $start_date->format('F j, Y g:i A') : $this->t('Not set'),
      ];
    }
    if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
      $end_date = $event->get('field_event_end')->date;
      $groups['when_where']['items'][] = [
        'label' => $this->t('End'),
        'value' => $end_date ? $end_date->format('F j, Y g:i A') : $this->t('Not set'),
      ];
    }
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $groups['when_where']['items'][] = [
        'label' => $this->t('Venue'),
        'value' => $event->get('field_venue_name')->value ?? $this->t('Not set'),
      ];
    }
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $address = $event->get('field_location')->first();
      if ($address) {
        $parts = array_filter([
          $address->address_line1 ?? '',
          $address->address_line2 ?? '',
          $address->locality ?? '',
          $address->administrative_area ?? '',
          $address->postal_code ?? '',
        ]);
        $groups['when_where']['items'][] = [
          'label' => $this->t('Location'),
          'value' => implode(', ', $parts) ?: $this->t('Not set'),
        ];
      }
    }

    // Group 3: Tickets.
    $groups['tickets'] = [
      'title' => $this->t('Tickets'),
      'items' => [],
    ];
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      $event_type = $event->get('field_event_type')->value;
      $groups['tickets']['items'][] = [
        'label' => $this->t('Event type'),
        'value' => $event_type ?? $this->t('Not set'),
      ];
    }
    if ($event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      $groups['tickets']['items'][] = [
        'label' => $this->t('Capacity'),
        'value' => $event->get('field_capacity')->value ?? $this->t('Not set'),
      ];
    }
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $product = $event->get('field_product_target')->entity;
      $groups['tickets']['items'][] = [
        'label' => $this->t('Product'),
        'value' => $product ? $product->label() : $this->t('Not set'),
      ];
    }
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $count = $event->get('field_ticket_types')->count();
      $groups['tickets']['items'][] = [
        'label' => $this->t('Ticket types'),
        'value' => $this->formatPlural($count, '1 type', '@count types'),
      ];
    }
    if ($event->hasField('field_attendee_questions') && !$event->get('field_attendee_questions')->isEmpty()) {
      $count = $event->get('field_attendee_questions')->count();
      $groups['tickets']['items'][] = [
        'label' => $this->t('Attendee questions'),
        'value' => $this->formatPlural($count, '1 question', '@count questions'),
      ];
    }

    // Group 4: Details.
    $groups['details'] = [
      'title' => $this->t('Details'),
      'items' => [],
    ];
    if ($event->hasField('field_refund_policy') && !$event->get('field_refund_policy')->isEmpty()) {
      $groups['details']['items'][] = [
        'label' => $this->t('Refund policy'),
        'value' => $event->get('field_refund_policy')->value ?? $this->t('Not set'),
      ];
    }
    if ($event->hasField('field_age_policy') && !$event->get('field_age_policy')->isEmpty()) {
      $groups['details']['items'][] = [
        'label' => $this->t('Age suitability'),
        'value' => $event->get('field_age_policy')->value ?? $this->t('Not set'),
      ];
    }
    if ($event->hasField('field_event_highlights')) {
      $count = $event->get('field_event_highlights')->count();
      $groups['details']['items'][] = [
        'label' => $this->t('Highlights'),
        'value' => $this->formatPlural($count, '1 highlight', '@count highlights'),
      ];
    }
    $has_accessibility = FALSE;
    if ($event->hasField('field_accessibility') && !$event->get('field_accessibility')->isEmpty()) {
      $has_accessibility = TRUE;
    }
    if ($event->hasField('field_accessibility_contact') && !$event->get('field_accessibility_contact')->isEmpty()) {
      $has_accessibility = TRUE;
    }
    if ($event->hasField('field_accessibility_directions') && !$event->get('field_accessibility_directions')->isEmpty()) {
      $has_accessibility = TRUE;
    }
    if ($event->hasField('field_accessibility_entry') && !$event->get('field_accessibility_entry')->isEmpty()) {
      $has_accessibility = TRUE;
    }
    if ($event->hasField('field_accessibility_parking') && !$event->get('field_accessibility_parking')->isEmpty()) {
      $has_accessibility = TRUE;
    }
    $groups['details']['items'][] = [
      'label' => $this->t('Accessibility'),
      'value' => $has_accessibility ? $this->t('Yes') : $this->t('None specified'),
    ];

    return $groups;
  }

  /**
   * Builds non-blocking warnings with Fix route and params.
   *
   * @return array
   *   List of warning items (message, severity, fix_route, fix_route_params).
   */
  private function buildWarnings(NodeInterface $event): array {
    $warnings = [];
    $event_id = (int) $event->id();

    // Recommended (yellow)
    if ($event->hasField('field_event_image') && $event->get('field_event_image')->isEmpty()) {
      $warnings[] = [
        'message' => $this->t('No event image. Adding one helps your event stand out.'),
        'severity' => 'recommended',
        'fix_route' => self::FIX_ROUTES['basics'],
        'fix_route_params' => ['event' => $event_id],
      ];
    }
    if ($event->hasField('field_event_intro') && trim((string) $event->get('field_event_intro')->value) === '') {
      $warnings[] = [
        'message' => $this->t('No intro text. A short description helps attendees understand your event.'),
        'severity' => 'recommended',
        'fix_route' => self::FIX_ROUTES['basics'],
        'fix_route_params' => ['event' => $event_id],
      ];
    }
    if ($event->hasField('field_event_highlights') && $event->get('field_event_highlights')->count() === 0) {
      $warnings[] = [
        'message' => $this->t('No highlights. Highlights help attendees see what to expect.'),
        'severity' => 'recommended',
        'fix_route' => self::FIX_ROUTES['details'],
        'fix_route_params' => ['event' => $event_id],
      ];
    }
    if ($event->hasField('field_refund_policy') && $event->get('field_refund_policy')->isEmpty()) {
      $warnings[] = [
        'message' => $this->t('Refund policy not provided.'),
        'severity' => 'recommended',
        'fix_route' => self::FIX_ROUTES['details'],
        'fix_route_params' => ['event' => $event_id],
      ];
    }
    if ($event->hasField('field_age_policy') && $event->get('field_age_policy')->isEmpty()) {
      $warnings[] = [
        'message' => $this->t('Age suitability not specified.'),
        'severity' => 'recommended',
        'fix_route' => self::FIX_ROUTES['details'],
        'fix_route_params' => ['event' => $event_id],
      ];
    }
    // Accessibility: warn if enabled (has terms) but no subfields filled.
    if ($event->hasField('field_accessibility') && !$event->get('field_accessibility')->isEmpty()) {
      $has_sub = ($event->hasField('field_accessibility_contact') && !$event->get('field_accessibility_contact')->isEmpty())
        || ($event->hasField('field_accessibility_directions') && !$event->get('field_accessibility_directions')->isEmpty())
        || ($event->hasField('field_accessibility_entry') && !$event->get('field_accessibility_entry')->isEmpty())
        || ($event->hasField('field_accessibility_parking') && !$event->get('field_accessibility_parking')->isEmpty());
      if (!$has_sub) {
        $warnings[] = [
          'message' => $this->t('Accessibility tags are set but no details (contact, directions, entry, parking) are provided.'),
          'severity' => 'recommended',
          'fix_route' => self::FIX_ROUTES['details'],
          'fix_route_params' => ['event' => $event_id],
        ];
      }
    }

    // Important (orange)
    if ($event->hasField('field_event_start') && $event->get('field_event_start')->isEmpty()) {
      $warnings[] = [
        'message' => $this->t('Start date and time are missing.'),
        'severity' => 'important',
        'fix_route' => self::FIX_ROUTES['when_where'],
        'fix_route_params' => ['event' => $event_id],
      ];
    }
    $has_location = $event->hasField('field_location') && !$event->get('field_location')->isEmpty();
    if (!$has_location) {
      $warnings[] = [
        'message' => $this->t('No location or address set.'),
        'severity' => 'important',
        'fix_route' => self::FIX_ROUTES['when_where'],
        'fix_route_params' => ['event' => $event_id],
      ];
    }
    if ($event->hasField('field_event_type') && $event->get('field_event_type')->isEmpty()) {
      $warnings[] = [
        'message' => $this->t('Event type is not set.'),
        'severity' => 'important',
        'fix_route' => self::FIX_ROUTES['tickets'],
        'fix_route_params' => ['event' => $event_id],
      ];
    }

    // Ticket readiness (orange)
    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? $event->get('field_event_type')->value
      : NULL;
    $is_paid = $event_type && in_array($event_type, ['paid', 'both'], TRUE);
    if ($is_paid) {
      if ($event->hasField('field_product_target') && $event->get('field_product_target')->isEmpty()) {
        $warnings[] = [
          'message' => $this->t('Paid event but no product linked. Add a product in the Tickets step.'),
          'severity' => 'important',
          'fix_route' => self::FIX_ROUTES['tickets'],
          'fix_route_params' => ['event' => $event_id],
        ];
      }
      if ($event->hasField('field_ticket_types') && $event->get('field_ticket_types')->count() === 0) {
        $warnings[] = [
          'message' => $this->t('Paid event but no ticket types configured.'),
          'severity' => 'important',
          'fix_route' => self::FIX_ROUTES['tickets'],
          'fix_route_params' => ['event' => $event_id],
        ];
      }
    }
    $is_rsvp = $event_type && in_array($event_type, ['rsvp', 'both'], TRUE);
    if ($is_rsvp && $event->hasField('field_capacity')) {
      $cap = $event->get('field_capacity')->value;
      if ($cap === NULL || $cap === '' || (int) $cap <= 0) {
        $warnings[] = [
          'message' => $this->t('RSVP event but capacity is missing or zero.'),
          'severity' => 'important',
          'fix_route' => self::FIX_ROUTES['tickets'],
          'fix_route_params' => ['event' => $event_id],
        ];
      }
    }

    return $warnings;
  }

  /**
   * Builds ready flags (tickets, location, details).
   *
   * @return array
   *   Keys: tickets_ready, location_ready, details_ready (booleans).
   */
  private function buildReady(NodeInterface $event): array {
    $location_ready = $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()
      && $event->hasField('field_location') && !$event->get('field_location')->isEmpty();

    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? $event->get('field_event_type')->value
      : NULL;
    $tickets_ready = TRUE;
    if (!$event_type) {
      $tickets_ready = FALSE;
    }
    elseif (in_array($event_type, ['paid', 'both'], TRUE)) {
      $has_product = $event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty();
      $has_types = $event->hasField('field_ticket_types') && $event->get('field_ticket_types')->count() > 0;
      $tickets_ready = $has_product && $has_types;
    }
    elseif (in_array($event_type, ['rsvp', 'both'], TRUE) && $event->hasField('field_capacity')) {
      $cap = $event->get('field_capacity')->value;
      if ($cap === NULL || $cap === '' || (int) $cap <= 0) {
        $tickets_ready = FALSE;
      }
    }

    $details_ready = TRUE;
    if ($event->hasField('field_refund_policy') && $event->get('field_refund_policy')->isEmpty()) {
      $details_ready = FALSE;
    }
    if ($event->hasField('field_age_policy') && $event->get('field_age_policy')->isEmpty()) {
      $details_ready = FALSE;
    }

    return [
      'tickets_ready' => $tickets_ready,
      'location_ready' => $location_ready,
      'details_ready' => $details_ready,
    ];
  }

  /**
   * Truncates a string to a max length with ellipsis.
   *
   * @return string
   *   Truncated string, or original if within limit.
   */
  private function truncate(string $text, int $max): string {
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
      return $text;
    }
    return mb_substr($text, 0, $max - 3) . '...';
  }

}
