<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Owner-approved legal policy page content for the direct-charge migration.
 *
 * This records the product owner's approval. It is not independent legal
 * advice; that distinction remains documented in the Stage 14 decision record.
 */
final class LegalPolicyPageContent {

  /**
   * Returns policy page definitions keyed by path alias.
   *
   * @return array<string, array{title: string, body: string}>
   *   Title and HTML body for each policy page.
   */
  public static function getDefinitions(?ConfigFactoryInterface $configFactory = NULL): array {
    $date = date('F j, Y');
    $supportEmail = '';
    if ($configFactory instanceof ConfigFactoryInterface) {
      $supportEmail = trim((string) ($configFactory->get('myeventlane_core.settings')->get('support_email') ?? ''));
    }
    $contactBlock = $supportEmail !== ''
      ? '<p>Email <a href="mailto:' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '</a> or use our <a href="/contact">Contact page</a>.</p>'
      : '<p>Use our <a href="/contact">Contact page</a> or the Help Centre.</p>';

    $reviewNotice = '';

    return [
      '/privacy' => [
        'title' => 'Privacy Policy',
        'body' => self::buildPrivacyBody($date, $contactBlock, $reviewNotice),
      ],
      '/terms' => [
        'title' => 'Customer Terms of Service',
        'body' => self::buildTermsBody('8 September 2026', $contactBlock, $reviewNotice),
      ],
      '/cookie-policy' => [
        'title' => 'Cookie Policy',
        'body' => self::buildCookiePolicyBody($date, $reviewNotice),
      ],
      '/vendor-terms' => [
        'title' => 'Vendor Agreement',
        'body' => self::buildVendorTermsBody('8 September 2026', $contactBlock),
      ],
    ];
  }

  private static function buildPrivacyBody(string $date, string $contactBlock, string $reviewNotice): string {
    return '<h2>Privacy Policy</h2>'
      . $reviewNotice
      . '<p><strong>Last updated:</strong> ' . $date . '</p>'
      . '<h3>What this page covers</h3>'
      . '<p>This Privacy Policy describes how My EventLane · ABN 11 304 813 593 (<strong>MyEventLane</strong>, <strong>we</strong>, <strong>us</strong>) handles personal information when you use our website, create an account, browse events, RSVP, or buy tickets. It is a practical summary for launch — not final legal advice.</p>'
      . '<h3>Information we collect</h3>'
      . '<p>Depending on how you use MyEventLane, we may collect:</p>'
      . '<ul>'
      . '<li><strong>Account details</strong> — name, email address, and login information</li>'
      . '<li><strong>Booking details</strong> — ticket or RSVP information, attendee responses, and order references</li>'
      . '<li><strong>Payment-related data</strong> — billing contact details and transaction references (card numbers are handled by Stripe, not stored by us)</li>'
      . '<li><strong>Organiser and vendor details</strong> — business or profile information when you host events</li>'
      . '<li><strong>Support messages</strong> — information you send when you contact us</li>'
      . '<li><strong>Technical data</strong> — device, browser, IP address, and usage information collected through cookies and similar technologies where enabled</li>'
      . '</ul>'
      . '<h3>How we use information</h3>'
      . '<p>We use personal information to:</p>'
      . '<ul>'
      . '<li>provide the platform, process bookings, and deliver tickets or RSVP confirmations</li>'
      . '<li>communicate with you about your account, orders, or support requests</li>'
      . '<li>help organisers manage their events and attendee lists</li>'
      . '<li>maintain security, prevent abuse, and improve the service</li>'
      . '<li>send optional marketing where you have agreed (you can opt out)</li>'
      . '</ul>'
      . '<h3>Event organiser access to booking information</h3>'
      . '<p>When you RSVP or buy a ticket, the event organiser receives the information needed to run the event — for example, your name, contact details, and any attendee questions you answer. Organisers are responsible for how they use that information for their event. MyEventLane provides tools to help them manage bookings; we do not control their independent use of attendee data outside the platform.</p>'
      . '<h3>Payments and Stripe</h3>'
      . '<p>Paid ticket checkout is processed by <strong>Stripe</strong>. Stripe collects and processes payment information according to its own privacy policy. MyEventLane receives limited payment and order data needed to confirm your purchase, support refunds where applicable, and help organisers reconcile sales.</p>'
      . '<h3>Cookies and analytics</h3>'
      . '<p>We use necessary cookies to run the site (for example, sign-in, cart, and security). Optional analytics or marketing cookies may be used where you allow them. See our <a href="/cookie-policy">Cookie Policy</a> and manage preferences on our <a href="/cookies">Cookies page</a>.</p>'
      . '<h3>Support and safety</h3>'
      . '<p>We may review account or booking information when investigating support requests, trust and safety reports, or suspected misuse of the platform. See our <a href="/help/policies/trust-and-safety">Trust &amp; safety</a> information in the Help Centre.</p>'
      . '<h3>Access, correction, and deletion requests</h3>'
      . '<p>You can update some account details in your profile. To request access to, correction of, or deletion of personal information we hold, contact us using the details below. We will respond within a reasonable time, subject to legal and operational requirements (for example, records we must keep for tax or dispute purposes).</p>'
      . '<h3>Contact</h3>'
      . $contactBlock;
  }

