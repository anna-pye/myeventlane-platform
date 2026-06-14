<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_core\MelStateEvaluation;

/**
 * Neutral readiness copy derived from interpretation results (no access gating).
 *
 * Canonical strings for vendor dashboard readiness surfaces, KPI empty hints, and
 * action-queue copy MUST flow through here to avoid drift from MELStateSystem wording.
 */
final class MelReadinessHelper {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * @param array<string, \Drupal\myeventlane_core\MelStateEvaluation> $evaluations
   *   Map of canonical state id to evaluation.
   * @param array<string, bool|int|string> $mergedSignals
   *   Workflow signals plus optional domain facts.
   *
   * @return list<string>
   */
  public function buildSummaries(MelSurfaceId $surface, array $evaluations, array $mergedSignals): array {
    $messages = [];

    if ($surface === MelSurfaceId::Vendor) {
      if (($evaluations['stripe_connected'] ?? NULL) === MelStateEvaluation::Unsatisfied) {
        $messages[] = $this->vendorStripeReadinessBlockingLine();
      }
      if (($evaluations['vendor_profile_completed'] ?? NULL) === MelStateEvaluation::Unsatisfied) {
        $messages[] = $this->vendorOrganiserProfileIncompleteLine();
      }
      if (($evaluations['first_event_ready'] ?? NULL) === MelStateEvaluation::Unsatisfied) {
        $messages[] = $this->vendorFirstEventReadinessLine();
      }
      if (($evaluations['publishable'] ?? NULL) === MelStateEvaluation::Unsatisfied
        && ($evaluations['moderation_hold'] ?? NULL) === MelStateEvaluation::Satisfied) {
        $messages[] = (string) $this->t('Publishing is paused while our team finishes reviewing this listing.');
      }
    }

    if ($surface === MelSurfaceId::Customer) {
      if (($evaluations['profile_completed'] ?? NULL) === MelStateEvaluation::Unsatisfied) {
        $messages[] = (string) $this->t('Add a few profile details to personalise your experience.');
      }
      if (($evaluations['attendee_complete'] ?? NULL) === MelStateEvaluation::Unsatisfied
        && !empty($mergedSignals['route.commerce_checkout'])) {
        $messages[] = $this->customerCheckoutAttendeeRequiredBeforePaymentLine();
      }
    }

    return $messages;
  }

  public function vendorStripeReadinessBlockingLine(): string {
    return (string) $this->t('Connect Stripe to accept card payments for paid tickets.');
  }

  public function vendorOrganiserProfileIncompleteLine(): string {
    return (string) $this->t('Finish your organiser profile to continue setup.');
  }

  public function vendorFirstEventReadinessLine(): string {
    return (string) $this->t('Complete your first event and ticket setup to be ready for sales.');
  }

  public function vendorFirstEventPublishingLine(): string {
    return (string) $this->t('Complete the missing details and publish when ready.');
  }

  public function vendorLifecycleDraftLabel(): string {
    return (string) $this->t('Draft');
  }

  public function vendorLifecycleUpcomingLabel(): string {
    return (string) $this->t('Upcoming');
  }

  public function vendorLifecyclePastLabel(): string {
    return (string) $this->t('Past');
  }

  /**
   * @return array<string, string>
   */
  public function vendorReadinessPresentationLabels(): array {
    return [
      'complete' => $this->vendorReadinessRowCompleteLabel(),
      'incomplete' => $this->vendorReadinessRowIncompleteLabel(),
      'profile' => (string) $this->t('Organiser profile'),
      'public_profile' => (string) $this->t('Public organiser page'),
      'stripe' => (string) $this->t('Stripe account'),
      'analytics_unavailable' => (string) $this->t('Analytics unavailable'),
    ];
  }

  public function vendorReadinessRowCompleteLabel(): string {
    return (string) $this->t('Complete');
  }

  public function vendorReadinessRowIncompleteLabel(): string {
    return (string) $this->t('Action required');
  }

  /**
   * @return array{title: string, message: string, action_label: string}
   */
  public function vendorActionNoVendorProfileStrings(): array {
    return [
      'title' => (string) $this->t('Complete your organiser setup'),
      'message' => (string) $this->t('Set up your organiser profile before creating or managing events.'),
      'action_label' => (string) $this->t('Set up profile'),
    ];
  }

  /**
   * @return array{title: string, message: string, action_label: string}
   */
  public function vendorActionProfileIncompleteStrings(): array {
    return [
      'title' => (string) $this->t('Complete your public profile'),
      'message' => $this->vendorOrganiserProfileIncompleteLine(),
      'action_label' => (string) $this->t('Edit profile'),
    ];
  }

  /**
   * @return array{title: string, message: string, action_label: string}
   */
  public function vendorActionMissingBookingStrings(): array {
    return [
      'title' => (string) $this->t('Add RSVP or tickets'),
      'message' => (string) $this->t('This event is visible but does not appear to have a booking option.'),
      'action_label' => (string) $this->t('Edit tickets'),
    ];
  }

  /**
   * @return array{title: string, message: string, action_label: string}
   */
  public function vendorActionStripePayoutStrings(bool $urgentForPaidSales): array {
    $message = $urgentForPaidSales
      ? $this->vendorStripeReadinessBlockingLine()
      : (string) $this->t('Connect payouts when you are ready to sell paid tickets.');
    return [
      'title' => (string) $this->t('Finish payout setup'),
      'message' => $message,
      'action_label' => (string) $this->t('Connect Stripe'),
    ];
  }

  /**
   * @return array{title: string, message: string, action_label: string}
   */
  public function vendorActionNoEventsStrings(): array {
    return [
      'title' => (string) $this->t('Create your first event'),
      'message' => $this->vendorFirstEventReadinessLine(),
      'action_label' => (string) $this->t('Create event'),
    ];
  }

  /**
   * @return array{title: string, message: string, action_label: string}
   */
  public function vendorActionDraftEventStrings(): array {
    return [
      'title' => (string) $this->t('Finish your draft event'),
      'message' => $this->vendorFirstEventPublishingLine(),
      'action_label' => (string) $this->t('Continue editing'),
    ];
  }

  /**
   * @return array{title: string, message: string, action_label: string}
   */
  public function vendorActionAnalyticsUnavailableStrings(): array {
    return [
      'title' => (string) $this->t('Analytics are not available'),
      'message' => (string) $this->t('Ticket sales are visible, but advanced analytics are not available for this account.'),
      'action_label' => (string) $this->t('View events'),
    ];
  }

  /**
   * @return array<string, string>
   */
  public function vendorHeroShellNeedsAttentionHint(): string {
    return (string) $this->t('Start with the most important task below.');
  }

  public function vendorHeroShellEventsComfortableHint(): string {
    return (string) $this->t('Your events are set up. Keep an eye on bookings and upcoming activity.');
  }

  public function vendorHeroShellLaunchHint(): string {
    return (string) $this->t('Create your first event and start taking RSVPs or selling tickets.');
  }

  public function vendorDashboardEmptyStrings(bool $eventsPresent): array {
    if (!$eventsPresent) {
      return [
        'hero_hint' => $this->vendorHeroShellLaunchHint(),
        'empty_title' => (string) $this->t('Welcome to your organiser dashboard'),
        'empty_message' => (string) $this->t('Create your first event to start selling tickets or collecting RSVPs.'),
        'empty_action_label' => (string) $this->t('Create event'),
        'neutral_empty_hint' => (string) $this->t('Your key setup and event tasks look clear.'),
      ];
    }
    return [
      'hero_hint' => $this->vendorHeroShellEventsComfortableHint(),
      'empty_title' => (string) $this->t('Welcome to your organiser dashboard'),
      'empty_message' => (string) $this->t('Use the event list and workspace tabs to manage ticket sales, RSVPs, and orders.'),
      'empty_action_label' => (string) $this->t('View events'),
      'neutral_empty_hint' => (string) $this->t('Your key setup and event tasks look clear.'),
    ];
  }

  /**
   * Fallback KPI narrative when aggregates fail (must stay non-sensitive).
   */
  public function vendorKpiUnavailableContextLine(): string {
    return (string) $this->t('Metrics temporarily unavailable');
  }

