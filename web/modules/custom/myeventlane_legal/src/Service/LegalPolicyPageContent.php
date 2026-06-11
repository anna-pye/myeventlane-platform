<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Launch-ready legal policy page content (draft — requires legal review).
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

    $reviewNotice = '<p class="mel-legal-review-notice"><strong>Legal review notice:</strong> This page is a launch-ready draft and should be reviewed before production legal sign-off.</p>';

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
        'body' => '<p>This Vendor Agreement sets out the terms under which organisers use MyEventLane to list events and sell tickets or manage RSVPs.</p>'
          . '<p><strong>Last updated:</strong> ' . $date . '</p>'
          . '<p><em>Full legal text to be inserted by qualified advisers. This is a structure placeholder only.</em></p>'
          . $reviewNotice,
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
      . '<p>MyEventLane is a marketplace and technology platform. We are <strong>not</strong> the organiser or host of events listed on the site unless we clearly say otherwise. Organisers are responsible for their events, listings, pricing, and attendee communication.</p>'
      . '<h3>Accounts</h3>'
      . '<p>You are responsible for keeping your login details secure and for activity on your account. Tell us promptly if you suspect unauthorised access.</p>'
      . '<h3>Event listings</h3>'
      . '<p>Listing details (date, location, description, accessibility information, and policies) are provided by organisers. Check each event page before you book. MyEventLane may remove or restrict listings that breach these Terms or our community standards.</p>'
      . '<h3>Tickets and RSVPs</h3>'
      . '<p>When you RSVP or purchase a ticket, you enter an arrangement with the event organiser. MyEventLane facilitates booking and payment processing but does not guarantee that an event will proceed as described.</p>'
      . '<h3>Payments and fees</h3>'
      . '<p>Paid tickets are processed through Stripe. Platform and payment fees may apply and are shown before you complete checkout. See our <a href="/pricing">Pricing &amp; fees</a> page for general information.</p>'
      . '<h3>Refunds</h3>'
      . '<p>Refund rules depend on the event and organiser policy. Platform-level guidance is in our <a href="/help/policies/refund-policy">Refund policy</a>. Organisers may set additional conditions on their event pages.</p>'
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
