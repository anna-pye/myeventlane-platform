<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Creates and updates trust/marketing basic pages for footer destinations.
 */
final class TrustContentFoundation {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Upserts all trust foundation basic pages.
   *
   * @return list<string>
   *   Human-readable result messages.
   */
  public function seedTrustPages(): array {
    $messages = [];
    foreach ($this->getPageDefinitions() as $alias => $definition) {
      $messages[] = $this->upsertPage($alias, $definition['title'], $definition['body']);
    }
    return $messages;
  }

  /**
   * Upserts the public pricing page at /pricing.
   *
   * @return list<string>
   *   Human-readable result messages.
   */
  public function seedPricingPage(): array {
    $definition = $this->getPricingPageDefinition();
    return [$this->upsertPage('/pricing', $definition['title'], $definition['body'])];
  }

  /**
   * @return array{title: string, body: string}
   */
  private function getPricingPageDefinition(): array {
    $config = $this->configFactory->get('myeventlane_core.settings');
    $platformFee = $this->formatPercent($config->get('platform_fee_percent'));
    $stripeFeePercent = $this->formatPercent($config->get('stripe_fee_percent'));
    $stripeFixedCents = (int) ($config->get('stripe_fee_fixed_cents') ?? 30);
    $stripeFixed = '$' . number_format($stripeFixedCents / 100, 2);
    $feePayer = (string) ($config->get('fee_payer') ?? 'buyer');
    $feePayerText = $feePayer === 'organizer_absorbs'
      ? 'Organisers can choose to absorb platform fees so attendees see the ticket price they expect at checkout.'
      : 'By default, platform and payment processing fees are shown to attendees at checkout before they pay.';

    return [
      'title' => 'Pricing & Fees',
      'body' => '<h2>Pricing &amp; Fees</h2>'
        . '<p>MyEventLane is <strong>community-first</strong>. We aim to keep pricing straightforward for grassroots organisers, volunteers, and small teams — not enterprise lock-in.</p>'
        . '<h3>Free RSVP events</h3>'
        . '<p>RSVP events are free to publish. There is no ticket charge when attendees register for a free event. Organisers still get listing, promotion, and attendee management tools.</p>'
        . '<h3>Paid ticket events</h3>'
        . '<p>Paid events use secure checkout powered by <strong>Stripe</strong>. When you sell tickets, two types of fees may apply:</p>'
        . '<ul>'
        . '<li><strong>MyEventLane platform fee</strong> — currently <strong>' . htmlspecialchars($platformFee, ENT_QUOTES, 'UTF-8') . '</strong> on ticket sales. This supports hosting, support, and platform development.</li>'
        . '<li><strong>Stripe payment processing</strong> — Stripe charges card processing (currently about <strong>' . htmlspecialchars($stripeFeePercent, ENT_QUOTES, 'UTF-8') . ' + ' . htmlspecialchars($stripeFixed, ENT_QUOTES, 'UTF-8') . ' AUD</strong> per successful card payment). Stripe sets these rates; they can change. See <a href="https://stripe.com/au/pricing" rel="noopener noreferrer" target="_blank">Stripe pricing</a> for current details.</li>'
        . '</ul>'
        . '<h3>Who pays fees</h3>'
        . '<p>' . htmlspecialchars($feePayerText, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>Exact totals are always shown to the buyer before payment is completed. We do not add hidden checkout surprises.</p>'
        . '<h3>What we do not charge for here</h3>'
        . '<p>This page describes public-facing fee transparency. It is not a live quote for your specific event — taxes, refunds, and payout timing depend on your event setup and Stripe account status.</p>'
        . '<h3>Need more detail?</h3>'
        . '<p>Browse organiser guides in the <a href="/help/organisers">Help Centre</a>, read our <a href="/help/policies/refund-policy">refund policy</a>, or <a href="/contact">contact us</a> if you have questions before you publish.</p>',
    ];
  }

  private function formatPercent(mixed $value): string {
    if (!is_numeric($value)) {
      return '1.5%';
    }
    $percent = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    return $percent . '%';
  }

  /**
   * @return array<string, array{title: string, body: string}>
   */
  private function getPageDefinitions(): array {
    $supportEmail = trim((string) ($this->configFactory->get('myeventlane_core.settings')->get('support_email') ?? ''));
    $emailBlock = $supportEmail !== ''
      ? '<p>For general platform questions, email us at <a href="mailto:' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '</a>.</p>'
      : '<p>For general platform questions, start with the Help Centre below. A platform support email can be configured in site settings.</p>';

    return [
      '/about' => [
        'title' => 'About MyEventLane',
        'body' => '<h2>About MyEventLane</h2>'
          . '<p>MyEventLane helps people <strong>discover local events</strong> and gives organisers a straightforward way to <strong>publish, promote, and manage</strong> them — from free RSVPs to ticketed events.</p>'
          . '<h3>What we do</h3>'
          . '<ul>'
          . '<li><strong>Discovery</strong> — browse events by date, category, and location</li>'
          . '<li><strong>Booking</strong> — secure checkout for paid tickets; simple RSVP for free events</li>'
          . '<li><strong>Organiser tools</strong> — dashboards, ticketing, and communication in one place</li>'
          . '<li><strong>Community support</strong> — help content and support paths when you need a hand</li>'
          . '</ul>'
          . '<h3>Who we serve</h3>'
          . '<p>Community groups, creatives, venues, festivals, fundraisers, and anyone who brings people together in the real world.</p>'
          . '<h3>Our approach</h3>'
          . '<p>We are <strong>Australian-owned</strong> and <strong>community-first</strong>. We use <strong>Stripe</strong> for secure payments. We aim to keep trust, support, and clear information close to every event journey.</p>'
          . '<p><a href="/our-story">Our story</a> · <a href="/help">Help Centre</a> · <a href="/contact">Contact us</a></p>',
      ],
      '/our-story' => [
        'title' => 'Our Story',
        'body' => '<h2>Our Story</h2>'
          . '<p>MyEventLane started from a simple idea: <strong>local events should be easier to find, easier to host, and easier to trust.</strong></p>'
          . '<p>Across Australia, community life happens in halls, parks, studios, pubs, and pop-up spaces. Organisers give enormous energy to bring people together — but ticketing tools and discovery are often fragmented, expensive, or hard to use.</p>'
          . '<p>We built MyEventLane to change that.</p>'
          . '<h3>Community first</h3>'
          . '<p>We prioritise the people who make events happen: volunteers, small teams, independent promoters, and grassroots organisers. Our platform is designed to feel approachable, not corporate.</p>'
          . '<h3>Built for real events</h3>'
          . '<p>From free RSVPs to paid multi-tier tickets, MyEventLane supports the way community events actually work — with clear listings, secure payments, and practical organiser tools.</p>'
          . '<h3>Still growing</h3>'
          . '<p>We are continuing to improve discovery, organiser workflows, and support content with the people who use the platform every day. Your feedback shapes what we build next.</p>'
          . '<p><a href="/about">About MyEventLane</a> · <a href="/create-event">Create an event</a> · <a href="/help">Help Centre</a></p>',
      ],
      '/contact' => [
        'title' => 'Contact Us',
        'body' => '<h2>Contact Us</h2>'
          . '<p>We are here to help attendees, organisers, and vendors use MyEventLane with confidence.</p>'
          . '<h3>How to reach us</h3>'
          . '<p><strong>Help Centre (fastest start)</strong><br>'
          . 'Many questions are answered in our <a href="/help">Help Centre</a>. Search for your topic or browse attendee, organiser, and policy guides.</p>'
          . '<p><strong>Signed-in support</strong><br>'
          . 'If you have a MyEventLane account, open <strong>Support</strong> from your account menu to view your requests and send a new message.</p>'
          . '<p><strong>Email</strong><br>'
          . $emailBlock . '</p>'
          . '<h3>What to include</h3>'
          . '<p>Please share your name, the email on your account, the event name or link (if relevant), and a short description of what you need. Screenshots help, but never send payment card numbers or passwords.</p>'
          . '<h3>Response expectations</h3>'
          . '<p>We read every message. Response times vary by volume and complexity, but we aim to reply within <strong>2–3 business days</strong> for most requests. Urgent event-day issues are prioritised where we can.</p>'
          . '<p>We are a small, community-first team. Thank you for being patient and respectful — it helps us help you faster.</p>',
      ],
      '/accessibility' => [
        'title' => 'Accessibility',
        'body' => '<h2>Accessibility</h2>'
          . '<p>MyEventLane aims to be usable by as many people as possible — including people who use assistive technology, keyboard navigation, or screen readers.</p>'
          . '<h3>Our commitment</h3>'
          . '<p>We work to use clear language and logical page structure, maintain readable contrast and visible focus states, and support keyboard navigation for core flows. Accessibility is ongoing work, not a one-time checkbox.</p>'
          . '<h3>Event accessibility</h3>'
          . '<p>Event organisers can share accessibility information on their listings. Details vary by event and venue — check each event page or contact the organiser directly.</p>'
          . '<h3>Feedback and support</h3>'
          . '<p>If you have trouble using MyEventLane or need content in a different format, please <a href="/contact">contact us</a>. Tell us which page or flow was difficult and what would help. We welcome feedback and use it to prioritise improvements.</p>',
      ],
    ];
  }

  /**
   * Creates or updates a published basic page at the given alias.
   */
  private function upsertPage(string $alias, string $title, string $body): string {
    $node = $this->loadNodeByAlias($alias);
    if ($node instanceof NodeInterface) {
      $node->setTitle($title);
      $node->set('body', ['value' => $body, 'format' => 'basic_html']);
      $node->setPublished();
      $node->save();
      return sprintf('Updated page at %s (nid=%d).', $alias, (int) $node->id());
    }

    $node = Node::create([
      'type' => 'page',
      'title' => $title,
      'body' => ['value' => $body, 'format' => 'basic_html'],
      'status' => 1,
      'uid' => 1,
    ]);
    $node->save();
    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => $alias,
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ])->save();

    return sprintf('Created page at %s (nid=%d).', $alias, (int) $node->id());
  }

  private function loadNodeByAlias(string $alias): ?NodeInterface {
    $pathAliasStorage = $this->entityTypeManager->getStorage('path_alias');
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    foreach ($pathAliasStorage->loadByProperties(['alias' => $alias]) as $pathAlias) {
      if (preg_match('#^/node/(\d+)$#', $pathAlias->getPath(), $matches)) {
        $node = $nodeStorage->load($matches[1]);
        if ($node instanceof NodeInterface) {
          return $node;
        }
      }
    }
    return NULL;
  }

}