  /**
   * Shared organiser-portal guest wall title (analytics, events index, etc.).
   */
  public function vendorOrganiserPortalSignInTitle(): string {
    return (string) $this->t('Sign in with your organiser account');
  }

  /**
   * Guest/anonymous notice when analytics routes are reachable without a session.
   */
  public function vendorOrganiserPortalSignInAnalyticsBody(): string {
    return (string) $this->t('Analytics are available once you sign in as an organiser.');
  }

  /**
   * Guest/anonymous notice for the vendor events workspace entry.
   */
  public function vendorOrganiserPortalSignInEventsBody(): string {
    return (string) $this->t('Sign in as an organiser to view and manage your events.');
  }

  /**
   * Checkout reassurance when organiser attendee questions mandate the ticket-holder pane.
   */
  public function customerCheckoutAttendeeRequiredBeforePaymentLine(): string {
    return (string) $this->t('Ticket holder details are required before payment.');
  }

  public function customerCheckoutTrustChipsRegionLabel(): string {
    return (string) $this->t('Trust and protections');
  }

  public function customerCheckoutSidebarHelpAriaLabel(): string {
    return (string) $this->t('Help');
  }

  public function customerCheckoutTrustChipSecure(): string {
    return (string) $this->t('Secure checkout');
  }

  public function customerCheckoutTrustChipInstantConfirmation(): string {
    return (string) $this->t('Instant confirmation');
  }

  public function customerCheckoutTrustChipRefundsAvailable(): string {
    return (string) $this->t('Refunds available');
  }

  public function customerCheckoutTrustChipRefundsConditional(): string {
    return (string) $this->t('Easy refunds when the organiser enables them');
  }

  /**
   * @return list<array{text: string}>
   */
  public function customerCheckoutTrustChipsBuilt(bool $refunds_known_available): array {
    $chips = [
      ['text' => $this->customerCheckoutTrustChipSecure()],
      ['text' => $this->customerCheckoutTrustChipInstantConfirmation()],
    ];
    $chips[] = [
      'text' => $refunds_known_available
        ? $this->customerCheckoutTrustChipRefundsAvailable()
        : $this->customerCheckoutTrustChipRefundsConditional(),
    ];
    return $chips;
  }

  /**
   * @return array<string, string|bool>
   */
  public function customerCheckoutStructuredHero(bool $refunds_known_available, bool $requires_attendee_question_schema): array {
    return [
      'heading' => (string) $this->t('Checkout'),
      'intro_primary' => (string) $this->customerCheckoutStructuredIntroHeading(),
      'intro_secondary' => $requires_attendee_question_schema
        ? (string) $this->customerCheckoutStructuredIntroBodyAttendeeQuestions()
        : (string) $this->customerCheckoutStructuredIntroBodyLight(),
      'flow_helper' => (string) $this->customerCheckoutOnePageHelperLine(),
      'error_summary' => (string) $this->customerCheckoutErrorSummaryLine(),
    ];
  }

  public function customerCheckoutStructuredIntroHeading(): string {
    return (string) $this->t('Complete your booking');
  }

  public function customerCheckoutStructuredIntroBodyAttendeeQuestions(): string {
    return (string) $this->t("Complete each ticket holder's details on this page—including any organiser questions—then pay securely.");
  }

  public function customerCheckoutStructuredIntroBodyLight(): string {
    return (string) $this->t('Confirm your contact details and pay securely on this page—we will apply them to each ticket.');
  }

  public function customerCheckoutOnePageHelperLine(): string {
    return (string) $this->t('Your order stays on one secure checkout page. Totals refresh when checkout updates.');
  }

  public function customerCheckoutErrorSummaryLine(): string {
    return (string) $this->t('Please complete the required details to continue.');
  }

  /**
   * @return array{text: string, stripe_note: string, charge_timing: string}
   */
  public function customerCheckoutPaymentTrustLines(): array {
    return [
      'text' => (string) $this->t('Secure checkout powered by Stripe'),
      'stripe_note' => (string) $this->t('Secure payments via Stripe'),
      'charge_timing' => (string) $this->t('You will not be charged until you confirm your booking.'),
    ];
  }

  public function customerCheckoutCtaSecurePaymentNote(): string {
    return (string) $this->t('Your payment will be processed securely.');
  }

  public function customerCheckoutOrderSummaryAriaLabel(): string {
    return (string) $this->t('Order summary');
  }

  /**
   * @return array<string, string>
   */
  public function customerCheckoutStickySupportNav(): array {
    return [
      'region_label' => (string) $this->t('Help and support'),
      'support' => (string) $this->t('Something wrong? Contact support'),
      'help_centre' => (string) $this->t('Help Centre'),
      'support_hub' => (string) $this->t('Support'),
      'recovery_prompt' => (string) $this->t('Need help with this booking?'),
    ];
  }

  /**
   * @return array{secure: string, instant: string, calendar_hint: string}
   */
  public function customerCheckoutSidebarConfidenceLines(): array {
    return [
      'secure' => (string) $this->t('Secure checkout — payment processed via Stripe'),
      'instant' => (string) $this->t('Confirmation email sent as soon as you complete booking'),
      'calendar_hint' => (string) $this->t('Add to your calendar from My Tickets after booking'),
    ];
  }

  public function customerCheckoutBookingSummaryHeading(): string {
    return (string) $this->t('Booking summary');
  }

  public function customerCheckoutCompletionHeadline(): string {
    return (string) $this->t("Great choice. You're all set.");
  }

  /**
   * @return array{heading: string, lead: string}
   */
  public function customerCheckoutCompletionHero(): array {
    return [
      'heading' => (string) $this->customerCheckoutCompletionHeadline(),
      'lead' => (string) $this->t('Your tickets and receipt are on their way to your inbox.'),
    ];
  }

  /**
   * @return array{eyebrow: string, heading: string, intro: string}
   */
  public function customerCheckoutStepBuyer(): array {
    return [
      'eyebrow' => (string) $this->t('Step 1'),
      'heading' => (string) $this->t('Your details'),
      'intro' => (string) $this->t('Tell us where to send tickets plus anything the organiser needs before payment.'),
    ];
  }

  /**
   * @return array{eyebrow: string, heading: string, intro: string}
   */
  public function customerCheckoutStepAttendees(): array {
    return [
      'eyebrow' => (string) $this->t('Step 2'),
      'heading' => (string) $this->t('Ticket holders'),
      'intro' => (string) $this->t('Add each ticket holder once. Sections fold closed as you finish so the next ticket stays in focus.'),
    ];
  }

  /**
   * @return array{eyebrow: string, heading: string, intro: string}
   */
  public function customerCheckoutStepPayment(): array {
    return [
      'eyebrow' => (string) $this->t('Step 3'),
      'heading' => (string) $this->t('Payment'),
      'intro' => (string) $this->t('Review Stripe’s secure payment form, then complete your booking.'),
    ];
  }

  public function customerCheckoutPaymentReassuranceRegionLabel(): string {
    return (string) $this->t('Payment reassurance');
  }

  /**
   * @return array<string, string>
   */
  public function customerCheckoutStickyCtaAriaLabel(): array {
    return [
      'label' => (string) $this->t('Booking total and complete action'),
      'cta' => (string) $this->t('Complete booking'),
    ];
  }

  public function customerCheckoutOptionalDonationChip(): string {
    return (string) $this->t('Optional');
  }

  /**
   * Full label set for checkout order summary surfaces (sidebar, grouped, core summary).
   *
   * @return array<string, string>
   */
  public function customerCheckoutOrderSummarySurfaceLabels(): array {
    return [
      'summary_region' => $this->customerCheckoutOrderSummaryAriaLabel(),
      'pricing_breakdown_region' => (string) $this->t('Pricing breakdown'),
      'contribution_label' => (string) $this->t('Contribution'),
      'total_label' => (string) $this->t('Total'),
      'platform_fee_label' => (string) $this->t('Platform fee'),
      'platform_fee_absorbed_value' => (string) $this->t('Organiser absorbs'),
      'gst_note' => (string) $this->t('All prices include GST'),
      'jump_payment' => (string) $this->t('Jump to payment'),
      'tickets_table_caption' => (string) $this->t('Tickets and quantities'),
      'core_heading_visually_hidden' => (string) $this->t('Order summary'),
      'sidebar_visible_title' => (string) $this->t('Your order'),
      'title' => (string) $this->t('Your order'),
      'subtotal_label' => (string) $this->t('Subtotal'),
      'trust_footer_secure' => (string) $this->t('Secure payments via Stripe'),
      'trust_footer_instant' => (string) $this->t('Confirmation emailed instantly'),
      'trust_footer_refund_hint' => (string) $this->t('Refund policy and organiser rules are in the Help Centre.'),
      'event_fallback_title' => (string) $this->t('Event'),
      'additional_items_title' => (string) $this->t('Additional items'),
      'help_region_short' => (string) $this->t('Help'),
    ];
  }