  private static function buildTermsBody(string $date, string $contactBlock, string $reviewNotice): string {
    return '<h2>Terms of Service</h2>'
      . $reviewNotice
      . '<p><strong>My EventLane · ABN 11 304 813 593</strong></p>'
      . '<p>These Terms govern your use of MyEventLane as an attendee, account holder, or visitor. By using the platform, you agree to these Terms and our <a href="/privacy">Privacy Policy</a>.</p>'
      . '<p><strong>Last updated:</strong> ' . $date . '</p>'
      . '<h3>Using MyEventLane</h3>'
      . '<p>MyEventLane helps people discover events and helps organisers publish listings, sell tickets, and manage RSVPs. You must use the platform lawfully, provide accurate information, and respect other users.</p>'
      . '<h3>Platform role</h3><p>The event organiser identified for your booking supplies the event and sells its tickets. My EventLane supplies the ticketing platform, including booking tools and support. Unless the event listing identifies My EventLane as the organiser, the organiser is responsible for delivering the event and communicating with attendees. My EventLane remains responsible for its own services, statements and obligations under applicable law.</p>'
      . '<h3>Accounts</h3>'
      . '<p>You are responsible for keeping your login details secure and for activity on your account. Tell us promptly if you suspect unauthorised access.</p>'
      . '<h3>Event listings</h3>'
      . '<p>Listing details (date, location, description, accessibility information, and policies) are provided by organisers. Check each event page before you book. MyEventLane may remove or restrict listings that breach these Terms or our community standards.</p>'
      . '<h3>Tickets and RSVPs</h3><p>Your ticket purchase or RSVP is a booking with the event organiser. The event description and any event-specific conditions disclosed before you book form part of that booking. Those conditions cannot exclude or restrict rights that apply under Australian Consumer Law. My EventLane provides the booking service and helps you access your booking records.</p>'
      . '<h3>Payments and fees</h3><p>Stripe processes ticket payments through the organiser’s connected Stripe account. Displayed ticket prices include mandatory attendee fees. Your order total and fee breakdown are shown before payment. My EventLane does not add a separate Stripe processing charge at checkout. See our <a href="/pricing">Pricing &amp; fees</a> page for details.</p>'
      . '<h3>GST on tickets and platform charges</h3>'
      . '<p>The organiser is the supplier of its tickets. Organiser GST is included and shown only where the organiser is currently registered for GST and the sale is taxable. An active ABN does not by itself mean the organiser is registered for GST. MyEventLane is the separate supplier of its platform charges, which may include GST even when the organiser is not registered. Your receipt identifies the relevant supplier and the GST recorded for each charge.</p>'
      . '<h3>Refunds</h3><p>The organiser is responsible for meeting its refund obligations for the event. Its change-of-mind policy operates in addition to your rights under Australian Consumer Law and cannot override them.</p><p>Open your booking from your account or confirmation email and use the refund or contact option shown. If you cannot submit a request, cannot reach the organiser, or need help with a disputed or delayed refund, <a href="/contact">contact My EventLane support</a> with your booking reference. We can investigate booking and refund records and help you contact the organiser. Contact us directly about a problem with My EventLane’s own service.</p><p>Event refunds are generally processed through Stripe using the organiser’s connected account. A right to a refund under applicable law does not depend on the organiser choosing to approve it or having sufficient funds available. See our <a href="/help/policies/refund-policy">refund policy</a>.</p>'
      . '<h3>Disputes and chargebacks</h3><p>You may contact your bank or payment provider about any available payment dispute or chargeback process. Eligibility, time limits and outcomes depend on your payment method and the relevant provider and card network rules. My EventLane can provide booking records and help investigate payment information but does not decide a chargeback. These processes do not replace your rights under applicable law.</p>'
      . '<h3>Organiser responsibilities</h3>'
      . '<p>Organisers must provide accurate event information, comply with applicable laws, honour reasonable attendee expectations, and use attendee data only for legitimate event-related purposes. Separate <a href="/vendor-terms">Vendor Terms</a> apply when you host events on MyEventLane.</p>'
      . '<h3>Community standards</h3>'
      . '<p>We expect respectful behaviour. See our <a href="/help/policies/community-guidelines">Community guidelines</a> in the Help Centre.</p>'
      . '<h3>Prohibited use</h3>'
      . '<p>You must not misuse the platform — for example, by posting unlawful content, attempting fraud, scraping data without permission, interfering with security, or impersonating others.</p>'
      . '<h3>Changes to events</h3><p>If the organiser chooses to cancel an event or makes a major change, you are entitled to a refund under Australian Consumer Law. You may also have rights where the event cannot be delivered safely. Rights arising from other cancellations or changes depend on the circumstances, the ticket terms and applicable law. Organisers must tell attendees promptly about cancellations and major changes. Contact the organiser or My EventLane support for help with your booking.</p>'
      . '<h3>Your consumer rights</h3><p>Nothing in these terms excludes, restricts or modifies any consumer guarantee, right or remedy that cannot lawfully be excluded under Australian Consumer Law or other applicable law. Depending on the circumstances, those remedies may include a refund and compensation for reasonably foreseeable loss. The organiser remains responsible for the event it supplies, and My EventLane remains responsible for its own services and conduct.</p>'
      . '<h3>Contact</h3>'
      . $contactBlock;
  }

