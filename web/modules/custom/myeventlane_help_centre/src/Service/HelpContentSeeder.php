<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Seeds Help Centre landing pages and baseline help articles.
 */
final class HelpContentSeeder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly HelpContentRepository $helpContentRepository,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('myeventlane_help_centre');
  }

  /**
   * Module logger channel.
   */
  private \Psr\Log\LoggerInterface $logger;

  public function seedLandingPages(): int {
    $definitions = [
      [
        'title' => 'Help Centre Home',
        // Avoid /help and /help/* — those paths are registered Help Centre routes.
        'alias' => '/help/landing/home',
        'summary' => 'Quick answers for attendees, organisers, vendors, and policies in one place.',
        'body' => '<p>Welcome to the MyEventLane Help Centre. Start with the audience that matches you, then follow clear steps to get moving again.</p>',
        'audience' => ['General'],
        'featured' => TRUE,
      ],
      [
        'title' => 'Attendee Help',
        'alias' => '/help/landing/attendees',
        'summary' => 'Find help with tickets, RSVPs, refunds, and event-day access.',
        'body' => '<p>Need help finding your ticket, updating your booking, or understanding refund options? Start here for attendee support.</p>',
        'audience' => ['Attendees'],
        'featured' => TRUE,
      ],
      [
        'title' => 'Organiser Help',
        'alias' => '/help/landing/organisers',
        'summary' => 'Guides for setting up events, tickets, payouts, and attendee comms.',
        'body' => '<p>Running an event? This section covers setup, ticketing choices, dashboard basics, and practical next steps.</p>',
        'audience' => ['Organisers'],
        'featured' => TRUE,
      ],
      [
        'title' => 'Vendor Help',
        'alias' => '/help/landing/vendors',
        'summary' => 'Support for vendor dashboards, applications, and stall workflows.',
        'body' => '<p>Find clear guidance for vendor applications, profile management, and day-to-day vendor operations on MyEventLane.</p>',
        'audience' => ['Vendors'],
        'featured' => TRUE,
      ],
      [
        'title' => 'Policies and Trust',
        'alias' => '/help/landing/policies',
        'summary' => 'Understand privacy, terms, safety expectations, and community rules.',
        'body' => '<p>Here is where we explain how MyEventLane handles trust, privacy, and community safety in plain language.</p>',
        'audience' => ['General'],
        'featured' => TRUE,
      ],
    ];

    return $this->seedLandingPageNodes($definitions);
  }

  public function seedHelpArticles(): int {
    $yaml = $this->helpContentRepository->getHelpArticleSeeds();
    if ($yaml !== []) {
      $rows = $this->normalizeYamlSeedsToArticleRows($yaml);
      if ($rows !== []) {
        return $this->seedArticleNodes($rows);
      }
    }

    return $this->seedHelpArticlesLegacy();
  }

  /**
   * Legacy baseline articles — used when YAML config is empty or invalid.
   */
  private function seedHelpArticlesLegacy(): int {
    $articles = [
      [
        'title' => 'How to buy tickets',
        'summary' => 'Buy tickets in a few quick steps from event page to confirmation.',
        'body' => '<p>Open the event page, choose your ticket type, and continue to checkout. After payment, your ticket and receipt are sent by email straight away.</p>',
        'audience' => ['Attendees'],
        'topic' => 'Tickets',
        'article_type' => 'Guide',
        'keywords' => 'buy ticket, checkout, purchase, order',
        'cta_label' => 'Browse events',
        'cta_link' => '/events',
        'alias' => '/help/attendees/how-to-buy-tickets',
        'featured' => TRUE,
      ],
      [
        'title' => 'How to RSVP to a free event',
        'summary' => 'Reserve your place at a free event and keep your confirmation handy.',
        'body' => '<p>Select RSVP on the event page and complete your details. Keep the confirmation email so you can check in faster on the day.</p>',
        'audience' => ['Attendees'],
        'topic' => 'RSVP',
        'article_type' => 'Guide',
        'keywords' => 'free event, rsvp, register',
        'cta_label' => 'Find free events',
        'cta_link' => '/events',
        'alias' => '/help/attendees/how-to-rsvp-to-a-free-event',
        'featured' => TRUE,
      ],
      [
        'title' => 'How to access your tickets',
        'summary' => 'Where to find your ticket email and QR code before event day.',
        'body' => '<p>Your tickets are sent to your account email and can also be found in your MyEventLane account. Open the QR code at the gate for quick entry.</p>',
        'audience' => ['Attendees'],
        'topic' => 'Check-in',
        'article_type' => 'FAQ',
        'keywords' => 'ticket email, qr code, check in',
        'cta_label' => 'Open your account',
        'cta_link' => '/user',
        'alias' => '/help/attendees/how-to-access-your-tickets',
        'featured' => FALSE,
      ],
      [
        'title' => 'How to request a refund',
        'summary' => 'Check eligibility and send a refund request through the right channel.',
        'body' => '<p>Open your booking, review the organiser refund policy, then submit a refund request. If approved, the refund returns to the original payment method.</p>',
        'audience' => ['Attendees'],
        'topic' => 'Refunds',
        'article_type' => 'Troubleshooting',
        'keywords' => 'refund request, cancellation, ticket refund',
        'cta_label' => 'Read refund policy',
        'cta_link' => '/help/policies/refund-policy',
        'alias' => '/help/attendees/how-to-request-a-refund',
        'featured' => TRUE,
      ],
      [
        'title' => 'How to create an event',
        'summary' => 'Set up your event details, timing, and tickets before publishing.',
        'body' => '<p>From your organiser dashboard, create a new event, complete the required details, and preview before publishing. Keep the event summary simple and clear.</p>',
        'audience' => ['Organisers'],
        'topic' => 'Event creation',
        'article_type' => 'Guide',
        'keywords' => 'create event, publish event, organiser',
        'cta_label' => 'Open organiser dashboard',
        'cta_link' => '/dashboard',
        'alias' => '/help/organisers/how-to-create-an-event',
        'featured' => TRUE,
      ],
      [
        'title' => 'Choosing RSVP or paid tickets',
        'summary' => 'Pick the right ticket setup based on your event goals.',
        'body' => '<p>Use RSVP when attendance is free and you mainly need numbers. Use paid tickets when you need checkout, receipts, and clearer revenue tracking.</p>',
        'audience' => ['Organisers'],
        'topic' => 'Ticket setup',
        'article_type' => 'Overview',
        'keywords' => 'rsvp vs paid, ticket setup, pricing',
        'cta_label' => 'Configure ticket types',
        'cta_link' => '/dashboard',
        'alias' => '/help/organisers/choosing-rsvp-or-paid-tickets',
        'featured' => FALSE,
      ],
      [
        'title' => 'Managing your event dashboard',
        'summary' => 'Track bookings, attendee activity, and quick event edits.',
        'body' => '<p>Your dashboard gives a live view of bookings, capacity, and messages. Use it to make quick updates and keep attendees informed.</p>',
        'audience' => ['Organisers'],
        'topic' => 'Analytics',
        'article_type' => 'Guide',
        'keywords' => 'event dashboard, analytics, organiser tools',
        'cta_label' => 'Go to dashboard',
        'cta_link' => '/dashboard',
        'alias' => '/help/organisers/managing-your-event-dashboard',
        'featured' => TRUE,
      ],
      [
        'title' => 'Vendor dashboard overview',
        'summary' => 'Navigate your vendor workspace, events, and stall tools.',
        'body' => '<p>Use the vendor dashboard to review applications, manage your profile, and access event-day workflows. Links from your dashboard open the right tools for each task.</p>',
        'audience' => ['Vendors'],
        'topic' => 'Getting started',
        'article_type' => 'Guide',
        'keywords' => 'vendor dashboard, vendor workspace, stall',
        'cta_label' => 'Vendor help',
        'cta_link' => '/help/vendors',
        'alias' => '/help/vendors/vendor-dashboard-overview',
        'featured' => FALSE,
      ],
      [
        'title' => 'Refund policy',
        'summary' => 'How refunds are handled, assessed, and paid back on MyEventLane.',
        'body' => '<p>Refunds depend on the organiser policy and event circumstances. Approved refunds are returned to the original payment method and timing may vary by bank.</p>',
        'audience' => ['General'],
        'topic' => 'Privacy',
        'article_type' => 'Policy',
        'keywords' => 'refund rules, policy, cancelled event',
        'cta_label' => 'Contact support',
        'cta_link' => '/help',
        'alias' => '/help/policies/refund-policy',
        'featured' => TRUE,
      ],
      [
        'title' => 'Community guidelines',
        'summary' => 'Our expectations for respectful behaviour across the platform.',
        'body' => '<p>MyEventLane is community-first. We expect respectful conduct, clear communication, and safe participation from attendees, organisers, and vendors.</p>',
        'audience' => ['General'],
        'topic' => 'Community guidelines',
        'article_type' => 'Policy',
        'keywords' => 'community rules, safety, respectful behaviour',
        'cta_label' => 'Read policies',
        'cta_link' => '/help/policies',
        'alias' => '/help/policies/community-guidelines',
        'featured' => TRUE,
      ],
    ];

    return $this->seedArticleNodes($articles);
  }

  /**
   * @param array<string, array<string, mixed>> $helpArticles
   *
   * @return array<int, array<string, mixed>>
   */
  private function normalizeYamlSeedsToArticleRows(array $helpArticles): array {
    $out = [];
    foreach ($helpArticles as $row) {
      if (!is_array($row)) {
        continue;
      }
      $title = trim((string) ($row['title'] ?? ''));
      if ($title === '') {
        continue;
      }
      $kw = $row['keywords'] ?? '';
      if (is_array($kw)) {
        $kw = implode(', ', $kw);
      }
      $audience = $row['audience'] ?? [];
      if (!is_array($audience)) {
        $audience = $audience !== '' && $audience !== NULL ? [(string) $audience] : [];
      }
      $out[] = [
        'title' => $title,
        'summary' => (string) ($row['summary'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'audience' => $audience,
        'topic' => (string) ($row['topic'] ?? ''),
        'article_type' => (string) ($row['article_type'] ?? ''),
        'keywords' => (string) $kw,
        'cta_label' => (string) ($row['cta_label'] ?? ''),
        'cta_link' => (string) ($row['cta_link'] ?? ''),
        'alias' => (string) ($row['alias'] ?? ''),
        'featured' => (bool) ($row['featured'] ?? FALSE),
      ];
    }
    return $out;
  }

  private function seedLandingPageNodes(array $definitions): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $created = 0;

    foreach ($definitions as $item) {
      $existing = $storage->loadByProperties([
        'type' => 'help_landing_page',
        'title' => $item['title'],
      ]);
      if ($existing !== []) {
        continue;
      }

      $node = Node::create([
        'type' => 'help_landing_page',
        'title' => $item['title'],
        'status' => 1,
      ]);
      $this->setTextField($node, 'field_help_summary', (string) $item['summary']);
      $this->setBodyField($node, (string) $item['body']);
      $this->setBoolField($node, 'field_featured_help', (bool) $item['featured']);
      $this->setAudienceField($node, (array) $item['audience']);
      $this->setAlias($node, (string) $item['alias']);
      $node->save();
      $created++;
    }

    $this->logger->notice('Help Centre landing pages seeded. Created @count pages.', ['@count' => (string) $created]);
    return $created;
  }

  private function seedArticleNodes(array $definitions): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $created = 0;

    foreach ($definitions as $item) {
      $existing = $storage->loadByProperties([
        'type' => 'help_article',
        'title' => $item['title'],
      ]);
      if ($existing !== []) {
        continue;
      }

      $node = Node::create([
        'type' => 'help_article',
        'title' => $item['title'],
        'status' => 1,
      ]);
      $this->setTextField($node, 'field_help_summary', (string) $item['summary']);
      $this->setBodyField($node, (string) $item['body']);
      $this->setAudienceField($node, (array) $item['audience']);
      $this->setTermFieldByName($node, 'field_help_topic', 'help_topic', (string) $item['topic']);
      $this->setTermFieldByName($node, 'field_help_article_type', 'help_article_type', (string) $item['article_type']);
      $this->setTextField($node, 'field_help_keywords', (string) $item['keywords']);
      $this->setBoolField($node, 'field_featured_help', (bool) ($item['featured'] ?? FALSE));
      $this->setTextField($node, 'field_help_cta_label', (string) $item['cta_label']);
      $this->setLinkField($node, 'field_help_cta_link', (string) $item['cta_link']);
      $this->setDateField($node, 'field_last_reviewed', date('Y-m-d'));
      $this->setAlias($node, (string) $item['alias']);
      $node->save();
      $created++;
    }

    $this->logger->notice('Help Centre articles seeded. Created @count articles.', ['@count' => (string) $created]);
    return $created;
  }

  private function setBodyField(NodeInterface $node, string $html): void {
    if (!$node->hasField('body')) {
      return;
    }
    $node->set('body', [
      'value' => $html,
      'format' => 'basic_html',
    ]);
  }

  private function setTextField(NodeInterface $node, string $fieldName, string $value): void {
    if ($value === '' || !$node->hasField($fieldName)) {
      return;
    }
    $node->set($fieldName, $value);
  }

  private function setBoolField(NodeInterface $node, string $fieldName, bool $value): void {
    if (!$node->hasField($fieldName)) {
      return;
    }
    $node->set($fieldName, $value ? 1 : 0);
  }

  private function setLinkField(NodeInterface $node, string $fieldName, string $uri): void {
    if ($uri === '' || !$node->hasField($fieldName)) {
      return;
    }
    $node->set($fieldName, [
      'uri' => str_starts_with($uri, 'http') ? $uri : 'internal:' . $uri,
      'title' => '',
    ]);
  }

  private function setDateField(NodeInterface $node, string $fieldName, string $date): void {
    if (!$node->hasField($fieldName)) {
      return;
    }
    $node->set($fieldName, $date);
  }

  private function setAlias(NodeInterface $node, string $alias): void {
    if ($alias === '' || !$node->hasField('path')) {
      return;
    }
    $node->set('path', [
      'alias' => $alias,
      'pathauto' => 0,
    ]);
  }

  private function setAudienceField(NodeInterface $node, array $names): void {
    if ($names === []) {
      return;
    }

    if (!$node->hasField('field_audience')) {
      return;
    }

    $canonical = [];
    foreach ($names as $name) {
      $mapped = $this->mapSeedAudienceNameToCanonical($name);
      if ($mapped !== NULL) {
        $canonical[$mapped] = $mapped;
      }
    }
    if ($canonical !== []) {
      $node->set('field_audience', array_map(
        static fn(string $v): array => ['value' => $v],
        array_values($canonical),
      ));
    }
  }

  /**
   * Maps seeded audience labels to field_audience list values.
   */
  private function mapSeedAudienceNameToCanonical(string $name): ?string {
    $key = mb_strtolower(trim($name));
    return match ($key) {
      'vendors', 'vendor' => 'vendor',
      'admin', 'staff' => 'staff',
      'attendees', 'attendee', 'organisers', 'organizers', 'general', 'public' => 'public',
      default => NULL,
    };
  }

  private function setTermFieldByName(NodeInterface $node, string $fieldName, string $vocabularyId, string $termName): void {
    if (!$node->hasField($fieldName) || $termName === '') {
      return;
    }
    $term = $this->loadTermByName($vocabularyId, $termName);
    if ($term instanceof TermInterface) {
      $node->set($fieldName, ['target_id' => (int) $term->id()]);
    }
  }

  private function loadTermByName(string $vocabularyId, string $name): ?TermInterface {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabularyId,
        'name' => $name,
      ]);
    if ($terms === []) {
      return NULL;
    }
    $term = reset($terms);
    return $term instanceof TermInterface ? $term : NULL;
  }

}