  /**
   * Sticky CTA / paid-flow label when the order total is greater than zero.
   */
  public function customerCheckoutCtaYoullPayLabel(): string {
    return (string) $this->t("You'll pay");
  }

  /**
   * @return array<string, string>
   */
  public function customerCheckoutGroupedSummaryTitles(): array {
    $labels = $this->customerCheckoutOrderSummarySurfaceLabels();
    return [
      'title' => $labels['title'],
      'subtotal_label' => $labels['subtotal_label'],
      'trust_footer_secure' => $labels['trust_footer_secure'],
      'trust_footer_instant' => $labels['trust_footer_instant'],
    ];
  }

  public function customerCheckoutContributionSummaryGroupTitle(): string {
    return (string) $this->t('Contribution');
  }

  public function customerCheckoutContributionLineLabel(): string {
    return (string) $this->t('Optional contribution');
  }

  public function customerCheckoutTaxRowFallbackLabel(): string {
    return (string) $this->t('Tax');
  }

  public function customerCheckoutFeeRowFallbackLabel(): string {
    return (string) $this->t('Fees');
  }

  public function customerCheckoutConfirmationEmailSentLine(string $email): string {
    return (string) $this->t('Confirmation sent to @email.', ['@email' => $email]);
  }

  public function customerCheckoutOrderNumberLine(string $order_number): string {
    return (string) $this->t('Order #@number', ['@number' => $order_number]);
  }

  /**
   * @return array<string, string>
   */
  public function customerCheckoutCompletionPresentationLabels(): array {
    return [
      'thank_you_title' => (string) $this->t('Thank you'),
      'done_title' => (string) $this->t('Done'),
      'event_summary_eyebrow' => (string) $this->t('Event summary'),
      'your_tickets_title' => (string) $this->t('Your tickets'),
      'donation_confirmed_title' => (string) $this->t('Donation confirmed'),
      'view_ticket' => (string) $this->t('View ticket'),
      'view_order' => (string) $this->t('View order'),
      'view_event' => (string) $this->t('View event'),
      'add_to_calendar' => (string) $this->t('Add to calendar'),
      'google_calendar' => (string) $this->t('Google Calendar'),
      'outlook_calendar' => (string) $this->t('Outlook'),
      'guest_ticket_email_body' => (string) $this->t('Your ticket link and order details have been sent to your email.'),
      'guest_order_number_intro' => (string) $this->t('Order number:'),
      'what_next_title' => (string) $this->t('What happens next'),
      'what_next_body' => (string) $this->t('Keep this confirmation handy. The organiser may email closer to the date with practical details.'),
      'organiser_trust_title' => (string) $this->t('About your organiser'),
      'organiser_trust_body' => (string) $this->t('Events are hosted by independent organisers. For changes, refunds, or access questions, contact them directly from your tickets.'),
    ];
  }

