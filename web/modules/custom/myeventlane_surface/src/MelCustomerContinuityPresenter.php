<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\MelReadinessHelper;

/**
 * Customer confirmation surfaces: governed CTA ordering without duplicating workflow rules.
 *
 * Sequencing aligns with MELExperienceRegistry::rsvp_completion_calendar_prompt and
 * MelWorkflowRegistry::first_rsvp next-step recommendations (event return, calendar export).
 */
final class MelCustomerContinuityPresenter {

  public function __construct(
    private readonly MelReadinessHelper $readinessHelper,
  ) {}

  /**
   * Presentation payload for mel_rsvp_thankyou (Twig consumes structured fields only).
   *
   * @return array<string, mixed>
   */
  public function buildRsvpThankYouPresentation(
    NodeInterface $event,
    string $event_url,
    bool $donation_pending,
    bool $donation_completed,
    float $donation,
    ?string $donation_checkout_url,
    string $cancel_url,
    bool $can_add_post_rsvp_contribution,
    string $attendee_name,
    string $attendee_email,
    int $guests,
    bool $is_authenticated = FALSE,
  ): array {
    $title = $event->label();
    $calendar_url = Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => (int) $event->id()])->toString();

    $headline = $this->readinessHelper->customerRsvpConfirmationHeadline();
    $subtitle = $this->readinessHelper->customerRsvpSpotConfirmedSubtitle($title);

    /** @var list<string> $meta_lines */
    $meta_lines = [];
    if ($attendee_name !== '') {
      $meta_lines[] = $this->readinessHelper->customerRsvpBookedAsMeta($attendee_name);
    }
    if ($attendee_email !== '') {
      $meta_lines[] = $attendee_email;
    }
    if ($guests > 1) {
      $meta_lines[] = $this->readinessHelper->customerRsvpSpotsBookedMeta($guests);
    }

    $status_line = $this->readinessHelper->customerRsvpStatusDefaultConfirmed();
    if ($donation > 0 && $donation_completed) {
      $status_line = $this->readinessHelper->customerRsvpStatusPaymentReceivedConfirmed();
    }
    elseif ($donation_pending) {
      $status_line = $this->readinessHelper->customerRsvpStatusCheckoutIncomplete();
    }

    $amount_display = '$' . number_format($donation > 0 ? $donation : 0.0, 2, '.', '');

    $donation_band = NULL;
    if ($donation > 0 && $donation_pending) {
      $donation_band = [
        'type' => 'pending',
        'title' => $this->readinessHelper->customerRsvpDonationPendingTitle(),
        'body' => $this->readinessHelper->customerRsvpDonationPendingBody($amount_display),
        'progress_label' => $this->readinessHelper->customerRsvpDonationProgressLabel(),
        'reassurance' => $this->readinessHelper->customerRsvpDonationReassuranceLine(),
        'contribution_button_label' => $this->readinessHelper->customerContinuityCompleteContributionCta($amount_display),
        'checkout_url' => $donation_checkout_url ?? '',
      ];
    }
    elseif ($donation > 0 && $donation_completed) {
      $donation_band = [
        'type' => 'complete',
        'title' => $this->readinessHelper->customerRsvpDonationCompleteTitle(),
        'body' => $this->readinessHelper->customerRsvpDonationCompleteBody(),
        'badge' => $this->readinessHelper->customerRsvpDonationCompleteBadge(),
      ];
    }
    elseif ($donation <= 0.0) {
      $donation_band = [
        'type' => 'upsell',
        'title' => $this->readinessHelper->customerRsvpDonationUpsellTitle(),
        'body' => $this->readinessHelper->customerRsvpDonationUpsellBody(),
      ];
    }

    /** @var list<array<string, string|bool>> $actions */
    $actions = [];
    $donation_checkout = $donation_checkout_url !== NULL && $donation_checkout_url !== '';
    $donation_section_primary = $donation > 0 && $donation_pending && $donation_checkout;

    if ($donation_section_primary) {
      $actions[] = [
        'key' => 'complete_donation',
        'label' => $this->readinessHelper->customerContinuityCompleteContributionCta($amount_display),
        'url' => $donation_checkout_url,
        'variant' => 'primary',
        'download' => FALSE,
      ];
    }

    if ($event_url !== '') {
      $actions[] = [
        'key' => 'view_event',
        'label' => $this->readinessHelper->customerContinuityViewEventCta(),
        'url' => $event_url,
        'variant' => (!$donation_section_primary && $actions === []) ? 'primary' : 'secondary',
        'download' => FALSE,
      ];
    }

    if ($is_authenticated) {
      try {
        $my_events_url = Url::fromRoute('myeventlane_dashboard.customer')->toString();
      }
      catch (\Throwable) {
        $my_events_url = '';
      }
      if ($my_events_url !== '') {
        $actions[] = [
          'key' => 'view_my_events',
          'label' => $this->readinessHelper->customerContinuityMyEventsCta(),
          'url' => $my_events_url,
          'variant' => 'secondary',
          'download' => FALSE,
        ];
      }
    }

    $actions[] = [
      'key' => 'add_calendar',
      'label' => $this->readinessHelper->customerContinuityAddToCalendarCta(),
      'url' => $calendar_url,
      'variant' => (!$donation_section_primary && $actions === []) ? 'primary' : 'secondary',
      'download' => TRUE,
    ];

    if ($can_add_post_rsvp_contribution && $event_url !== '') {
      $actions[] = [
        'key' => 'add_contribution',
        'label' => $this->readinessHelper->customerContinuityAddContributionCta(),
        'url' => $event_url,
        'variant' => 'secondary',
        'download' => FALSE,
      ];
    }

    $actions = array_merge($actions, $this->buildPostBookingDiscoveryActions(FALSE));

    if ($cancel_url !== '') {
      $actions[] = [
        'key' => 'cancel_rsvp',
        'label' => $this->readinessHelper->customerContinuityCancelRsvpCta(),
        'url' => $cancel_url,
        'variant' => 'danger',
        'download' => FALSE,
      ];
    }

    return [
      'headline' => $headline,
      'subtitle' => $subtitle,
      'meta_lines' => $meta_lines,
      'status_line' => $status_line,
      'donation_amount_display' => $amount_display,
      'ics_calendar_url' => $calendar_url,
      'donation_band' => $donation_band,
      'continuity_actions' => $actions,
    ];
  }

  /**
   * Commerce checkout completion: governed copy + CTA URLs (no Commerce types here).
   *
   * @param array<string, string|null> $calendar_links
   *   Keys: apple, google, outlook → URL strings or NULL.
   *
   * @return array<string, mixed>
   */
  public function buildCheckoutCompletionPresentation(
    string $email,
    string $order_number,
    ?int $customer_id,
    int $order_entity_id,
    bool $has_tickets,
    ?string $donation_total_formatted,
    array $calendar_links,
    string $event_url,
    bool $is_paid = TRUE,
  ): array {
    $labels = $this->readinessHelper->customerCheckoutCompletionPresentationLabels();
    $hero = $this->resolveCheckoutCompletionHero($has_tickets, $donation_total_formatted, $labels, $is_paid);

    $primary_url = '';
    $primary_label = '';
    if ($customer_id) {
      try {
        $primary_url = Url::fromRoute('myeventlane_checkout_flow.order_detail', [
          'commerce_order' => $order_entity_id,
        ])->toString();
      }
      catch (\Throwable) {
        $primary_url = '';
      }
      $primary_label = $has_tickets ? $labels['view_ticket'] : $labels['view_order'];
    }

    $calendar = [];
    foreach (['apple' => 'add_to_calendar', 'google' => 'google_calendar', 'outlook' => 'outlook_calendar'] as $link_key => $label_key) {
      $href = $calendar_links[$link_key] ?? NULL;
      if (is_string($href) && $href !== '') {
        $calendar[] = [
          'key' => $link_key,
          'label' => $labels[$label_key] ?? $labels['add_to_calendar'],
          'url' => $href,
        ];
      }
    }

    $primary_action = NULL;
    if ($primary_url !== '') {
      $primary_action = [
        'label' => $primary_label,
        'url' => $primary_url,
      ];
    }

    $view_event = NULL;
    if ($event_url !== '') {
      $view_event = [
        'label' => $labels['view_event'],
        'url' => $event_url,
      ];
    }

    $email_line = $has_tickets && !$is_paid
      ? $this->readinessHelper->customerCheckoutPendingPaymentEmailLine($email)
      : $this->readinessHelper->customerCheckoutConfirmationEmailSentLine($email);

    return [
      'hero' => $hero,
      'labels' => $labels,
      'confirmation' => [
        'email_line' => $email_line,
        'order_line' => $this->readinessHelper->customerCheckoutOrderNumberLine($order_number),
      ],
      'guest' => [
        'body' => $labels['guest_ticket_email_body'],
        'order_intro' => $labels['guest_order_number_intro'],
        'order_number' => $order_number,
      ],
      'what_next' => [
        'title' => $labels['what_next_title'],
        'body' => $labels['what_next_body'],
      ],
      'organiser_trust' => [
        'title' => $labels['organiser_trust_title'],
        'body' => $labels['organiser_trust_body'],
      ],
      'primary_action' => $primary_action,
      'calendar' => $calendar,
      'view_event' => $view_event,
      'continuity_actions' => $this->buildPostBookingDiscoveryActions(TRUE),
      'donation_total_formatted' => $donation_total_formatted,
    ];
  }

  /**
   * Post-booking discovery bridge — shared RSVP thank-you + checkout completion.
   *
   * @return list<array<string, string|bool>>
   */
  private function buildPostBookingDiscoveryActions(bool $browse_more_label): array {
    /** @var list<array<string, string|bool>> $actions */
    $actions = [];

    try {
      $hidden_gems_url = Url::fromRoute('view.upcoming_events.page_hidden_gems')->toString();
    }
    catch (\Throwable) {
      $hidden_gems_url = '';
    }
    if ($hidden_gems_url !== '') {
      $actions[] = [
        'key' => 'explore_hidden_gems',
        'label' => $this->readinessHelper->customerContinuityExploreHiddenGemsCta(),
        'url' => $hidden_gems_url,
        'variant' => 'secondary',
        'download' => FALSE,
      ];
    }

    try {
      $browse_url = Url::fromRoute('view.upcoming_events.page_events')->toString();
    }
    catch (\Throwable) {
      $browse_url = '';
    }
    if ($browse_url !== '') {
      $actions[] = [
        'key' => 'browse_events',
        'label' => $browse_more_label
          ? $this->readinessHelper->customerContinuityBrowseMoreEventsCta()
          : $this->readinessHelper->customerPrimaryBrowseEventsCta(),
        'url' => $browse_url,
        'variant' => 'secondary',
        'download' => FALSE,
      ];
    }

    return $actions;
  }

  /**
   * @param array<string, string> $labels
   *
   * @return array{type: string, heading: string, lead: string}
   */
  private function resolveCheckoutCompletionHero(bool $has_tickets, ?string $donation_total_formatted, array $labels, bool $is_paid = TRUE): array {
    $donation_display = ($donation_total_formatted ?? '') !== '';
    if ($donation_display && !$has_tickets) {
      return [
        'type' => 'donation',
        'heading' => $labels['thank_you_title'],
        'lead' => '',
      ];
    }
    if ($has_tickets) {
      // Confirmed booking language only when Commerce considers the order paid.
      // Pending-payment orders (e.g. manual/invoice gateway) get an honest
      // "order received" state rather than "booking confirmed".
      $h = $is_paid
        ? $this->readinessHelper->customerCheckoutCompletionHero()
        : $this->readinessHelper->customerCheckoutPendingPaymentHero();
      return [
        'type' => $is_paid ? 'tickets' : 'tickets_pending',
        'heading' => $h['heading'],
        'lead' => $h['lead'],
      ];
    }
    return [
      'type' => 'generic',
      'heading' => $labels['done_title'],
      'lead' => '',
    ];
  }

}
