<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_core\MelReadinessHelper
 *
 * @group myeventlane_surface
 */
final class MelReadinessHelperCustomerTest extends UnitTestCase {

  private function helper(): MelReadinessHelper {
    return new MelReadinessHelper($this->getStringTranslationStub());
  }

  /**
   * @covers ::customerCommerceOrderStateLabel
   */
  public function testOrderStateMapsToGovernedCustomerLabels(): void {
    $h = $this->helper();
    $this->assertSame((string) $h->customerCommerceOrderStateLabel('completed', 'X'), (string) $h->customerCommerceOrderStateLabel('placed', 'Y'));
    $this->assertNotSame('', (string) $h->customerCommerceOrderStateLabel('unknown_state_xyz', 'Custom fallback'));
    $this->assertStringContainsString('Custom fallback', (string) $h->customerCommerceOrderStateLabel('unknown_state_xyz', 'Custom fallback'));
  }

  /**
   * @covers ::customerMyTicketsOverviewEmptySlots
   * @covers ::customerCategoryFollowEmptySlots
   * @covers ::customerCategoryFollowWeeklyQuietSlots
   * @covers ::customerMyEventsDashboardUpcomingEmptySlots
   * @covers ::customerAccountDashboardBookingsEmptySlots
   * @covers ::customerHubBookingStatusLabel
   * @covers ::customerDigitalPassStatusLabel
   * @covers ::customerDigitalPassNextStep
   * @covers ::customerEventReadinessPanelLabels
   * @covers ::customerEventReadinessItemLabels
   * @covers ::customerEventReadinessStatusLabel
   * @covers ::customerHubBookingCtaLabels
   * @covers ::customerPrimaryBrowseEventsCta
   * @covers ::customerContinuityExploreHiddenGemsCta
   * @covers ::customerContinuityBrowseMoreEventsCta
   */
  public function testOperationalEmptySlotsAreNonEmptyStrings(): void {
    $h = $this->helper();
    $tickets = $h->customerMyTicketsOverviewEmptySlots();
    $this->assertNotSame('', $tickets['heading']);
    $this->assertNotSame('', $tickets['what_happened']);
    $this->assertNotSame('', $tickets['next_action']);
    $categories = $h->customerCategoryFollowEmptySlots();
    $this->assertNotSame('', $categories['heading']);
    $quiet = $h->customerCategoryFollowWeeklyQuietSlots();
    $this->assertNotSame('', $quiet['heading']);
    $this->assertNotSame('', $quiet['what_happened']);
    $my_events = $h->customerMyEventsDashboardUpcomingEmptySlots();
    $this->assertNotSame('', $my_events['heading']);
    $this->assertNotSame('', $my_events['why_empty']);
    $bookings = $h->customerAccountDashboardBookingsEmptySlots();
    $this->assertSame('Nothing booked yet', $bookings['heading']);
    $this->assertSame('Browse events', $bookings['cta_label']);
    $this->assertSame('Booking confirmed', $h->customerHubBookingStatusLabel('confirmed'));
    $this->assertSame('Today', $h->customerHubBookingStatusLabel('today'));
    $this->assertSame('Tomorrow', $h->customerHubBookingStatusLabel('tomorrow'));
    $this->assertSame('Completed', $h->customerHubBookingStatusLabel('completed'));
    $this->assertSame('Ticket ready', $h->customerHubBookingStatusLabel('ticket_ready'));
    $this->assertSame('Ticket ready', $h->customerDigitalPassStatusLabel('ticket_ready'));
    $this->assertSame('Checked in', $h->customerDigitalPassStatusLabel('checked_in'));
    $this->assertSame('Expired', $h->customerDigitalPassStatusLabel('expired'));
    $this->assertSame('Cancelled', $h->customerDigitalPassStatusLabel('cancelled'));
    $this->assertStringContainsString('QR code', $h->customerDigitalPassNextStep('ticket_ready'));
    $panel = $h->customerEventReadinessPanelLabels();
    $this->assertSame('Event readiness', $panel['heading']);
    $this->assertSame('Need more information?', $panel['summary']);
    $this->assertStringContainsString('7 days', $panel['reminder_note']);
    $items = $h->customerEventReadinessItemLabels();
    $this->assertSame('Booking confirmed', $items['booking_confirmed']);
    $this->assertSame('Accessibility', $items['accessibility']);
    $this->assertSame('Today', $h->customerEventReadinessStatusLabel('today'));
    $this->assertSame('Checked in', $h->customerEventReadinessStatusLabel('checked_in'));
    $ctas = $h->customerHubBookingCtaLabels();
    $this->assertSame('View Digital Pass', $ctas['view_booking']);
    $this->assertSame('Show QR code', $ctas['view_ticket']);
    $this->assertSame('Download PDF', $ctas['download_ticket']);
    $this->assertSame('Get directions', $ctas['get_directions']);
    $this->assertSame('Contact organiser', $ctas['contact_organiser']);
    $this->assertNotSame('', $h->customerPrimaryBrowseEventsCta());
    $this->assertNotSame('', $h->customerContinuityExploreHiddenGemsCta());
    $this->assertNotSame('', $h->customerContinuityBrowseMoreEventsCta());
  }

  /**
   * @covers ::customerCheckoutOrderSummarySurfaceLabels
   * @covers ::customerCheckoutCartRemovedUnavailableSlots
   * @covers ::customerCartShellLabels
   * @covers ::customerCheckoutOrderNumberLine
   * @covers ::customerCheckoutCompletionHero
   */
  public function testCheckoutSummaryAndCartShellLabelsIncludeTrustFooter(): void {
    $h = $this->helper();
    $labels = $h->customerCheckoutOrderSummarySurfaceLabels();
    $this->assertSame('Booking summary', $labels['title']);
    $this->assertSame('Book with confidence', $labels['trust_heading']);
    $this->assertArrayHasKey('trust_footer_refund_hint', $labels);
    $this->assertSame('Secure payment processed by Stripe', $labels['trust_footer_secure']);
    $this->assertStringContainsString('booking confirmation', $labels['trust_footer_instant']);
    $this->assertStringContainsString('organiser’s refund policy', $labels['trust_footer_refund_hint']);
    $this->assertSame('View the Refund Policy', $labels['trust_refund_link_label']);
    $this->assertArrayNotHasKey('jump_payment', $labels);
    $this->assertStringNotContainsString('Jump to payment', implode("\n", $labels));
    $this->assertStringNotContainsString('Confirmation emailed instantly', implode("\n", $labels));
    $this->assertStringNotContainsString('Refund policy and organiser rules are in the Help Centre.', implode("\n", $labels));
    $this->assertStringNotContainsString('Secure payments via Stripe', implode("\n", $labels));
    $removed = $h->customerCheckoutCartRemovedUnavailableSlots();
    $this->assertNotSame('', $removed['heading']);
    $this->assertNotSame('', $removed['what_happened']);
    $cart = $h->customerCartShellLabels();
    $this->assertSame($labels['trust_footer_secure'], $cart['reassurance_secure']);
    $this->assertArrayHasKey('reassurance_refund_hint', $cart);
    $this->assertSame('Booking #2026-07-3', $h->customerCheckoutOrderNumberLine('2026-07-3'));
    $hero = $h->customerCheckoutCompletionHero();
    $this->assertSame('Booking confirmed', $hero['heading']);
    $this->assertStringNotContainsString('tickets have been sent', $hero['lead']);
  }

}