  /**
   * @return array<string, string>
   */
  public function customerCartShellLabels(): array {
    $summary = $this->customerCheckoutOrderSummarySurfaceLabels();
    return [
      'page_title' => (string) $this->t('Your cart'),
      'item_singular' => (string) $this->t('item'),
      'items_plural' => (string) $this->t('items'),
      'order_summary_title' => (string) $this->t('Order summary'),
      'events_region' => (string) $this->t('Events in your cart'),
      'misc_items_note' => (string) $this->t('Some items are not linked to a single event — see the list below.'),
      'review_heading' => (string) $this->t('Review your tickets'),
      'review_intro' => (string) $this->t('You are almost there — check your details before checkout.'),
      'reassurance_secure' => $summary['trust_footer_secure'],
      'reassurance_no_hidden_fees' => (string) $this->t('No hidden fees'),
      'reassurance_instant' => $summary['trust_footer_instant'],
      'reassurance_refund_hint' => $summary['trust_footer_refund_hint'],
      'browse_events_cta' => (string) $this->t('Browse events'),
      'help_region_short' => (string) $this->t('Help and support'),
      'help_centre' => (string) $this->t('Help Centre'),
      'support' => (string) $this->t('Support'),
      'subtotal_label' => $summary['subtotal_label'],
      'total_label' => $summary['total_label'],
      'platform_fee_label' => $summary['platform_fee_label'],
      'platform_fee_absorbed_value' => $summary['platform_fee_absorbed_value'],
      'gst_note' => $summary['gst_note'],
      'cart_summary_trust_region' => (string) $this->t('Why you can trust this checkout'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCheckoutMissingOrderStateSlots(): array {
    return [
      'heading' => (string) $this->t('Confirmation is still loading'),
      'what_happened' => (string) $this->t('We could not connect this screen to a confirmed order reference.'),
      'why_empty' => (string) $this->t('Refreshing usually restores the summary once the booking record finishes saving.'),
      'next_action' => (string) $this->t('If this persists, contact support with your email and event link.'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCheckoutTicketHolderMinimalAutoAssignedSlots(): array {
    return [
      'heading' => (string) $this->t('No ticket holder step on this order'),
      'what_happened' => (string) $this->t('This checkout does not need separate per-ticket holder rows right now (for example, ticket quantity is zero on the holder-enabled lines).'),
      'why_empty' => (string) $this->t('When you do buy tickets that need names or organiser questions, those fields appear here automatically.'),
      'next_action' => (string) $this->t('Continue with your details and payment as usual.'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCheckoutEmptyCartSlots(): array {
    return [
      'heading' => (string) $this->t('Your cart is empty'),
      'what_happened' => (string) $this->t('Nothing is queued for checkout on this browser session yet.'),
      'why_empty' => (string) $this->t('Tickets are held in your cart briefly while you browse; they may clear if pricing or timing changes.'),
      'next_action' => (string) $this->t('Browse events and add tickets again when you find something you like.'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCheckoutPaymentUnavailableSlots(): array {
    return [
      'heading' => (string) $this->t('Payments are paused right now'),
      'what_happened' => (string) $this->t('We couldn’t activate a trusted payment route for your order.'),
      'why_empty' => (string) $this->t('This protects your card—it can happen briefly when gateways refresh or organisers adjust settings.'),
      'next_action' => (string) $this->t('Try refreshing in a minute, or reach our support crew with your event link handy.'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCheckoutTicketSoldOutSlots(): array {
    return [
      'heading' => (string) $this->t('This ticket tier just sold through'),
      'what_happened' => (string) $this->t('Inventory tightened while checkout was still open.'),
      'why_empty' => (string) $this->t('Paid inventory is authoritative—releases can shift quickly on popular listings.'),
      'next_action' => (string) $this->t('Return to the event page for other tiers or follow the organiser for more releases.'),
    ];
  }

  /**
   * Cart cleared when nothing purchasable remains (event-level sold through).
   *
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCheckoutCartSoldOutSlots(): array {
    return [
      'heading' => (string) $this->t('Nothing left to buy for that moment'),
      'what_happened' => (string) $this->t('The last tickets for this path sold through before checkout could finish.'),
      'why_empty' => (string) $this->t('We clear sold-through carts so totals and holds stay honest for the next person in line.'),
      'next_action' => (string) $this->t('Browse other events or check the listing again in case the organiser releases more.'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCheckoutCartRemovedUnavailableSlots(): array {
    return [
      'heading' => (string) $this->t('Something in your cart was updated'),
      'what_happened' => (string) $this->t('A ticket line was removed because it was no longer available at the listed price or quantity.'),
      'why_empty' => (string) $this->t('We keep paid inventory accurate so you are never charged for a tier that cannot be honoured.'),
      'next_action' => (string) $this->t('Review what is left in your cart, then add tickets again from the event page if you still need them.'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCheckoutExpiredSlots(): array {
    return [
      'heading' => (string) $this->t('This checkout expired'),
      'what_happened' => (string) $this->t('Timed holds on tickets can lapse for fairness and accuracy.'),
      'why_empty' => (string) $this->t('Starting again keeps totals, seating, or fees aligned with the organiser today.'),
      'next_action' => (string) $this->t('Head back to the event page and re-add tickets to continue.'),
    ];
  }

  /**
   * @return array<string, string>
   */
  public function customerCheckoutExpressSection(): array {
    return [
      'title' => (string) $this->t('Express checkout'),
      'intro' => (string) $this->t('Use Stripe Link, Apple Pay, or Google Pay when this device supports them.'),
      'multi_ticket' => (string) $this->t('Individual attendee tweaks stay available after purchase when you bought more than one ticket.'),
      'fallback' => (string) $this->t('Or continue with details'),
      'blocked' => (string) $this->t('Fast checkout is not offered when organiser attendee questions ask for more detail.'),
      'minimal_help' => (string) $this->t('This event only confirms basic attendee details before express checkout.'),
      'minimal_cta' => (string) $this->t('Continue with express checkout'),
      'minimal_name_label' => (string) $this->t('Name'),
      'minimal_email_label' => (string) $this->t('Email'),
    ];
  }

  public function customerCheckoutOptionalDonationHeading(): string {
    return (string) $this->t('Optional donation');
  }

  // --- Customer-facing confirmation & lifecycle (MELStateSystem vocabulary) ----

  public function customerRsvpConfirmationHeadline(): string {
    return (string) $this->t("You're in. See you there.");
  }

  /**
   * @param string $event_title
   *   Event label; already safe for display.
   */
  public function customerRsvpSpotConfirmedSubtitle(string $event_title): string {
    return (string) $this->t('Your spot is confirmed for @event.', [
      '@event' => $event_title,
    ]);
  }

  public function customerRsvpBookedAsMeta(string $name): string {
    return (string) $this->t('Booked as @name', ['@name' => $name]);
  }

  public function customerRsvpSpotsBookedMeta(int $guests): string {
    return (string) $this->t('You have booked @count spots', [
      '@count' => (string) $guests,
    ]);
  }

  public function customerRsvpDonationPendingTitle(): string {
    return (string) $this->t('You are supporting this event');
  }

  public function customerRsvpDonationPendingBody(string $amount_display): string {
    return (string) $this->t('You chose to contribute @amount. Complete your contribution to finish checkout.', [
      '@amount' => $amount_display,
    ]);
  }

  public function customerRsvpDonationProgressLabel(): string {
    return (string) $this->t('Almost done');
  }

  public function customerRsvpDonationReassuranceLine(): string {
    return (string) $this->t('Your RSVP is already confirmed.');
  }

  public function customerRsvpDonationCompleteTitle(): string {
    return (string) $this->t('Thank you for your support');
  }

  public function customerRsvpDonationCompleteBody(): string {
    return (string) $this->t('Your contribution has been completed.');
  }

  public function customerRsvpDonationCompleteBadge(): string {
    return (string) $this->t('Contribution complete');
  }

  public function customerRsvpDonationUpsellTitle(): string {
    return (string) $this->t('Support this event');
  }

  public function customerRsvpDonationUpsellBody(): string {
    return (string) $this->t('This event is community-powered. Add a small contribution if you would like to support it.');
  }

  public function customerRsvpStatusDefaultConfirmed(): string {
    return (string) $this->t('Your RSVP is confirmed.');
  }

  public function customerRsvpStatusPaymentReceivedConfirmed(): string {
    return (string) $this->t('Payment received. Your RSVP is confirmed.');
  }

  public function customerRsvpStatusCheckoutIncomplete(): string {
    return (string) $this->t('Checkout not completed. Your RSVP is saved but not confirmed.');
  }

  public function customerContinuityViewEventCta(): string {
    return (string) $this->t('View event');
  }

  public function customerContinuityAddToCalendarCta(): string {
    return (string) $this->t('Add to calendar');
  }

  public function customerContinuityCancelRsvpCta(): string {
    return (string) $this->t('Cancel RSVP');
  }

  public function customerContinuityCompleteContributionCta(string $amount_display): string {
    return (string) $this->t('Complete your @amount contribution', [
      '@amount' => $amount_display,
    ]);
  }

  public function customerContinuityAddContributionCta(): string {
    return (string) $this->t('Add a contribution');
  }

  public function customerContinuityExploreHiddenGemsCta(): string {
    return (string) $this->t('Explore Hidden Gems');
  }

  public function customerContinuityBrowseMoreEventsCta(): string {
    return (string) $this->t('Browse more events');
  }

  /**
   * Governed Commerce order state wording for customer self-service (not raw workflow ids).
   *
   * @param string $fallback_label
   *   Typically the translated state label from Commerce (used only when unknown).
   */
  public function customerCommerceOrderStateLabel(string $state_id, string $fallback_label): string {
    $id = strtolower($state_id);
    return match ($id) {
      'completed', 'fulfilled', 'fulfillment' => (string) $this->t('Confirmed'),
      'placed' => (string) $this->t('Confirmed'),
      'canceled', 'cancelled' => (string) $this->t('Cancelled'),
      'draft' => (string) $this->t('Pending'),
      'refunded' => (string) $this->t('Refunded'),
      default => $fallback_label !== '' ? $fallback_label : (string) $this->t('In progress'),
    };
  }

  /**
   * Primary customer CTA label for browsing discoverable events (single vocabulary).
   */
  public function customerPrimaryBrowseEventsCta(): string {
    return (string) $this->t('Browse events');
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerMyTicketsOverviewEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No ticket orders to show'),
      'what_happened' => (string) $this->t('We are not showing any completed ticket purchases on this account yet.'),
      'why_empty' => (string) $this->t('Orders appear here when you are signed in as the same user who completed checkout, or when guest checkout used this email address.'),
      'next_action' => (string) $this->t('Explore Hidden Gems or browse discoverable events while you wait for your first booking.'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCategoryFollowEmptySlots(): array {
    return [
      'heading' => (string) $this->t('You are not following any categories yet'),
      'what_happened' => (string) $this->t('Followed categories collect new listings here so you can see what is fresh for you.'),
      'why_empty' => (string) $this->t('Use Follow on category pages you care about to build this list.'),
      'next_action' => (string) $this->t('Start from discoverable events and follow the categories you want to track.'),
    ];
  }

  /**
   * Per-category “quiet week” continuation (My Categories list is non-empty).
   *
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCategoryFollowWeeklyQuietSlots(): array {
    return [
      'heading' => (string) $this->t('Nothing new here this week'),
      'what_happened' => (string) $this->t('There are no new listings in this category for the past seven days.'),
      'why_empty' => '',
      'next_action' => (string) $this->t('Check back soon, or browse discoverable events when you want more options.'),
    ];
  }

  /**
   * Combined ticket + RSVP “My Events” dashboard when there are no rows.
   *
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerMyEventsDashboardUpcomingEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No upcoming events yet'),
      'what_happened' => (string) $this->t('You do not have any upcoming ticket purchases or RSVPs on this account yet.'),
      'why_empty' => (string) $this->t('Tickets and RSVPs appear after you finish booking for this signed-in account (or a matching guest checkout email).'),
      'next_action' => (string) $this->t('Browse events and save something you want to attend.'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string, cta_label: string}
   */
  public function customerAccountDashboardTicketsEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No upcoming tickets'),
      'what_happened' => (string) $this->t('You do not have any upcoming ticket purchases on this account yet.'),
      'why_empty' => (string) $this->t('Purchases appear after checkout completes for this signed-in user (or matching guest email).'),
      'next_action' => (string) $this->t('Browse events and book in a few taps when you are ready.'),
      'cta_label' => (string) $this->t('Find events'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string, cta_label: string}
   */
  public function customerAccountDashboardRsvpsEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No RSVPs yet'),
      'what_happened' => (string) $this->t('You do not have any upcoming RSVPs on this account yet.'),
      'why_empty' => (string) $this->t('RSVPs appear here after you confirm a spot for free community events.'),
      'next_action' => (string) $this->t('Browse events and save your spot when you find something you like.'),
      'cta_label' => $this->customerPrimaryBrowseEventsCta(),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerAccountDashboardPastPreviewEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No past events yet'),
      'what_happened' => (string) $this->t('Once you have attended something, it will show in this preview and your full past list.'),
      'why_empty' => '',
      'next_action' => '',
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string, cta_label: string}
   */
  public function customerAccountPastEventsPageEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No past events yet'),
      'what_happened' => (string) $this->t('Past events you have attended will appear here once they are recorded on this account.'),
      'why_empty' => '',
      'next_action' => (string) $this->t('Browse events to plan your next outing.'),
      'cta_label' => $this->customerPrimaryBrowseEventsCta(),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string, cta_label: string}
   */
  public function customerAccountDashboardSavedEventsEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No saved events yet'),
      'what_happened' => (string) $this->t('Save events to find them later.'),
      'why_empty' => '',
      'next_action' => (string) $this->t('Tap Save on an event page when something catches your eye.'),
      'cta_label' => $this->customerPrimaryBrowseEventsCta(),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string, cta_label: string}
   */
  public function customerSavedEventsPageEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No saved events yet'),
      'what_happened' => (string) $this->t('Save events to find them later.'),
      'why_empty' => '',
      'next_action' => (string) $this->t('Browse upcoming events and tap Save when you want to come back.'),
      'cta_label' => $this->customerPrimaryBrowseEventsCta(),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string, cta_label: string}
   */
  public function customerCategoriesFollowEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No followed categories yet'),
      'what_happened' => (string) $this->t('Follow categories to personalise your event feed.'),
      'why_empty' => '',
      'next_action' => (string) $this->t('Open a category you like and choose Follow to hear when new events land.'),
      'cta_label' => $this->customerPrimaryBrowseEventsCta(),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function customerCategoriesNoNewEventsThisWeekSlots(): array {
    return [
      'heading' => (string) $this->t('No new events this week'),
      'what_happened' => (string) $this->t('When organisers publish something new in this category, it will show up here.'),
      'why_empty' => '',
      'next_action' => (string) $this->t('You can still browse all events in this category from its page.'),
    ];
  }

  /**
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string, cta_label: string}
   */
  public function customerFollowedOrganisersEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No followed organisers yet'),
      'what_happened' => (string) $this->t('Follow organisers you love to hear about future events.'),
      'why_empty' => '',
      'next_action' => (string) $this->t('Visit an organiser profile and tap Follow to add them here.'),
      'cta_label' => $this->customerPrimaryBrowseEventsCta(),
    ];
  }

  // --- MELIntelligenceSystem presentation (centralised vocabulary) -----------------

  /**
   * Theme variables for mel_intelligence_panel — no translatable literals in Twig.
   *
   * @return array{
   *   intelligence_region_heading: string,
   *   explainability_summary_label: string,
   *   explainability_signals_label: string,
   *   insights_aria_label: string,
   *   dismiss_hint_template: string
   * }
   */
  public function intelligencePanelThemeVariables(MelSurfaceId $surface, bool $public_checkout_storytelling_focus = FALSE): array {
    $staff = $surface === MelSurfaceId::Staff;
    $public_story = $surface === MelSurfaceId::Public && $public_checkout_storytelling_focus;
    return [
      'intelligence_region_heading' => (string) $this->intelligencePanelRegionHeading($surface, $public_story),
      'explainability_summary_label' => $staff ? (string) $this->intelligenceStaffExplainabilitySummaryLabel() : '',
      'explainability_signals_label' => $staff ? (string) $this->intelligenceStaffSignalsReferenceLabel() : '',
      'insights_aria_label' => $staff
        ? (string) $this->intelligenceInsightsAriaLabelStaff()
        : (string) $this->intelligenceInsightsAriaLabelProductSurfaces($surface, $public_story),
      'dismiss_hint_template' => (string) $this->intelligenceDismissHintTemplate($surface, $public_story),
    ];
  }

  public function intelligencePanelRegionHeading(MelSurfaceId $surface, bool $public_checkout_storytelling_focus = FALSE): string {
    if ($surface === MelSurfaceId::Public && $public_checkout_storytelling_focus) {
      return (string) $this->t('Before you confirm');
    }
    return (string) $this->t('Suggestions for your next step');
  }

  /**
   * Staff-only summary label for diagnostics (never shown on public tiers).
   */
  public function intelligenceStaffExplainabilitySummaryLabel(): string {
    return (string) $this->t('Diagnostic context (staff)');
  }

  /**
   * Staff-only prefix before workflow input ids (still no “signals evaluated” wording).
   */
  public function intelligenceStaffSignalsReferenceLabel(): string {
    return (string) $this->t('Referenced workflow inputs');
  }

  public function intelligenceInsightsAriaLabelStaff(): string {
    return (string) $this->t('Staff operational notes');
  }

  public function intelligenceInsightsAriaLabelProductSurfaces(MelSurfaceId $surface, bool $public_checkout_storytelling_focus = FALSE): string {
    if ($surface === MelSurfaceId::Public && $public_checkout_storytelling_focus) {
      return (string) $this->t('Checkout reminders');
    }
    return match ($surface) {
      MelSurfaceId::Public => (string) $this->t('Ideas while you browse'),
      MelSurfaceId::Vendor => (string) $this->t('Quick notes for organisers'),
      default => (string) $this->t('Helpful reminders while you browse'),
    };
  }

  public function intelligenceDismissHintTemplate(MelSurfaceId $surface, bool $public_checkout_storytelling_focus = FALSE): string {
    return match ($surface) {
      MelSurfaceId::Staff => '',
      MelSurfaceId::Public => $public_checkout_storytelling_focus
        ? ''
        : (string) $this->t('Not interested? Skip this anytime — browsing stays the same.'),
      MelSurfaceId::Vendor => (string) $this->t('Dismiss if it is not useful right now; you can revisit these shortcuts later.'),
      default => (string) $this->t('Dismiss when you like — nothing changes unless you choose a next step.'),
    };
  }

  /**
   * @return array{headline: string, action: string}
   */
  public function intelligenceRecommendationCopy(string $definition_id, MelSurfaceId $surface): array {
    return match ($definition_id) {
      'recommended_events' => [
        'headline' => (string) $this->t('Worth exploring next'),
        'action' => (string) $this->t('Discover more events popular with the community on MyEventLane.'),
      ],
      'continue_saved_event' => [
        'headline' => (string) $this->t('Pick up where you left off'),
        'action' => (string) $this->t('Jump back in from your account area or the link you saved earlier.'),
      ],
      'complete_profile_prompt' => [
        'headline' => (string) $this->t('Polish your profile'),
        'action' => (string) $this->t('A few details make checkout quicker and recommendations feel more natural.'),
      ],
      'add_to_calendar_prompt' => [
        'headline' => (string) $this->t('Keep the date handy'),
        'action' => (string) $this->t('Add this event to your calendar from the download or share tools on the page.'),
      ],
      'revisit_recent_event' => [
        'headline' => (string) $this->t('Something you recently opened'),
        'action' => (string) $this->t('Find tickets, QR codes, and order details from your account whenever you need them.'),
      ],
      'support_followup_prompt' => [
        'headline' => (string) $this->t('Need a hand?'),
        'action' => (string) $this->t('Pick up any open help requests from the support entry points in your account.'),
      ],
      'complete_vendor_setup' => [
        'headline' => (string) $this->t('Finish organiser setup'),
        'action' => (string) $this->t('Complete the remaining onboarding steps so ticket sales and RSVPs stay smooth.'),
      ],
      'connect_payouts' => [
        'headline' => (string) $this->t('Finish payout readiness'),
        'action' => (string) $this->t('Connect Stripe and finish verification before you take paid orders.'),
      ],
      'publish_event_prompt' => [
        'headline' => (string) $this->t('Ready to go live'),
        'action' => (string) $this->t('Publish once your listing looks right and any moderation checks are clear.'),
      ],
      'boost_event_prompt' => [
        'headline' => (string) $this->t('Reach more people'),
        'action' => (string) $this->t('When you are eligible, explore boost options to put this event in front of new fans.'),
      ],
      'low_ticket_sales_prompt' => [
        'headline' => (string) $this->t('Give ticket sales a nudge'),
        'action' => (string) $this->t('Review pricing, timing, and how you describe the experience — small tweaks can lift momentum.'),
      ],
      'attendee_engagement_prompt' => [
        'headline' => (string) $this->t('Stay close to your attendees'),
        'action' => (string) $this->t('Use your organiser messaging tools for timely updates when you are ready.'),
      ],
      'analytics_review_prompt' => [
        'headline' => (string) $this->t('See how things are performing'),
        'action' => (string) $this->t('Open your analytics workspace for a clear read on sales and engagement.'),
      ],
      'complete_attendee_details' => [
        'headline' => (string) $this->t('Finish attendee details'),
        'action' => (string) $this->t('We need a few attendee fields before you can continue to payment.'),
      ],
      'retry_checkout' => [
        'headline' => (string) $this->t('Checkout paused'),
        'action' => (string) $this->t('Use the secure resume link on this page to try again without starting over.'),
      ],
      'add_wallet_prompt' => [
        'headline' => (string) $this->t('Keep tickets on your phone'),
        'action' => (string) $this->t('Add passes to Apple Wallet or Google Wallet when your device supports it.'),
      ],
      'share_event_prompt' => [
        'headline' => (string) $this->t('Share the word'),
        'action' => (string) $this->t('Invite friends with the share tools on this page when you would like company.'),
      ],
      'order_followup_prompt' => [
        'headline' => (string) $this->t('Everything about this order'),
        'action' => (string) $this->t('Review tickets, receipts, and help links for this purchase in one place.'),
      ],
      'draft_completion_prompt' => [
        'headline' => (string) $this->t('Polish your draft'),
        'action' => (string) $this->t('Head back to the event editor to finish the sections still marked as needed.'),
      ],
      'sold_out_momentum_prompt' => [
        'headline' => (string) $this->t('Fully booked'),
        'action' => (string) $this->t('Consider waitlists, follow-up events, or partner listings when you are ready for the next chapter.'),
      ],
      'cancelled_event_followup' => [
        'headline' => (string) $this->t('This event was cancelled'),
        'action' => (string) $this->t('Check your email and order history for refunds or rescheduled details from the organiser.'),
      ],
      'moderation_recovery_prompt' => [
        'headline' => (string) $this->t('Moderation is reviewing this listing'),
        'action' => (string) $this->t('Follow the guidance in your organiser tools until the review wraps up.'),
      ],
      're_engagement_prompt' => [
        'headline' => (string) $this->t('Welcome back'),
        'action' => (string) $this->t('Choose a simple next step from your dashboard to keep plans moving.'),
      ],
      'incomplete_onboarding_prompt' => [
        'headline' => (string) $this->t('Finish organiser onboarding'),
        'action' => (string) $this->t('Complete the remaining setup tasks so publishing and payouts stay straightforward.'),
      ],
      'incomplete_customer_onboarding_prompt' => [
        'headline' => (string) $this->t('Wrap up your starter checklist'),
        'action' => (string) $this->t('Finish the short getting-started flow when you have a minute — it keeps future bookings easy.'),
      ],
      'return_to_platform_prompt' => [
        'headline' => (string) $this->t('Continue your plans'),
        'action' => (string) $this->t('Pick up saved events, tickets, and RSVPs from your account whenever you are ready.'),
      ],
      'trust_building_prompt' => [
        'headline' => (string) $this->t('How MyEventLane protects you'),
        'action' => (string) $this->t('Read about trust, safety, and refunds on your own schedule — no pressure.'),
      ],
      'staff_trust_escalation_diagnostic' => [
        'headline' => (string) $this->t('Escalation review is active (staff)'),
        'action' => (string) $this->t('Work the case in staff escalation tools; keep customer messaging separate from this panel.'),
      ],
      'staff_moderation_hold_diagnostic' => [
        'headline' => (string) $this->t('Moderation hold is active (staff)'),
        'action' => (string) $this->t('Use moderation queues; never paste internal notes into customer-facing surfaces.'),
      ],
      'public_discovery_events_hint' => [
        'headline' => (string) $this->t('Discover local events'),
        'action' => (string) $this->t('Browse what is happening nearby and create an account only when you are ready to book.'),
      ],
      default => $this->intelligenceFallbackRecommendation($surface),
    };
  }

  /**
   * @return array{headline: string, action: string}
   */
  public function intelligenceFallbackRecommendation(MelSurfaceId $surface): array {
    return match ($surface) {
      MelSurfaceId::Public => [
        'headline' => (string) $this->t('Events worth a look'),
        'action' => (string) $this->t('Browse listings at your pace — you can save favourites before you sign in.'),
      ],
      MelSurfaceId::Customer, MelSurfaceId::Auth => [
        'headline' => (string) $this->t('One clear next step'),
        'action' => (string) $this->t('Use the buttons on this page to move tickets, RSVPs, or profile tasks forward.'),
      ],
      MelSurfaceId::Vendor => [
        'headline' => (string) $this->t('Keep your event on track'),
        'action' => (string) $this->t('Use the workspace actions above to finish setup, publish, or follow up with attendees.'),
      ],
      MelSurfaceId::Staff => [
        'headline' => (string) $this->t('Internal guidance'),
        'action' => (string) $this->t('Reference staff tooling for this route; this text is diagnostic, not customer copy.'),
      ],
    };
  }

  public function eventStudioIntelligenceEmptyHeading(): string {
    return (string) $this->t('No tips to show right now');
  }

  public function eventStudioIntelligenceEmptyBody(): string {
    return (string) $this->t('Keep editing your event — we will surface timely suggestions when they help.');
  }

  public function eventStudioFieldTipsEmptyHeading(): string {
    return (string) $this->t('No field tips yet');
  }

  // -------------------------------------------------------------------------
  // Operational readiness intelligence.
  // Presentation-only summaries for vendor surfaces. Business rules remain in
  // the existing state, publishing, Stripe, Commerce, RSVP, and attendee layers.
  // -------------------------------------------------------------------------

  /**
   * @param list<array<string, mixed>> $events
   *
   * @return list<array{key: string, label: string, message: string, severity: string}>
   */
  public function vendorDashboardOperationalReadinessSummary(array $readiness, array $events): array {
    $published = 0;
    $draft = 0;
    $paidOrHybrid = FALSE;
    $bookingReady = FALSE;
    $attendeeActivity = FALSE;
    $promotionReady = FALSE;

    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      $status = (string) ($event['status'] ?? '');
      if ($status === 'draft') {
        $draft++;
      }
      elseif ($status === 'upcoming' || $status === 'past' || $status === 'published') {
        $published++;
      }

      $eventType = (string) ($event['event_type'] ?? 'unknown');
      if ($eventType !== 'unknown') {
        $bookingReady = TRUE;
      }
      if ($eventType === 'paid' || $eventType === 'both') {
        $paidOrHybrid = TRUE;
      }

      if (!empty($event['metric_label'])) {
        $attendeeActivity = TRUE;
      }

      $image = $event['image'] ?? [];
      if (is_array($image) && !empty($image['url']) && empty($image['is_placeholder'])) {
        $promotionReady = TRUE;
      }
    }

    $stripeReady = (bool) ($readiness['stripe_ready'] ?? FALSE);

    return [
      [
        'key' => 'publishing',
        'label' => (string) $this->t('Publishing'),
        'message' => $published > 0
          ? (string) $this->t('Your event is live and ready for bookings.')
          : ($draft > 0
            ? (string) $this->t("Your draft is saved and won't appear publicly until you publish.")
            : (string) $this->t('Create your first event when you are ready.')),
        'severity' => $published > 0 ? 'success' : 'info',
      ],
      [
        'key' => 'tickets_rsvp',
        'label' => (string) $this->t('Tickets & RSVP'),
        'message' => $bookingReady
          ? (string) $this->t('People have a clear way to book or RSVP.')
          : (string) $this->t('Add ticket types or RSVP settings before you rely on bookings.'),
        'severity' => $bookingReady ? 'success' : 'warning',
      ],
      [
        'key' => 'payments_payouts',
        'label' => (string) $this->t('Payments & payouts'),
        'message' => $stripeReady
          ? (string) $this->t('Payout setup is connected for paid ticket activity.')
          : ($paidOrHybrid
            ? $this->vendorStripeReadinessBlockingLine()
            : (string) $this->t("Connect payouts when you're ready to sell paid tickets.")),
        'severity' => $stripeReady ? 'success' : ($paidOrHybrid ? 'warning' : 'info'),
      ],
      [
        'key' => 'attendees',
        'label' => (string) $this->t('Attendee readiness'),
        'message' => $attendeeActivity
          ? (string) $this->t('Attendee activity is flowing into your organiser tools.')
          : (string) $this->t('Your attendees will appear here after bookings begin.'),
        'severity' => $attendeeActivity ? 'success' : 'info',
      ],
      [
        'key' => 'promotion',
        'label' => (string) $this->t('Promotion & discovery'),
        'message' => $promotionReady
          ? (string) $this->t('Your listing has visual content that can help it stand out.')
          : (string) $this->t('Add a banner image to help your event stand out in discovery.'),
        'severity' => $promotionReady ? 'success' : 'info',
      ],
    ];
  }

  /**
   * @return list<array{key: string, message: string, severity: string}>
   */
  public function vendorDashboardFirstEventGuidance(bool $has_published_events, bool $has_ticket_sales, bool $stripe_ready): array {
    if ($has_published_events || $has_ticket_sales || $stripe_ready) {
      return [];
    }
    return [
      [
        'key' => 'create_first_event',
        'message' => (string) $this->t('Create your first event.'),
        'severity' => 'info',
      ],
      [
        'key' => 'add_booking_mode',
        'message' => (string) $this->t('Add tickets or RSVPs so people know how to join.'),
        'severity' => 'info',
      ],
      [
        'key' => 'connect_payouts_when_ready',
        'message' => (string) $this->t("Connect payouts when you're ready to sell paid tickets."),
        'severity' => 'info',
      ],
    ];
  }

  /**
   * Builds lifecycle guidance from dashboard-ready event rows.
   *
   * @param list<array<string, mixed>> $events
   *
   * @return array{heading: string, intro: string, items: list<array{key: string, stage: string, message: string, severity: string}>}
   */
  public function vendorDashboardLifecycleSummary(array $readiness, array $events, bool $analytics_available): array {
    $hasEvents = $events !== [];
    $hasPublished = FALSE;
    $hasDraft = FALSE;
    $hasBooking = FALSE;
    $hasActivity = FALSE;
    $hasVisualContent = FALSE;
    $hasPromotedEvent = FALSE;

    foreach ($events as $event) {
      if (!is_array($event)) {
        continue;
      }
      $status = (string) ($event['status'] ?? '');
      $hasPublished = $hasPublished || in_array($status, ['upcoming', 'published', 'past'], TRUE);
      $hasDraft = $hasDraft || $status === 'draft';

      $eventType = (string) ($event['event_type'] ?? 'unknown');
      $hasBooking = $hasBooking || $eventType !== 'unknown';
      $hasActivity = $hasActivity || !empty($event['metric_label']);

      $image = $event['image'] ?? [];
      $hasVisualContent = $hasVisualContent || (is_array($image) && !empty($image['url']) && empty($image['is_placeholder']));
      $hasPromotedEvent = $hasPromotedEvent || !empty($event['is_promoted']);
    }

    $stripeReady = (bool) ($readiness['stripe_ready'] ?? FALSE);

    return [
      'heading' => (string) $this->t('Lifecycle guidance'),
      'intro' => (string) $this->t('Helpful context based on the organiser, event, booking, attendee, and discovery signals already shown here.'),
      'items' => [
        [
          'key' => 'event_setup',
          'stage' => (string) $this->t('Event Setup'),
          'message' => $hasEvents
            ? (string) $this->t('Your event workspace is ready for setup, publishing, and attendee follow-up.')
            : (string) $this->t('Create your first event to start adding details, RSVPs, or tickets.'),
          'severity' => $hasEvents ? 'success' : 'info',
        ],
        [
          'key' => 'publishing_readiness',
          'stage' => (string) $this->t('Publishing Readiness'),
          'message' => $hasPublished
            ? (string) $this->t('At least one event is visible to attendees.')
            : ($hasDraft
              ? (string) $this->t('Your draft event stays private until you publish it.')
              : (string) $this->t('Publishing guidance will appear after you create an event.')),
          'severity' => $hasPublished ? 'success' : 'info',
        ],
        [
          'key' => 'booking_readiness',
          'stage' => (string) $this->t('Booking Readiness'),
          'message' => $hasBooking
            ? (string) $this->t('RSVP or ticket setup is present on at least one event.')
            : (string) $this->t('Add RSVP settings or ticket types when you are ready to accept bookings.'),
          'severity' => $hasBooking ? 'success' : 'info',
        ],
        [
          'key' => 'discovery_readiness',
          'stage' => (string) $this->t('Discovery Readiness'),
          'message' => $hasVisualContent
            ? ($hasPromotedEvent
              ? (string) $this->t('Your event has visual content and an active promoted placement signal.')
              : (string) $this->t('Your event has visual content that can support discovery and sharing.'))
            : (string) $this->t('Adding a banner image can help your event stand out in discovery.'),
          'severity' => $hasVisualContent ? 'success' : 'info',
        ],
        [
          'key' => 'attendee_activity',
          'stage' => (string) $this->t('Attendee Activity'),
          'message' => $hasActivity
            ? (string) $this->t('Your event has active bookings or RSVPs in organiser tools.')
            : (string) $this->t('Bookings will appear here after attendees reserve tickets or RSVP.'),
          'severity' => $hasActivity ? 'success' : 'info',
        ],
        [
          'key' => 'event_momentum',
          'stage' => (string) $this->t('Event Momentum'),
          'message' => $analytics_available
            ? (string) $this->t('Analytics are available from the organiser dashboard when you want to review activity.')
            : ($stripeReady
              ? (string) $this->t('Your payout setup is ready for paid ticket activity when your events use paid tickets.')
              : (string) $this->t('Connect Stripe only when you plan to sell paid tickets.')),
          'severity' => 'info',
        ],
      ],
    ];
  }

  /**
   * @return list<array{key: string, label: string, message: string, severity: string}>
   */
  public function vendorEventWorkspaceOperationalSummary(string $event_type, string $status, int $tickets_sold, int $orders_count, int $rsvp_count): array {
    $isPublished = $status === 'published' || $status === 'past' || $status === 'upcoming';
    $hasBooking = $event_type !== 'unknown';
    $hasActivity = $tickets_sold > 0 || $orders_count > 0 || $rsvp_count > 0;
    $isPaid = $event_type === 'paid' || $event_type === 'both';

    return [
      [
        'key' => 'visibility',
        'label' => (string) $this->t('Public visibility'),
        'message' => $isPublished
          ? (string) $this->t('This event page is visible to attendees.')
          : (string) $this->t("This event is still in draft and won't appear publicly."),
        'severity' => $isPublished ? 'success' : 'warning',
      ],
      [
        'key' => 'booking',
        'label' => (string) $this->t('Booking setup'),
        'message' => $hasBooking
          ? (string) $this->t('Attendees have a booking path for this event.')
          : (string) $this->t('Add ticket types or RSVP settings before publishing.'),
        'severity' => $hasBooking ? 'success' : 'warning',
      ],
      [
        'key' => 'paid_tickets',
        'label' => (string) $this->t('Paid ticket readiness'),
        'message' => $isPaid
          ? (string) $this->t('Paid ticket setup uses the existing Stripe and Commerce publish checks.')
          : (string) $this->t('Paid tickets are not enabled for this event.'),
        'severity' => $isPaid ? 'info' : 'neutral',
      ],
      [
        'key' => 'attendees',
        'label' => (string) $this->t('Attendees'),
        'message' => $hasActivity
          ? (string) $this->t('Bookings are visible in attendee and check-in tools.')
          : (string) $this->t('Ticket holders will appear here after bookings begin.'),
        'severity' => $hasActivity ? 'success' : 'info',
      ],
      [
        'key' => 'day_of_event',
        'label' => (string) $this->t('Day-of-event readiness'),
        'message' => (string) $this->t('Use check-in during your event to validate tickets.'),
        'severity' => 'info',
      ],
    ];
  }

  /**
   * Builds event-scoped lifecycle guidance from existing workspace signals.
   *
   * @return array{heading: string, intro: string, items: list<array{key: string, stage: string, message: string, severity: string}>}
   */
  public function vendorEventWorkspaceLifecycleSummary(string $event_type, string $status, int $tickets_sold, int $orders_count, int $rsvp_count, bool $has_banner, bool $has_category, bool $has_tags, bool $is_promoted): array {
    $isPublished = $status === 'published' || $status === 'past' || $status === 'upcoming';
    $hasBooking = $event_type !== 'unknown';
    $hasActivity = $tickets_sold > 0 || $orders_count > 0 || $rsvp_count > 0;
    $hasDiscoveryTaxonomy = $has_category || $has_tags;
    $isPast = $status === 'past';

    return [
      'heading' => (string) $this->t('Lifecycle guidance'),
      'intro' => (string) $this->t('Contextual notes that explain this event state without adding new rules or alerts.'),
      'items' => [
        [
          'key' => 'launch_readiness',
          'stage' => (string) $this->t('Launch Readiness'),
          'message' => $isPublished
            ? (string) $this->t('Your event is visible to attendees.')
            : (string) $this->t('This event is still in draft. Publish when your details and booking setup are ready.'),
          'severity' => $isPublished ? 'success' : 'info',
        ],
        [
          'key' => 'booking_readiness',
          'stage' => (string) $this->t('Booking Readiness'),
          'message' => $hasBooking
            ? ($event_type === 'rsvp'
              ? (string) $this->t('RSVPs are active for this event.')
              : ($event_type === 'paid'
                ? (string) $this->t('Paid tickets use the existing Stripe and Commerce publish checks.')
                : (string) $this->t('RSVPs and paid tickets are active for this event.')))
            : (string) $this->t('Ticket sales and RSVPs are not currently enabled.'),
          'severity' => $hasBooking ? 'success' : 'warning',
        ],
        [
          'key' => 'discovery_readiness',
          'stage' => (string) $this->t('Discovery Readiness'),
          'message' => $has_banner && $hasDiscoveryTaxonomy
            ? ($is_promoted
              ? (string) $this->t('Your event has discovery details and an active promoted placement signal.')
              : (string) $this->t('Your banner image and category or tag details can support discovery.'))
            : ($has_banner
              ? (string) $this->t('Adding categories or tags can help people understand where this event fits.')
              : (string) $this->t('Adding a banner image can help your event stand out in discovery.')),
          'severity' => ($has_banner && $hasDiscoveryTaxonomy) ? 'success' : 'info',
        ],
        [
          'key' => 'attendee_momentum',
          'stage' => (string) $this->t('Attendee Momentum'),
          'message' => $hasActivity
            ? (string) $this->t('Your event already has bookings. Check-in tools are ready when you need them.')
            : (string) $this->t('Bookings will appear here after attendees reserve tickets or RSVP.'),
          'severity' => $hasActivity ? 'success' : 'info',
        ],
        [
          'key' => 'post_event_followup',
          'stage' => (string) $this->t('Post-Event Follow-up'),
          'message' => $isPast
            ? (string) $this->t('Your event has ended. Ticket holders and RSVP responses remain available from this workspace.')
            : (string) $this->t('After the event, attendee activity remains available from your organiser dashboard and event workspace.'),
          'severity' => $isPast ? 'neutral' : 'info',
        ],
      ],
    ];
  }

  /**
   * @param array{terms: bool, profile: bool, stripe: bool} $flags
   *
   * @return list<string>
   */
  public function eventStudioPublishReadinessLines(array $flags): array {
    $items = [];
    if (empty($flags['stripe'])) {
      $items[] = $this->vendorStripeReadinessBlockingLine();
    }
    if (empty($flags['profile'])) {
      $items[] = $this->vendorOrganiserProfileIncompleteLine();
    }
    if (empty($flags['terms'])) {
      $items[] = (string) $this->t('Accept terms to publish.');
    }
    return $items;
  }

  public function vendorDashboardOperationalSummaryHeading(): string {
    return (string) $this->t('Operational readiness');
  }

  public function vendorDashboardOperationalSummaryIntro(): string {
    return (string) $this->t('A calm snapshot of publishing, bookings, payouts, attendees, and discovery.');
  }

  public function vendorEventWorkspaceOperationalSummaryHeading(): string {
    return (string) $this->t('Operational readiness');
  }

  public function vendorEventWorkspaceOperationalSummaryIntro(): string {
    return (string) $this->t('What is ready, what attendees can see, and what still needs setup.');
  }

  public function vendorAttendeeCheckinGuidanceLine(): string {
    return (string) $this->t('Use check-in during your event to validate tickets.');
  }

  // -------------------------------------------------------------------------
  // MEL Attendee Operations Layer empty-state slots.
  // Owned by MELStateSystem; consumed by GovernedOperationalTemplates and the
  // attendee operations presenter. NEVER inline this copy in Twig.
  // See docs/adoption/mel-attendee-operations-audit.md.
  // -------------------------------------------------------------------------

  /**
   * Vendor: no attendees recorded for an event yet.
   *
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function vendorAttendeeOperationsNoAttendeesYetSlots(): array {
    return [
      'heading' => (string) $this->t('No attendees yet'),
      'what_happened' => (string) $this->t('We do not show any attendance records for this event.'),
      'why_empty' => (string) $this->t('RSVPs and ticket purchases appear here as soon as the first one is confirmed.'),
      'next_action' => (string) $this->t('Share your event link to start collecting RSVPs or ticket sales.'),
    ];
  }

  /**
   * Vendor: ticket sales empty state for paid events.
   *
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function vendorAttendeeOperationsNoTicketSalesYetSlots(): array {
    return [
      'heading' => (string) $this->t('No ticket sales yet'),
      'what_happened' => (string) $this->t('No paid ticket purchases have been recorded for this event.'),
      'why_empty' => (string) $this->t('Sales appear here when a buyer completes checkout.'),
      'next_action' => (string) $this->t('Confirm your tickets are on sale and your event is published.'),
    ];
  }

  /**
   * Vendor: RSVP submissions empty state for free events.
   *
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function vendorAttendeeOperationsNoRsvpsYetSlots(): array {
    return [
      'heading' => (string) $this->t('No RSVP submissions yet'),
      'what_happened' => (string) $this->t('Nobody has reserved a spot for this event yet.'),
      'why_empty' => (string) $this->t('RSVPs show here as soon as the first one comes in.'),
      'next_action' => (string) $this->t('Share your event so people can confirm their attendance.'),
    ];
  }

  /**
   * Vendor: export unavailable (no rows or transient block).
   *
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function vendorAttendeeOperationsExportUnavailableSlots(): array {
    return [
      'heading' => (string) $this->t('Export unavailable'),
      'what_happened' => (string) $this->t('There is nothing to export from the current attendee list.'),
      'why_empty' => (string) $this->t('Exports become available once at least one attendee record exists for this event.'),
      'next_action' => (string) $this->t('Try again after the first RSVP, ticket sale, or manual entry.'),
    ];
  }

  /**
   * Vendor: search returned no rows.
   *
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function vendorAttendeeOperationsSearchEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No attendees match this search'),
      'what_happened' => (string) $this->t('We could not find anybody whose name, email, or ticket code matches your search.'),
      'why_empty' => (string) $this->t('The search applies to the visible event only.'),
      'next_action' => (string) $this->t('Try a shorter search term or clear the search to see everyone.'),
    ];
  }

  /**
   * Vendor: filter combination returned no rows.
   *
   * @return array{heading: string, what_happened: string, why_empty: string, next_action: string}
   */
  public function vendorAttendeeOperationsFilterEmptySlots(): array {
    return [
      'heading' => (string) $this->t('No attendees match these filters'),
      'what_happened' => (string) $this->t('Nobody on this event matches the state and source filters you have selected.'),
      'why_empty' => (string) $this->t('Filters narrow the visible rows to make operational tasks easier.'),
      'next_action' => (string) $this->t('Reset the filters to see every attendee for this event.'),
    ];
  }

}