  private static function buildVendorTermsBody(string $date, string $contactBlock): string {
    return '<h2>Organiser Agreement</h2>'
      . '<p><strong>Last updated:</strong> ' . $date . '</p>'
      . '<p><strong>My EventLane · ABN 11 304 813 593</strong></p>'
      . '<p>This agreement applies when you list or run an event, sell tickets, or manage RSVPs through MyEventLane.</p>'
      . '<h3>Your role as seller</h3><p>You supply the event and sell the tickets for each event you publish. You must have authority to sell those tickets and accurately identify the event supplier. You are responsible for event descriptions, prices, delivery, attendee communication and your obligations under applicable law. My EventLane supplies the ticketing platform and remains responsible for its own services and conduct.</p>'
      . '<h3>Stripe account and ticket revenue</h3>'
      . '<p>Paid ticket transactions are direct charges on your connected Stripe account. Your ticket revenue belongs to you and is managed through Stripe. Stripe sends available funds to your nominated bank account according to your Stripe payout schedule. MyEventLane does not hold or manually release your ticket-sale funds.</p>'
      . '<h3>Fees</h3>'
      . '<p>MyEventLane deducts its disclosed platform fee when a ticket payment is processed. Stripe separately charges processing fees under your Stripe account and payment method. Current fees must be shown in the applicable pricing and order surfaces.</p>'
      . '<h3>GST registration and tax information</h3>'
      . '<p>You must tell us whether your organisation is currently registered for GST with the Australian Taxation Office (ATO). An active ABN does not by itself mean you are registered for GST. If you are registered, you must provide a valid ABN and the GST registration effective date shown on <a href="https://abr.business.gov.au/">ABN Lookup</a>. You must keep this information current and confirm that it matches the Australian Business Register.</p>'
      . '<p>MyEventLane uses your recorded status to prepare invoices and apply organiser GST to taxable ticket sales. If you are not currently registered, MyEventLane will not include organiser GST in your ticket sales. MyEventLane\'s separate platform fee may still include GST. You remain responsible for your own registration and tax obligations; check the <a href="https://www.ato.gov.au/businesses-and-organisations/gst-excise-and-indirect-taxes/gst/registering-for-gst">current ATO guidance</a> or obtain professional advice if you are unsure.</p>'
      . '<h3>Refunds, cancellations and disputes</h3><p>You must clearly disclose your event conditions and change-of-mind refund policy before a booking is made. They cannot exclude or restrict rights under Australian Consumer Law.</p><p>If you choose to cancel an event or make a major change, you must provide refunds required by Australian Consumer Law. You must promptly notify affected attendees, explain how to request a remedy, and respond to requests within a reasonable time. You must also consider any other remedy required by law, including compensation where applicable.</p><p>Refunds processed through My EventLane generally use your connected Stripe account. You must maintain access to sufficient funds to meet refunds and payment disputes, including after payouts have reached your bank. A lack of available funds does not remove your obligations to attendees. Keep My EventLane informed if you cannot complete a required refund.</p><p>You must cooperate with refund investigations and provide accurate records. Respond to payment disputes within the deadlines set by your payment provider. My EventLane can supply booking records and support the process; chargebacks are determined through the relevant payment provider and card network processes.</p>'
      . '<h3>Stripe verification and payouts</h3>'
      . '<p>You must keep your connected Stripe account, identity information and bank details accurate. Stripe controls verification, restrictions, payout timing and bank settlement. MyEventLane cannot release a payout, change your Stripe payout schedule or edit your bank account.</p>'
      . '<h3>Event and attendee responsibilities</h3>'
      . '<p>You must provide accurate event information, communicate material changes promptly, run events safely, use attendee data only for legitimate event purposes, and comply with venue requirements and applicable laws.</p>'
      . '<h3>Contact</h3>'
      . $contactBlock;
  }

  private static function buildCookiePolicyBody(string $date, string $reviewNotice): string {
    return '<h2>Cookies on MyEventLane</h2>'
      . $reviewNotice
      . '<p>Cookies are small files stored on your device. We use them to run the site, remember preferences, and understand how people use MyEventLane.</p>'
      . '<h3>Necessary cookies</h3>'
      . '<p>Required for the site to work — for example, keeping you signed in, remembering your cart, and protecting against abuse. These cannot be turned off.</p>'
      . '<h3>Analytics cookies</h3>'
      . '<p>Help us understand which pages are useful so we can improve the Help Centre and event discovery. Optional — manage these on our <a href="/cookies">Cookies page</a>.</p>'
      . '<h3>Marketing cookies</h3>'
      . '<p>Used for advertising and personalisation where enabled. Optional.</p>'
      . '<h3>Your choices</h3>'
      . '<p>Use <strong>Manage cookie preferences</strong> on our <a href="/cookies">Cookies page</a> to update your settings at any time.</p>'
      . '<p>For more detail about personal information, see our <a href="/privacy">Privacy Policy</a>.</p>'
      . '<p><strong>Last updated:</strong> ' . $date . '</p>';
  }

}
