<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_core\Policy\DirectChargeCopy;

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
        'body' => self::buildTermsBody($date, $contactBlock, $reviewNotice),
      ],
      '/cookie-policy' => [
        'title' => 'Cookie Policy',
        'body' => self::buildCookiePolicyBody($date, $reviewNotice),
      ],
      '/vendor-terms' => [
        'title' => 'Vendor Agreement',
        'body' => self::buildVendorTermsBody($date, $contactBlock),
      ],
    ];
  }

  private static function buildPrivacyBody(string $date, string $contactBlock, string $reviewNotice): string {
    return '<h2>Privacy Policy</h2>'
      . $reviewNotice
      . '<p><strong>Last updated:</strong> ' . $date . '</p>'
      . '<h3>What this page covers</h3>'
      . '<p>This Privacy Policy describes how MyEventLane Pty Ltd (<strong>MyEventLane</strong>, <strong>we</strong>, <strong>us</strong>) handles personal information when you use our website, create an account, browse events, RSVP, or buy tickets. It is a practical summary for launch — not final legal advice.</p>'
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
      . '<p>These Terms govern your use of MyEventLane as an attendee, account holder, or visitor. By using the platform, you agree to these Terms and our <a href="/privacy">Privacy Policy</a>.</p>'
      . '<p><strong>Last updated:</strong> ' . $date . '</p>'
      . '<h3>Using MyEventLane</h3>'
      . '<p>MyEventLane helps people discover events and helps organisers publish listings, sell tickets, and manage RSVPs. You must use the platform lawfully, provide accurate information, and respect other users.</p>'
      . '<h3>Platform role</h3>'
      . '<p>' . DirectChargeCopy::CUSTOMER_SELLER . ' MyEventLane is <strong>not</strong> the organiser or host unless we clearly say otherwise. Organisers are responsible for their events, listings, pricing, attendee communication and compliance with applicable law.</p>'
      . '<h3>Accounts</h3>'
      . '<p>You are responsible for keeping your login details secure and for activity on your account. Tell us promptly if you suspect unauthorised access.</p>'
      . '<h3>Event listings</h3>'
      . '<p>Listing details (date, location, description, accessibility information, and policies) are provided by organisers. Check each event page before you book. MyEventLane may remove or restrict listings that breach these Terms or our community standards.</p>'
      . '<h3>Tickets and RSVPs</h3>'
      . '<p>When you RSVP or purchase a ticket, you enter an arrangement with the event organiser. MyEventLane facilitates booking and payment processing but does not guarantee that an event will proceed as described.</p>'
      . '<h3>Payments and fees</h3>'
      . '<p>Ticket payments are direct charges on the organiser\'s connected Stripe account. MyEventLane does not receive and later pay out the organiser\'s ticket revenue. Any MEL platform fee and applicable Stripe processing fee must be shown before you complete checkout. See our <a href="/pricing">Pricing &amp; fees</a> page for general information.</p>'
      . '<h3>GST on tickets and platform charges</h3>'
      . '<p>The organiser is the supplier of its tickets. Organiser GST is included and shown only where the organiser is currently registered for GST and the sale is taxable. An active ABN does not by itself mean the organiser is registered for GST. MyEventLane is the separate supplier of its platform charges, which may include GST even when the organiser is not registered. Your receipt identifies the relevant supplier and the GST recorded for each charge.</p>'
      . '<h3>Refunds</h3>'
      . '<p>The organiser is responsible for the event and its refund policy, subject to rights that cannot be excluded under Australian Consumer Law. MyEventLane may provide the refund workflow and support, but an approved refund is funded from the organiser\'s connected Stripe account and processed by Stripe. See our <a href="/help/policies/refund-policy">refund guidance</a>.</p>'
      . '<h3>Disputes and chargebacks</h3>'
      . '<p>The organiser, as seller, is responsible for responding to payment disputes about their event and providing requested evidence. Stripe controls the dispute process and outcome. MyEventLane may provide order or booking records but cannot decide a Stripe dispute.</p>'
      . '<h3>Organiser responsibilities</h3>'
      . '<p>Organisers must provide accurate event information, comply with applicable laws, honour reasonable attendee expectations, and use attendee data only for legitimate event-related purposes. Separate <a href="/vendor-terms">Vendor Terms</a> apply when you host events on MyEventLane.</p>'
      . '<h3>Community standards</h3>'
      . '<p>We expect respectful behaviour. See our <a href="/help/policies/community-guidelines">Community guidelines</a> in the Help Centre.</p>'
      . '<h3>Prohibited use</h3>'
      . '<p>You must not misuse the platform — for example, by posting unlawful content, attempting fraud, scraping data without permission, interfering with security, or impersonating others.</p>'
      . '<h3>Changes to events</h3>'
      . '<p>Organisers may change or cancel events. If that happens, follow the organiser’s instructions and our Help Centre guidance. MyEventLane is not responsible for organiser decisions about postponement or cancellation.</p>'
      . '<h3>Limits and disclaimers</h3>'
      . '<p>The platform is provided on an &quot;as available&quot; basis. To the extent permitted by law, MyEventLane limits its liability for organiser conduct, event outcomes, and indirect loss. Nothing in these draft Terms excludes rights that cannot be excluded under Australian consumer law. Final liability wording requires legal review.</p>'
      . '<h3>Contact</h3>'
      . $contactBlock;
  }

  private static function buildVendorTermsBody(string $date, string $contactBlock): string {
    return '<h2>Organiser Agreement</h2>'
      . '<p><strong>Last updated:</strong> ' . $date . '</p>'
      . '<p>This agreement applies when you list or run an event, sell tickets, or manage RSVPs through MyEventLane.</p>'
      . '<h3>Your role as seller</h3>'
      . '<p>You are the seller for each paid event you publish. You are responsible for the event, ticket descriptions, pricing, delivery, attendee communication, cancellations, refunds and compliance with applicable law. MyEventLane provides the marketplace and booking workflow.</p>'
      . '<h3>Stripe account and ticket revenue</h3>'
      . '<p>Paid ticket transactions are direct charges on your connected Stripe account. Your ticket revenue belongs to you and is managed through Stripe. Stripe sends available funds to your nominated bank account according to your Stripe payout schedule. MyEventLane does not hold or manually release your ticket-sale funds.</p>'
      . '<h3>Fees</h3>'
      . '<p>MyEventLane deducts its disclosed platform fee when a ticket payment is processed. Stripe separately charges processing fees under your Stripe account and payment method. Current fees must be shown in the applicable pricing and order surfaces.</p>'
      . '<h3>GST registration and tax information</h3>'
      . '<p>You must tell us whether your organisation is currently registered for GST with the Australian Taxation Office (ATO). An active ABN does not by itself mean you are registered for GST. If you are registered, you must provide a valid ABN and the GST registration effective date shown on <a href="https://abr.business.gov.au/">ABN Lookup</a>. You must keep this information current and confirm that it matches the Australian Business Register.</p>'
      . '<p>MyEventLane uses your recorded status to prepare invoices and apply organiser GST to taxable ticket sales. If you are not currently registered, MyEventLane will not include organiser GST in your ticket sales. MyEventLane\'s separate platform fee may still include GST. You remain responsible for your own registration and tax obligations; check the <a href="https://www.ato.gov.au/businesses-and-organisations/gst-excise-and-indirect-taxes/gst/registering-for-gst">current ATO guidance</a> or obtain professional advice if you are unsure.</p>'
      . '<h3>Refunds, cancellations and disputes</h3>'
      . '<p>You must handle refund requests fairly and meet obligations that cannot be excluded under Australian Consumer Law. Refunds processed through MyEventLane are funded from your connected Stripe account. Keep sufficient funds available for refunds, cancellations and disputes. Stripe controls dispute and chargeback processing; you must respond and provide requested evidence. MyEventLane may provide booking records but cannot decide a Stripe dispute.</p>'
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
