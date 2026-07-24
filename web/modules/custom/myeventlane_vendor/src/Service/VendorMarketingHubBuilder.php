<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the organiser Marketing Hub view model (Event Growth Centre).
 *
 * Deep-links Boost, widgets, audience, and share tools.
 * Does not invent a new advertising or analytics architecture.
 */
final class VendorMarketingHubBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly TicketSalesService $ticketSales,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly DomainDetector $domainDetector,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
    private readonly ?object $boostManager = NULL,
    private readonly ?object $boostHelpContent = NULL,
    private readonly ?object $eventStateResolver = NULL,
    private readonly ?object $qrCodeGenerator = NULL,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds the global Marketing Hub payload.
   *
   * @return array<string, mixed>
   *   Hub view model for Twig.
   */
  public function build(): array {
    $uid = (int) $this->currentUser->id();
    $eventIds = $this->ticketSales->getManagedEventNidsForUser($uid);
    $events = $this->loadManagedEvents($eventIds);
    $vendor = $this->vendorResolver->resolveFromCurrentUser();
    $boostPayload = $this->buildBoostPayload($events);
    $shareEvents = $this->buildShareEvents($events);
    $primaryShare = $shareEvents[0] ?? NULL;
    $socialReady = $this->isSocialComplete($vendor);
    $publishedCount = count(array_filter($events, static fn(NodeInterface $n): bool => $n->isPublished()));
    $activeBoosts = count($boostPayload['campaigns']);
    $boostEligible = count($boostPayload['eligible']);
    $orderCount = $this->ticketSales->getVendorOrderCount($uid);
    $score = $this->computeMarketingScore($publishedCount, $primaryShare !== NULL, $boostEligible > 0 || $activeBoosts > 0, $socialReady, $activeBoosts > 0);
    $health = $this->buildHealth($publishedCount, $primaryShare, $boostEligible, $activeBoosts, $socialReady, $score);

    $this->logger->info('marketing_opened uid=@uid events=@events published=@published boosts=@boosts score=@score', [
      '@uid' => (string) $uid,
      '@events' => (string) count($events),
      '@published' => (string) $publishedCount,
      '@boosts' => (string) $activeBoosts,
      '@score' => (string) $score,
    ]);

    return [
      'health' => $health,
      'share' => [
        'title' => (string) $this->t('Share event'),
        'body' => (string) $this->t('Copy your public link, download a QR code, or share on social — one click at a time.'),
        'events' => $shareEvents,
        'empty_title' => (string) $this->t('No live events to share yet'),
        'empty_body' => (string) $this->t('Publish an event first. Then you can copy the link, show a QR code, and share with your community.'),
        'publish_url' => $this->safeRouteUrl('myeventlane_vendor.console.events'),
      ],
      'boost' => [
        'title' => (string) $this->t('Boost'),
        'eyebrow' => (string) $this->t('Premium visibility'),
        'what' => (string) $this->t('Boost features your event in selected placements across MyEventLane — such as discovery and category browsing — so more locals can find you.'),
        'who' => (string) $this->t('People exploring events on MyEventLane see Boosted events in those placements. It does not create a private club or guarantee ticket sales.'),
        'outcome' => (string) $this->t('Expect more people to discover your page. Sales still depend on your event, price, and timing — we never claim Boost caused a booking.'),
        'campaigns' => $boostPayload['campaigns'],
        'eligible' => $boostPayload['eligible'],
        'faq' => $boostPayload['faq'],
        'export_url' => $this->safeRouteUrl('myeventlane_vendor.console.boost_vendor_export'),
        'empty_title' => (string) $this->t('No active Boost campaigns'),
        'empty_body' => (string) $this->t('When you Boost an event, its status and end date appear here.'),
        'available' => $this->moduleHandler->moduleExists('myeventlane_boost'),
      ],
      'widgets' => [
        'title' => (string) $this->t('Widgets & embeds'),
        'body' => (string) $this->t('Add a ticket widget to your own website so people can book without leaving your brand.'),
        'events' => $this->buildWidgetEvents($events),
        'empty_title' => (string) $this->t('Publish an event to add widgets'),
        'empty_body' => (string) $this->t('Widgets live under Tickets for each event. Marketing brings you there in one click.'),
      ],
      'social' => [
        'title' => (string) $this->t('Social media'),
        'body' => (string) $this->t('Share where your community already gathers. Add your organiser social links so guests can follow you.'),
        'ready' => $socialReady,
        'ready_label' => $socialReady
          ? (string) $this->t('Your social links look ready on your organiser profile.')
          : (string) $this->t('Add social links in Settings so guests can find you beyond the event page.'),
        'settings_url' => $this->safeRouteUrl('myeventlane_vendor.console.settings'),
        'channels' => [
          ['key' => 'facebook', 'label' => (string) $this->t('Facebook')],
          ['key' => 'instagram', 'label' => (string) $this->t('Instagram')],
          ['key' => 'linkedin', 'label' => (string) $this->t('LinkedIn')],
          ['key' => 'email', 'label' => (string) $this->t('Email')],
        ],
      ],
      'audience' => [
        'title' => (string) $this->t('Audience growth'),
        'body' => (string) $this->t('See who has booked or RSVPed across your events, then invite them back to what is next.'),
        'cta_label' => (string) $this->t('Open Audience'),
        'cta_url' => $this->safeRouteUrl('myeventlane_vendor.console.audience'),
        'tips' => [
          (string) $this->t('Share your public page with local groups and newsletters.'),
          (string) $this->t('Message past guests when you publish something new (from Messages).'),
          (string) $this->t('Use Boost when you want wider discovery on MyEventLane.'),
        ],
      ],
      'performance' => [
        'title' => (string) $this->t('Marketing performance'),
        'body' => (string) $this->t('A simple pulse from data we already collect. Deeper charts live in Analytics.'),
        'metrics' => [
          [
            'label' => (string) $this->t('Live events'),
            'value' => (string) $publishedCount,
            'hint' => (string) $this->t('Published and shareable'),
          ],
          [
            'label' => (string) $this->t('Bookings'),
            'value' => (string) $orderCount,
            'hint' => (string) $this->t('Orders across your events'),
          ],
          [
            'label' => (string) $this->t('Active Boosts'),
            'value' => (string) $activeBoosts,
            'hint' => (string) $this->t('Campaigns running now'),
          ],
        ],
        'unavailable' => [
          'title' => (string) $this->t('Coming later'),
          'items' => [
            (string) $this->t('Page views on this Marketing home (instrumentation planned).'),
            (string) $this->t('Traffic sources on this Marketing home (use Analytics for event depth when available).'),
            (string) $this->t('Conversion rate on this Marketing home (event Analytics holds funnel detail when enabled).'),
          ],
        ],
        'analytics_url' => $this->safeRouteUrl('myeventlane_analytics.dashboard'),
        'analytics_label' => (string) $this->t('Open Analytics'),
        'export_url' => $this->safeRouteUrl('myeventlane_vendor.console.boost_vendor_export'),
        'export_label' => (string) $this->t('Export Boost performance'),
      ],
      'actions' => [
        'title' => (string) $this->t('Recommended actions'),
        'items' => $this->buildRecommendedActions($publishedCount, $primaryShare, $boostEligible, $activeBoosts, $socialReady),
      ],
      'analytics' => [
        'marketing_opened' => TRUE,
        'marketing_score' => $score,
      ],
    ];
  }

  /**
   * Loads managed event nodes for the hub.
   *
   * @param list<int> $eventIds
   *   Managed event node IDs.
   *
   * @return list<\Drupal\node\NodeInterface>
   *   Loaded event nodes (access already gated by TicketSalesService).
   */
  private function loadManagedEvents(array $eventIds): array {
    if ($eventIds === []) {
      return [];
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($eventIds);
    $events = [];
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface && $node->bundle() === 'event') {
        $events[] = $node;
      }
    }
    usort($events, static function (NodeInterface $a, NodeInterface $b): int {
      $pub = ((int) $b->isPublished()) <=> ((int) $a->isPublished());
      if ($pub !== 0) {
        return $pub;
      }
      return strcasecmp((string) $a->label(), (string) $b->label());
    });
    return $events;
  }

  /**
   * Builds share cards for published events.
   *
   * @param list<\Drupal\node\NodeInterface> $events
   *   Managed events.
   *
   * @return list<array<string, mixed>>
   *   Share cards for published events.
   */
  private function buildShareEvents(array $events): array {
    $cards = [];
    foreach ($events as $event) {
      if (!$event->isPublished()) {
        continue;
      }
      $nid = (int) $event->id();
      $path = Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
      // Share / copy / QR must be absolute — publicUrl() can return a relative
      // path on single-host environments (e.g. DDEV).
      $publicUrl = $this->domainDetector->absolutePublicUrl($path);
      $title = (string) $event->label();
      $qrUri = NULL;
      if ($this->qrCodeGenerator !== NULL && method_exists($this->qrCodeGenerator, 'buildDataUri')) {
        try {
          $qrUri = $this->qrCodeGenerator->buildDataUri($publicUrl, 240);
        }
        catch (\Throwable $e) {
          $this->logger->warning('Marketing hub QR generation failed for event @nid: @message', [
            '@nid' => (string) $nid,
            '@message' => $e->getMessage(),
          ]);
        }
      }

      $cards[] = [
        'id' => $nid,
        'title' => $title,
        'public_url' => $publicUrl,
        'view_url' => $publicUrl,
        'qr_data_uri' => $qrUri,
        'marketing_url' => $this->safeRouteUrl('myeventlane_event_studio.workspace_marketing', ['node' => $nid]),
        'boost_url' => $this->safeRouteUrl('myeventlane_boost.vendor_event_boost', ['event' => $nid])
        ?? $this->safeRouteUrl('myeventlane_boost.boost_page', ['node' => $nid]),
        'widgets_url' => $this->safeRouteUrl('myeventlane_tickets.event_tickets_widgets', ['event' => $nid]),
        'channels' => $this->buildShareChannels($publicUrl, $title),
      ];
    }
    return $cards;
  }

  /**
   * Builds share channel buttons for a public event URL.
   *
   * @return list<array<string, mixed>>
   *   Share channel buttons.
   */
  private function buildShareChannels(string $publicUrl, string $title): array {
    $encoded = UrlHelper::buildQuery(['u' => $publicUrl]);
    $linkedIn = 'https://www.linkedin.com/sharing/share-offsite/?' . UrlHelper::buildQuery(['url' => $publicUrl]);
    $facebook = 'https://www.facebook.com/sharer/sharer.php?' . $encoded;
    $subject = (string) $this->t('Join me at @title', ['@title' => $title]);
    $body = (string) $this->t("I thought you might like this event on MyEventLane:\n\n@url", ['@url' => $publicUrl]);
    $mailto = 'mailto:?' . UrlHelper::buildQuery([
      'subject' => $subject,
      'body' => $body,
    ]);

    return [
      [
        'key' => 'copy',
        'label' => (string) $this->t('Copy link'),
        'url' => NULL,
        'action' => 'copy',
        'analytics' => 'share_link_copied',
      ],
      [
        'key' => 'facebook',
        'label' => (string) $this->t('Facebook'),
        'url' => $facebook,
        'action' => 'external',
        'analytics' => 'share_channel_selected',
      ],
      [
        'key' => 'instagram',
        'label' => (string) $this->t('Instagram'),
        'url' => NULL,
        'action' => 'copy',
        'hint' => (string) $this->t('Copy the link, then paste it into your Instagram post or story.'),
        'analytics' => 'share_channel_selected',
      ],
      [
        'key' => 'linkedin',
        'label' => (string) $this->t('LinkedIn'),
        'url' => $linkedIn,
        'action' => 'external',
        'analytics' => 'share_channel_selected',
      ],
      [
        'key' => 'email',
        'label' => (string) $this->t('Email'),
        'url' => $mailto,
        'action' => 'external',
        'analytics' => 'share_channel_selected',
      ],
      [
        'key' => 'embed',
        'label' => (string) $this->t('Embed widget'),
        'url' => NULL,
        'action' => 'widgets',
        'analytics' => 'widget_copied',
      ],
    ];
  }

  /**
   * Builds widget deep links for managed events.
   *
   * @param list<\Drupal\node\NodeInterface> $events
   *   Managed events.
   *
   * @return list<array<string, mixed>>
   *   Widget deep links.
   */
  private function buildWidgetEvents(array $events): array {
    $rows = [];
    foreach ($events as $event) {
      $nid = (int) $event->id();
      $url = $this->safeRouteUrl('myeventlane_tickets.event_tickets_widgets', ['event' => $nid]);
      if ($url === NULL) {
        continue;
      }
      $rows[] = [
        'id' => $nid,
        'title' => (string) $event->label(),
        'url' => $url,
        'published' => $event->isPublished(),
      ];
    }
    return $rows;
  }

  /**
   * Builds Boost campaigns and eligible event lists.
   *
   * Uses the same managed-event catalogue as Share and Marketing health so
   * published events never disappear from Boost rows on this hub.
   *
   * @param list<\Drupal\node\NodeInterface> $events
   *   Managed events (same list as Share / health).
   *
   * @return array{campaigns: list<array<string, mixed>>, eligible: list<array<string, mixed>>, faq: array<string, mixed>}
   *   Boost section payload.
   */
  private function buildBoostPayload(array $events): array {
    $campaigns = [];
    $eligible = [];
    $faq = [];
    if ($this->boostHelpContent !== NULL && method_exists($this->boostHelpContent, 'getFaqContent')) {
      $faq = $this->boostHelpContent->getFaqContent();
    }

    if (!$this->moduleHandler->moduleExists('myeventlane_boost') || $this->boostManager === NULL) {
      return ['campaigns' => [], 'eligible' => [], 'faq' => $faq];
    }

    foreach ($events as $event) {
      if (!$event instanceof NodeInterface || !$event->isPublished()) {
        continue;
      }
      $nid = (int) $event->id();
      $status = ['active' => FALSE, 'end_timestamp' => NULL];
      if (method_exists($this->boostManager, 'getBoostStatusForEvent')) {
        try {
          $status = $this->boostManager->getBoostStatusForEvent($event);
        }
        catch (\Throwable $e) {
          $this->logger->warning('Marketing hub Boost status failed for @nid: @message', [
            '@nid' => (string) $nid,
            '@message' => $e->getMessage(),
          ]);
        }
      }

      $boostUrl = $this->safeRouteUrl('myeventlane_boost.vendor_event_boost', ['event' => $nid])
        ?? $this->safeRouteUrl('myeventlane_boost.boost_page', ['node' => $nid]);
      $stateLabel = NULL;
      if ($this->eventStateResolver !== NULL && method_exists($this->eventStateResolver, 'resolveState')) {
        try {
          $stateLabel = (string) $this->eventStateResolver->resolveState($event);
        }
        catch (\Throwable) {
          $stateLabel = NULL;
        }
      }

      if (!empty($status['active'])) {
        $ends = !empty($status['end_timestamp'])
          ? date('j M Y, g:ia', (int) $status['end_timestamp'])
          : NULL;
        $campaigns[] = [
          'title' => (string) $event->label(),
          'status' => (string) $this->t('Active'),
          'ends' => $ends,
          'url' => $boostUrl,
        ];
      }
      else {
        $eligible[] = [
          'title' => (string) $event->label(),
          'state' => $stateLabel,
          'url' => $boostUrl,
          'cta_label' => (string) $this->t('Start Boost'),
        ];
      }
    }

    return [
      'campaigns' => $campaigns,
      'eligible' => $eligible,
      'faq' => $faq,
    ];
  }

  /**
   * Builds the Marketing health hero.
   *
   * @return array<string, mixed>
   *   Marketing health hero.
   */
  private function buildHealth(
    int $publishedCount,
    ?array $primaryShare,
    int $boostEligible,
    int $activeBoosts,
    bool $socialReady,
    int $score,
  ): array {
    $needsAttention = $publishedCount === 0 || $primaryShare === NULL;
    $tone = $needsAttention ? 'attention' : ($score >= 75 ? 'success' : 'muted');

    $ctaAction = 'link';
    $ctaCopyUrl = NULL;
    $ctaAnalytics = NULL;

    if ($publishedCount === 0) {
      $headline = (string) $this->t('Publish an event to grow your reach');
      $summary = (string) $this->t('Marketing tools unlock once your event is live for the public.');
      $next = (string) $this->t('Finish publishing, then come back to share and Boost.');
      $ctaLabel = (string) $this->t('Go to events');
      $ctaUrl = $this->safeRouteUrl('myeventlane_vendor.console.events') ?? '/vendor/events';
    }
    elseif ($activeBoosts > 0 && is_array($primaryShare) && !empty($primaryShare['public_url'])) {
      $headline = (string) $this->t('Your events are getting visibility');
      $summary = (string) $this->t('You have live pages to share and Boost campaigns running.');
      $next = (string) $this->t('Share your link with local communities while Boost is active.');
      $ctaLabel = (string) $this->t('Copy public link');
      $ctaUrl = NULL;
      $ctaAction = 'copy';
      $ctaCopyUrl = (string) $primaryShare['public_url'];
      $ctaAnalytics = 'share_link_copied';
    }
    elseif ($boostEligible > 0) {
      $headline = (string) $this->t('Ready to reach more people');
      $summary = (string) $this->t('Your event is live. Share it now, or Boost discovery on MyEventLane.');
      $next = (string) $this->t('Copy your public link, then consider Boost if you want wider discovery.');
      $ctaLabel = (string) $this->t('Share event');
      $ctaUrl = '#share';
    }
    else {
      $headline = (string) $this->t('Keep sharing with your community');
      $summary = (string) $this->t('Your marketing basics are in place. Keep the link handy for every channel.');
      $next = $socialReady
        ? (string) $this->t('Share again this week, or check Analytics for bookings.')
        : (string) $this->t('Add social links in Settings so guests can follow you.');
      $ctaLabel = (string) $this->t('Share event');
      $ctaUrl = '#share';
    }

    return [
      'tone' => $tone,
      'headline' => $headline,
      'summary' => $summary,
      'next_step' => $next,
      'needs_attention' => $needsAttention,
      'score' => $score,
      'score_label' => (string) $this->t('Marketing score'),
      'cta_label' => $ctaLabel,
      'cta_url' => $ctaUrl,
      'cta_action' => $ctaAction,
      'cta_copy_url' => $ctaCopyUrl,
      'cta_analytics' => $ctaAnalytics,
      'facts' => [
        [
          'label' => (string) $this->t('Public status'),
          'value' => $publishedCount > 0
            ? (string) $this->formatPlural($publishedCount, '1 live event', '@count live events')
            : (string) $this->t('No live events yet'),
        ],
        [
          'label' => (string) $this->t('Sharing readiness'),
          'value' => $primaryShare !== NULL
            ? (string) $this->t('Public link ready')
            : (string) $this->t('Publish to unlock sharing'),
        ],
        [
          'label' => (string) $this->t('Boost eligibility'),
          'value' => $activeBoosts > 0
            ? (string) $this->formatPlural($activeBoosts, '1 campaign active', '@count campaigns active')
            : ($boostEligible > 0
              ? (string) $this->t('Eligible to Boost')
              : (string) $this->t('Publish a live event first')),
        ],
        [
          'label' => (string) $this->t('Social completeness'),
          'value' => $socialReady
            ? (string) $this->t('Social links added')
            : (string) $this->t('Add social links'),
        ],
      ],
    ];
  }

  /**
   * Builds recommended next-step cards.
   *
   * @return list<array<string, mixed>>
   *   Recommended next steps.
   */
  private function buildRecommendedActions(
    int $publishedCount,
    ?array $primaryShare,
    int $boostEligible,
    int $activeBoosts,
    bool $socialReady,
  ): array {
    $items = [];
    if ($publishedCount === 0) {
      $items[] = [
        'title' => (string) $this->t('Publish your first event'),
        'body' => (string) $this->t('Guests can only find and book a live page.'),
        'cta_label' => (string) $this->t('Go to events'),
        'cta_url' => $this->safeRouteUrl('myeventlane_vendor.console.events'),
      ];
      return $items;
    }

    if ($primaryShare !== NULL && !empty($primaryShare['public_url'])) {
      $items[] = [
        'title' => (string) $this->t('Share your public link'),
        'body' => (string) $this->t('Send it to friends, local groups, and your email list.'),
        'cta_label' => (string) $this->t('Copy link'),
        'cta_action' => 'copy',
        'cta_copy_url' => (string) $primaryShare['public_url'],
        'analytics' => 'share_link_copied',
      ];
    }

    if ($activeBoosts === 0 && $boostEligible > 0) {
      $items[] = [
        'title' => (string) $this->t('Consider Boost'),
        'body' => (string) $this->t('Optional paid visibility on MyEventLane discovery — community-first, never a hard sell.'),
        'cta_label' => (string) $this->t('Explore Boost'),
        'cta_url' => '#boost',
      ];
    }

    if (!$socialReady) {
      $items[] = [
        'title' => (string) $this->t('Add your social links'),
        'body' => (string) $this->t('Help guests follow you after they discover your event.'),
        'cta_label' => (string) $this->t('Open Settings'),
        'cta_url' => $this->safeRouteUrl('myeventlane_vendor.console.settings'),
      ];
    }

    $items[] = [
      'title' => (string) $this->t('Add a ticket widget'),
      'body' => (string) $this->t('Embed booking on your own site when you are ready.'),
      'cta_label' => (string) $this->t('Open widgets'),
      'cta_url' => '#widgets',
      'analytics' => 'widget_copied',
    ];

    return $items;
  }

  /**
   * Computes a simple 0–100 marketing readiness score.
   */
  private function computeMarketingScore(
    int $publishedCount,
    bool $shareReady,
    bool $boostReady,
    bool $socialReady,
    bool $boostActive,
  ): int {
    $score = 0;
    if ($publishedCount > 0) {
      $score += 35;
    }
    if ($shareReady) {
      $score += 25;
    }
    if ($boostReady) {
      $score += 15;
    }
    if ($boostActive) {
      $score += 15;
    }
    if ($socialReady) {
      $score += 10;
    }
    return min(100, $score);
  }

  /**
   * Whether the organiser profile has social links.
   */
  private function isSocialComplete(?Vendor $vendor): bool {
    if ($vendor === NULL || !$vendor->hasField('field_social_links')) {
      return FALSE;
    }
    return !$vendor->get('field_social_links')->isEmpty();
  }

  /**
   * Returns a route URL or NULL when the route is unavailable.
   */
  private function safeRouteUrl(string $routeName, array $parameters = []): ?string {
    try {
      return Url::fromRoute($routeName, $parameters)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
