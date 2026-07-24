<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\EventStateResolver;
use Drupal\myeventlane_core\Utility\UpcomingEventEntityQueryHelper;
use Drupal\myeventlane_core\MelReadinessHelper;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\myeventlane_vendor\Service\RsvpStatsService;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\myeventlane_vendor\Service\VendorPaymentsHealthService;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Organiser Analytics hub view model (Event Intelligence Centre).
 *
 * Orchestrates existing vendor metrics only. Does not invent telemetry or
 * duplicate Commerce SQL. Free pulse is always available; Pro depth is
 * value-gated in the UI (never a bare 403 on the hub route).
 */
final class VendorAnalyticsViewModelBuilder {

  use StringTranslationTrait;

  private const MAX_EVENTS = 100;

  private const DETAIL_ENRICH_LIMIT = 12;

  public function __construct(
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MetricsAggregator $metricsAggregator,
    private readonly TicketSalesService $ticketSalesService,
    private readonly RsvpStatsService $rsvpStatsService,
    private readonly EventStateResolver $eventStateResolver,
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccessManagerInterface $accessManager,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $string_translation,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly VendorPaymentsHealthService $paymentsHealth,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?object $vendorProState = NULL,
    private readonly ?object $refundsRepository = NULL,
    private readonly ?object $refundsMetrics = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the Analytics hub model.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   * @param array<string, mixed> $filters
   *   Reserved for future date-range filters.
   *
   * @return array<string, mixed>
   *   Hub contract for Twig.
   */
  public function build(AccountInterface $account, array $filters = []): array {
    unset($filters);
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return $this->emptyGuestModel();
    }

    $isPro = $this->vendorProState !== NULL && method_exists($this->vendorProState, 'isPro')
      ? (bool) $this->vendorProState->isPro()
      : FALSE;

    try {
      $kpis = $this->buildKpis($uid);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Vendor analytics KPI build failed for uid @uid: @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      $kpis = $this->fallbackKpis();
    }

    $readinessSignals = [
      'tickets_configured' => FALSE,
      'door_ready' => FALSE,
      'publishing_issues' => 0,
      'event_count' => 0,
      'load_failed' => FALSE,
    ];
    $eventsLoadFailed = FALSE;
    $cataloguePulse = [
      'checkins_total' => 0,
      'boosts_active' => 0,
    ];
    $recentActivity = [];
    $cacheEventNids = [];
    try {
      [$events, $readinessSignals, $cataloguePulse, $hubExtras] = $this->buildEventRows($uid, $account);
      $recentActivity = $hubExtras['recent_activity'] ?? [];
      $cacheEventNids = $hubExtras['cache_event_nids'] ?? [];
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Vendor analytics event rows failed for uid @uid: @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      $events = [];
      $eventsLoadFailed = TRUE;
      $readinessSignals['load_failed'] = TRUE;
      $cataloguePulse = NULL;
      // Still tag every managed event so readiness/pulse rebuild when any event changes.
      $cacheEventNids = $this->managedEventCacheNids($uid);
    }

    $refundPending = $this->countPendingRefunds();
    $paymentHealth = $this->paymentsHealth->buildForCurrentUser();
    $launchReadiness = $this->buildLaunchReadiness($readinessSignals, $paymentHealth, $refundPending);
    $businessHealth = $this->buildBusinessHealth($kpis, $events, $refundPending, $launchReadiness);
    $nextAction = $this->buildNextAction($launchReadiness, $businessHealth, $events, $account);
    $sections = $this->buildSections($kpis, $events, $refundPending, $isPro, $cataloguePulse);
    $pro = $this->buildProDepth($isPro, $account);

    $this->loggerFactory->get('myeventlane_analytics')->info('analytics_viewed uid=@uid is_pro=@pro events=@count', [
      '@uid' => (string) $uid,
      '@pro' => $isPro ? '1' : '0',
      '@count' => (string) count($events),
    ]);

    return [
      'title' => (string) $this->t('Analytics'),
      'subtitle' => (string) $this->t('Your Event Intelligence Centre — how your events are performing, and what to do next.'),
      'eyebrow' => (string) $this->t('Event Intelligence Centre'),
      'date_range' => [
        'active' => 'all',
        'items' => [],
      ],
      'is_pro' => $isPro,
      'business_health' => $businessHealth,
      'launch_readiness' => $launchReadiness,
      'kpis' => $kpis,
      'sections' => $sections,
      'events' => $events,
      'top_events' => array_slice($events, 0, 5),
      'recent_activity' => $recentActivity,
      'next_action' => $nextAction,
      'pro' => $pro,
      'insights' => [],
      'empty_state' => $this->buildEmptyState($account, $events === [] && !$eventsLoadFailed, $eventsLoadFailed),
      'cache_event_nids' => $cacheEventNids,
      'analytics' => [
        'analytics_viewed' => TRUE,
        'is_pro' => $isPro,
      ],
    ];
  }

  /**
   * Builds an empty model for anonymous visitors.
   *
   * @return array<string, mixed>
   *   Guest hub payload.
   */
  private function emptyGuestModel(): array {
    return [
      'title' => (string) $this->t('Analytics'),
      'subtitle' => (string) $this->t('Sign in to see how your events are performing.'),
      'eyebrow' => (string) $this->t('Event Intelligence Centre'),
      'date_range' => ['active' => 'all', 'items' => []],
      'is_pro' => FALSE,
      'business_health' => NULL,
      'launch_readiness' => NULL,
      'kpis' => [],
      'sections' => [],
      'events' => [],
      'top_events' => [],
      'recent_activity' => [],
      'next_action' => NULL,
      'pro' => $this->buildProDepth(FALSE, NULL),
      'insights' => [],
      'empty_state' => [
        'title' => $this->readinessHelper->vendorOrganiserPortalSignInTitle(),
        'message' => $this->readinessHelper->vendorOrganiserPortalSignInAnalyticsBody(),
        'action_label' => NULL,
        'url' => NULL,
      ],
      'cache_event_nids' => [],
      'analytics' => ['analytics_viewed' => FALSE],
    ];
  }

  /**
   * Builds the KPI strip for the hub.
   *
   * @param int $uid
   *   Organiser user ID.
   *
   * @return list<array<string, mixed>>
   *   KPI cards.
   */
  private function buildKpis(int $uid): array {
    $strip = $this->metricsAggregator->getVendorKpis($uid);
    $revenueValue = (string) ($strip[2]['value'] ?? '$0.00');
    $rsvpCount = (int) ($strip[1]['value'] ?? 0);
    $ticketsSold = (int) ($strip[3]['value'] ?? 0);
    $ticketsValue = (string) $ticketsSold;
    // Attendance = ticket holders + confirmed RSVPs (same composition as event rows).
    $attendanceValue = (string) ($ticketsSold + $rsvpCount);

    $publishedManaged = $this->userVendorMembershipQuery->getManagedEventNodeIds($uid, TRUE);
    $upcoming = $this->countUpcomingPublishedEvents($publishedManaged);

    return [
      [
        'key' => 'revenue',
        'label' => (string) $this->t('Revenue'),
        'value' => $revenueValue,
        'context' => (string) $this->t('Ticket sales after refunds'),
        'severity' => 'neutral',
      ],
      [
        'key' => 'tickets_sold',
        'label' => (string) $this->t('Tickets sold'),
        'value' => $ticketsValue,
        'context' => (string) $this->t('Completed ticket orders'),
        'severity' => 'neutral',
      ],
      [
        'key' => 'attendance',
        'label' => (string) $this->t('Attendance'),
        'value' => $attendanceValue,
        'context' => (string) $this->t('Ticket holders and confirmed RSVPs'),
        'severity' => 'neutral',
      ],
      [
        'key' => 'upcoming_events',
        'label' => (string) $this->t('Upcoming events'),
        'value' => (string) $upcoming,
        'context' => (string) $this->t('Published events starting soon or in progress'),
        'severity' => 'neutral',
      ],
    ];
  }

  /**
   * Returns unavailable KPI placeholders after a load failure.
   *
   * @return list<array<string, mixed>>
   *   Fallback KPI cards.
   */
  private function fallbackKpis(): array {
    $na = (string) $this->t('Not available yet');
    $keys = [
      'revenue' => (string) $this->t('Revenue'),
      'tickets_sold' => (string) $this->t('Tickets sold'),
      'attendance' => (string) $this->t('Attendance'),
      'upcoming_events' => (string) $this->t('Upcoming events'),
    ];
    $out = [];
    foreach ($keys as $key => $label) {
      $out[] = [
        'key' => $key,
        'label' => $label,
        'value' => $na,
        'context' => (string) $this->t('Metrics could not be loaded. Try again shortly.'),
        'severity' => 'neutral',
      ];
    }
    return $out;
  }

  /**
   * Builds the Business Health hero card.
   *
   * @param list<array<string, mixed>> $kpis
   *   KPI strip.
   * @param list<array<string, mixed>> $events
   *   Event intelligence rows.
   * @param int $refundPending
   *   Pending refund count.
   * @param array<string, mixed> $launchReadiness
   *   Launch readiness payload.
   *
   * @return array<string, mixed>
   *   Business health card.
   */
  private function buildBusinessHealth(array $kpis, array $events, int $refundPending, array $launchReadiness): array {
    $byKey = [];
    foreach ($kpis as $kpi) {
      $byKey[(string) ($kpi['key'] ?? '')] = $kpi;
    }

    $attentionItems = [];
    foreach ($launchReadiness['items'] ?? [] as $item) {
      if (($item['tone'] ?? '') === 'attention' || ($item['tone'] ?? '') === 'warning') {
        $attentionItems[] = $item['label'] ?? '';
      }
    }

    $tone = 'success';
    $headline = (string) $this->t('Your business looks healthy');
    $summary = (string) $this->t('Sales and setup are on track. Keep an eye on upcoming events.');

    if (!empty($launchReadiness['load_failed'])) {
      $tone = 'attention';
      $headline = (string) $this->t('Some insights could not load');
      $summary = (string) $this->t('We could not load your event list just now. Refresh shortly — KPI figures above may still be available.');
    }
    elseif ($attentionItems !== []) {
      $tone = 'attention';
      $headline = (string) $this->t('A few things need attention');
      $summary = (string) $this->t('Fix the items below so you can run your next event with confidence.');
    }
    elseif ($refundPending > 0) {
      $tone = 'attention';
      $headline = (string) $this->t('Refunds need a quick look');
      $summary = (string) $this->formatPlural(
        $refundPending,
        '1 refund is waiting for your review.',
        '@count refunds are waiting for your review.',
      );
    }
    elseif ($events === []) {
      $tone = 'muted';
      $headline = (string) $this->t('Ready when you are');
      $summary = (string) $this->t('Create an event to start seeing sales, attendance, and health here.');
    }

    $trend = (string) $this->t('Snapshot of your organiser account right now');

    return [
      'tone' => $tone,
      'question' => (string) $this->t('How is my business performing?'),
      'headline' => $headline,
      'summary' => $summary,
      'trend_label' => $trend,
      'metrics' => [
        [
          'label' => (string) $this->t('Revenue'),
          'value' => (string) ($byKey['revenue']['value'] ?? '$0.00'),
        ],
        [
          'label' => (string) $this->t('Tickets sold'),
          'value' => (string) ($byKey['tickets_sold']['value'] ?? '0'),
        ],
        [
          'label' => (string) $this->t('Attendance'),
          'value' => (string) ($byKey['attendance']['value'] ?? '0'),
        ],
        [
          'label' => (string) $this->t('Upcoming events'),
          'value' => (string) ($byKey['upcoming_events']['value'] ?? '0'),
        ],
        [
          'label' => (string) $this->t('Refunds to review'),
          'value' => (string) $refundPending,
        ],
      ],
    ];
  }

  /**
   * Builds the Launch Readiness operational checklist.
   *
   * Tickets / Door / publishing signals come from all managed events (not the
   * sales-sorted intelligence rows capped at MAX_EVENTS).
   *
   * @param array{tickets_configured?: bool, door_ready?: bool, publishing_issues?: int, event_count?: int, load_failed?: bool} $signals
   *   Catalogue-wide readiness signals (or load_failed after a catalogue error).
   * @param array<string, mixed> $paymentHealth
   *   Payments health payload.
   * @param int $refundPending
   *   Pending refund count.
   *
   * @return array<string, mixed>
   *   Launch readiness card.
   */
  private function buildLaunchReadiness(array $signals, array $paymentHealth, int $refundPending): array {
    $loadFailed = !empty($signals['load_failed']);
    $ticketsConfigured = !empty($signals['tickets_configured']);
    $doorReady = !empty($signals['door_ready']);
    $publishingIssues = (int) ($signals['publishing_issues'] ?? 0);
    $eventCount = (int) ($signals['event_count'] ?? 0);

    $stripeReady = !empty($paymentHealth['connected']) && empty($paymentHealth['needs_attention']);
    $stripeTone = $stripeReady ? 'success' : (!empty($paymentHealth['needs_attention']) ? 'attention' : 'muted');
    $messagesReady = $this->isMessagesBrandConfigured();

    $unavailable = (string) $this->t('Unavailable');
    $items = [
      [
        'key' => 'tickets',
        'label' => (string) $this->t('Tickets configured'),
        'detail' => $loadFailed
          ? (string) $this->t('Could not load event ticket setup just now.')
          : ($ticketsConfigured
            ? (string) $this->t('At least one event has tickets or RSVP set up.')
            : (string) $this->t('Add tickets or RSVP so people can register.')),
        'tone' => $loadFailed ? 'muted' : ($ticketsConfigured ? 'success' : 'attention'),
        'status_label' => $loadFailed
          ? $unavailable
          : ($ticketsConfigured ? (string) $this->t('Ready') : (string) $this->t('Needs attention')),
        'url' => $this->safeUrlFromRoute('myeventlane_vendor.console.events')?->toString(),
      ],
      [
        'key' => 'stripe',
        'label' => (string) $this->t('Stripe connected'),
        'detail' => (string) ($paymentHealth['summary'] ?? $this->t('Connect Stripe to get paid for ticket sales.')),
        'tone' => $stripeTone,
        'status_label' => $stripeReady
          ? (string) $this->t('Ready')
          : (string) ($paymentHealth['verification_status'] ?? $this->t('Needs attention')),
        'url' => $this->safeUrlFromRoute('myeventlane_vendor.console.payments')?->toString(),
      ],
      [
        'key' => 'messages',
        'label' => (string) $this->t('Messages ready'),
        'detail' => $messagesReady
          ? (string) $this->t('Your sender name is set for attendee updates.')
          : (string) $this->t('Set your Messages brand so guests know who is writing.'),
        'tone' => $messagesReady ? 'success' : 'attention',
        'status_label' => $messagesReady ? (string) $this->t('Ready') : (string) $this->t('Needs attention'),
        'url' => $this->safeUrlFromRoute('myeventlane_vendor.console.messages')?->toString(),
      ],
      [
        'key' => 'door',
        'label' => (string) $this->t('Door Mode ready'),
        'detail' => $loadFailed
          ? (string) $this->t('Could not confirm Door Mode readiness without your event list.')
          : ($doorReady
            ? (string) $this->t('You can check guests in from Attendees → Door Mode.')
            : (string) $this->t('Set up tickets or RSVP first, then Door Mode unlocks.')),
        'tone' => $loadFailed ? 'muted' : ($doorReady ? 'success' : 'muted'),
        'status_label' => $loadFailed
          ? $unavailable
          : ($doorReady ? (string) $this->t('Ready') : (string) $this->t('Not yet')),
        'url' => $this->safeUrlFromRoute('myeventlane_vendor.console.events')?->toString(),
      ],
      [
        'key' => 'refunds',
        'label' => $refundPending > 0
          ? (string) $this->formatPlural($refundPending, '@count refund awaiting review', '@count refunds awaiting review')
          : (string) $this->t('No refunds awaiting review'),
        'detail' => $refundPending > 0
          ? (string) $this->t('Review refund requests so guests are not left waiting.')
          : (string) $this->t('Nothing in the refund queue right now.'),
        'tone' => $refundPending > 0 ? 'attention' : 'success',
        'status_label' => $refundPending > 0 ? (string) $this->t('Needs attention') : (string) $this->t('Ready'),
        'url' => $this->safeUrlFromRoute('myeventlane_vendor.console.payments', [], ['fragment' => 'refunds'])?->toString()
        ?? $this->safeUrlFromRoute('myeventlane_vendor.console.payments')?->toString(),
      ],
      [
        'key' => 'publishing',
        'label' => $loadFailed
          ? (string) $this->t('Publishing status unavailable')
          : ($publishingIssues > 0
            ? (string) $this->formatPlural($publishingIssues, '@count draft needs publishing review', '@count drafts need publishing review')
            : (string) $this->t('No publishing issues')),
        'detail' => $loadFailed
          ? (string) $this->t('Could not load draft events just now.')
          : ($publishingIssues > 0
            ? (string) $this->t('Finish and publish drafts when you are ready to go live.')
            : (string) $this->t('No draft events are waiting on you.')),
        'tone' => $loadFailed ? 'muted' : ($publishingIssues > 0 ? 'attention' : 'success'),
        'status_label' => $loadFailed
          ? $unavailable
          : ($publishingIssues > 0 ? (string) $this->t('Needs attention') : (string) $this->t('Ready')),
        'url' => $this->safeUrlFromRoute('myeventlane_vendor.console.events')?->toString(),
      ],
    ];

    $attentionCount = count(array_filter($items, static fn(array $i): bool => ($i['tone'] ?? '') === 'attention'));
    if ($loadFailed) {
      $tone = 'attention';
      $headline = (string) $this->t('Event readiness could not be fully checked');
      $summary = (string) $this->t('We could not load your events. Stripe and Messages below still reflect live setup.');
    }
    else {
      $tone = $attentionCount > 0 ? 'attention' : ($eventCount === 0 ? 'muted' : 'success');
      $headline = match ($tone) {
        'attention' => (string) $this->t('Can I successfully run my next event today?'),
        'muted' => (string) $this->t('Can I successfully run an event today?'),
        default => (string) $this->t('Yes — you are set up to run events today'),
      };
      $summary = match ($tone) {
        'attention' => (string) $this->t('A few operational checks still need a quick fix.'),
        'muted' => (string) $this->t('Create your first event to unlock this checklist.'),
        default => (string) $this->t('Tickets, payments, messages, and door ops look ready.'),
      };
    }

    return [
      'tone' => $tone,
      'question' => (string) $this->t('Can I successfully run this event today?'),
      'headline' => $headline,
      'summary' => $summary,
      'items' => $items,
      'attention_count' => $attentionCount,
      'load_failed' => $loadFailed,
    ];
  }

  /**
   * Builds the next recommended action card.
   *
   * @param array<string, mixed> $launchReadiness
   *   Launch readiness payload.
   * @param array<string, mixed> $businessHealth
   *   Business health payload.
   * @param list<array<string, mixed>> $events
   *   Event intelligence rows.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   *
   * @return array<string, mixed>|null
   *   Next action card or NULL.
   */
  private function buildNextAction(array $launchReadiness, array $businessHealth, array $events, AccountInterface $account): ?array {
    foreach ($launchReadiness['items'] ?? [] as $item) {
      if (($item['tone'] ?? '') === 'attention' && !empty($item['url'])) {
        return [
          'title' => (string) $this->t('Next recommended action'),
          'body' => (string) ($item['detail'] ?? $item['label']),
          'cta_label' => (string) ($item['label'] ?? $this->t('Fix this')),
          'cta_url' => (string) $item['url'],
          'tone' => 'attention',
        ];
      }
    }

    if (!empty($launchReadiness['load_failed'])) {
      return [
        'title' => (string) $this->t('Next recommended action'),
        'body' => (string) $this->t('Refresh Analytics to reload your events and launch checks.'),
        'cta_label' => NULL,
        'cta_url' => NULL,
        'tone' => 'attention',
      ];
    }

    if ($events === []) {
      $url = $this->urlIfAccessible($account, 'myeventlane_vendor.create_event_gateway', []);
      return [
        'title' => (string) $this->t('Next recommended action'),
        'body' => (string) $this->t('Create your first event to start gathering sales and attendance insights.'),
        'cta_label' => (string) $this->t('Create event'),
        'cta_url' => $url?->toString(),
        'tone' => 'muted',
      ];
    }

    $top = $events[0] ?? NULL;
    if (is_array($top) && !empty($top['analytics_url'])) {
      return [
        'title' => (string) $this->t('Next recommended action'),
        'body' => (string) $this->t('Review @title — your strongest event right now.', [
          '@title' => (string) ($top['title'] ?? $this->t('your top event')),
        ]),
        'cta_label' => (string) $this->t('Open event analytics'),
        'cta_url' => (string) $top['analytics_url'],
        'tone' => 'success',
      ];
    }

    return [
      'title' => (string) $this->t('Next recommended action'),
      'body' => (string) ($businessHealth['summary'] ?? $this->t('Keep an eye on sales as your next event approaches.')),
      'cta_label' => NULL,
      'cta_url' => NULL,
      'tone' => (string) ($businessHealth['tone'] ?? 'success'),
    ];
  }

  /**
   * Builds Sales / Attendance / Revenue / Marketing / Audience sections.
   *
   * @param list<array<string, mixed>> $kpis
   *   KPI strip.
   * @param list<array<string, mixed>> $events
   *   Event intelligence rows.
   * @param int $refundPending
   *   Pending refund count.
   * @param bool $isPro
   *   Whether the organiser has Pro.
   * @param array{checkins_total?: int, boosts_active?: int}|null $cataloguePulse
   *   Catalogue-wide pulse totals (all managed events). NULL when unavailable
   *   (e.g. event rows failed to load).
   *
   * @return list<array<string, mixed>>
   *   Section cards.
   */
  private function buildSections(array $kpis, array $events, int $refundPending, bool $isPro, ?array $cataloguePulse = NULL): array {
    $byKey = [];
    foreach ($kpis as $kpi) {
      $byKey[(string) ($kpi['key'] ?? '')] = $kpi;
    }

    $capacityTotal = 0;
    $checkinsTotal = 0;
    $attendanceTotal = 0;
    foreach ($events as $row) {
      $capacityTotal += (int) ($row['capacity_raw'] ?? 0);
      $checkinsTotal += (int) ($row['checkins_raw'] ?? 0);
      $attendanceTotal += (int) ($row['attendance_raw'] ?? 0);
    }

    $boostActive = 0;
    foreach ($events as $row) {
      if (!empty($row['boost_active'])) {
        $boostActive++;
      }
    }

    // Hub Check-ins / Active Boosts must cover every managed event, not only
    // DETAIL_ENRICH_LIMIT rows from enrichTopEventRows().
    if ($cataloguePulse !== NULL) {
      $checkinsTotal = (int) ($cataloguePulse['checkins_total'] ?? $checkinsTotal);
      $boostActive = (int) ($cataloguePulse['boosts_active'] ?? $boostActive);
    }

    $sections = [
      [
        'key' => 'sales',
        'title' => (string) $this->t('Sales'),
        'summary' => (string) $this->t('Tickets sold across your events.'),
        'metrics' => [
          ['label' => (string) $this->t('Tickets sold'), 'value' => (string) ($byKey['tickets_sold']['value'] ?? '0')],
          [
            'label' => (string) $this->t('Events selling'),
            'value' => (string) count(array_filter(
              $events,
              static fn(array $e): bool => ((int) ($e['tickets_sold_raw'] ?? 0)) > 0,
            )),
          ],
        ],
        'pro_only' => FALSE,
      ],
      [
        'key' => 'attendance',
        'title' => (string) $this->t('Attendance'),
        'summary' => (string) $this->t('Who is coming, and who has checked in.'),
        'metrics' => [
          [
            'label' => (string) $this->t('Guests'),
            'value' => (string) max(
              $attendanceTotal,
              (int) ($byKey['attendance']['value'] ?? 0),
            ),
          ],
          ['label' => (string) $this->t('Check-ins'), 'value' => (string) $checkinsTotal],
        ],
        'pro_only' => FALSE,
      ],
      [
        'key' => 'revenue',
        'title' => (string) $this->t('Revenue'),
        'summary' => (string) $this->t('Money from ticket sales after refunds.'),
        'metrics' => [
          ['label' => (string) $this->t('Revenue'), 'value' => (string) ($byKey['revenue']['value'] ?? '$0.00')],
          ['label' => (string) $this->t('Refunds to review'), 'value' => (string) $refundPending],
        ],
        'pro_only' => FALSE,
      ],
      [
        'key' => 'marketing',
        'title' => (string) $this->t('Marketing'),
        'summary' => (string) $this->t('Boost and reach across your events.'),
        'metrics' => [
          ['label' => (string) $this->t('Active Boosts'), 'value' => (string) $boostActive],
        ],
        'cta_label' => (string) $this->t('Open Marketing'),
        'cta_url' => $this->safeUrlFromRoute('myeventlane_vendor.console.events')?->toString(),
        'pro_only' => FALSE,
      ],
      [
        'key' => 'audience',
        'title' => (string) $this->t('Audience'),
        'summary' => $isPro
          ? (string) $this->t('Segment your guests and compare events over time.')
          : (string) $this->t('See who is engaging with your events. Deeper segments are included in Pro.'),
        'metrics' => [
          ['label' => (string) $this->t('Events'), 'value' => (string) count($events)],
        ],
        'pro_only' => TRUE,
        'pro_teaser' => (string) $this->t('Pro unlocks audience segments, longer history, and side-by-side event comparisons.'),
      ],
    ];

    if ($capacityTotal > 0) {
      $sections[0]['metrics'][] = [
        'label' => (string) $this->t('Capacity tracked'),
        'value' => (string) $capacityTotal,
      ];
    }

    return $sections;
  }

  /**
   * Builds the recent activity list from the full event catalogue.
   *
   * Ordered by node changed time (then start date), not sales rank — the
   * intelligence table may still be sales-sorted separately.
   *
   * @param list<array<string, mixed>> $rows
   *   Uncapped event rows.
   *
   * @return list<array<string, mixed>>
   *   Recent activity items.
   */
  private function buildRecentActivity(array $rows): array {
    $sorted = $rows;
    usort($sorted, static function (array $a, array $b): int {
      $changedA = (int) ($a['changed_ts'] ?? 0);
      $changedB = (int) ($b['changed_ts'] ?? 0);
      if ($changedA !== $changedB) {
        return $changedB <=> $changedA;
      }
      return ((int) ($b['start_ts'] ?? 0)) <=> ((int) ($a['start_ts'] ?? 0));
    });

    $items = [];
    foreach (array_slice($sorted, 0, 6) as $row) {
      $bits = [];
      if (!empty($row['tickets_label'])) {
        $bits[] = (string) $row['tickets_label'];
      }
      if (!empty($row['rsvp_label'])) {
        $bits[] = (string) $row['rsvp_label'];
      }
      if (!empty($row['revenue_label'])) {
        $bits[] = (string) $row['revenue_label'];
      }
      $changedTs = (int) ($row['changed_ts'] ?? 0);
      if ($changedTs > 0) {
        $bits[] = (string) $this->t('Updated @when', [
          '@when' => $this->dateFormatter->format($changedTs, 'short'),
        ]);
      }
      $items[] = [
        'title' => (string) ($row['title'] ?? ''),
        'meta' => implode(' · ', $bits) ?: (string) ($row['status_label'] ?? ''),
        'status' => (string) ($row['status_label'] ?? ''),
        'url' => (string) ($row['analytics_url'] ?? $row['workspace_url'] ?? ''),
      ];
    }
    return $items;
  }

  /**
   * Builds the Pro depth / exports value card.
   *
   * @param bool $isPro
   *   Whether the organiser has Pro.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   Current account (reserved).
   *
   * @return array<string, mixed>
   *   Pro depth card.
   */
  private function buildProDepth(bool $isPro, ?AccountInterface $account): array {
    $upgradeUrl = NULL;
    if ($this->routeExists('myeventlane_pro.overview')) {
      $upgradeUrl = $this->safeUrlFromRoute('myeventlane_pro.overview')?->toString();
    }
    elseif ($this->routeExists('myeventlane_pro.vendor_overview')) {
      $upgradeUrl = $this->safeUrlFromRoute('myeventlane_pro.vendor_overview')?->toString();
    }

    return [
      'is_pro' => $isPro,
      'title' => $isPro
        ? (string) $this->t('Pro analytics')
        : (string) $this->t('Go deeper with Pro'),
      'body' => $isPro
        ? (string) $this->t('Exports, longer trends, and comparisons are included with your Pro plan.')
        : (string) $this->t('Analytics depth is included in Pro — longer trends, comparisons, exports, and advanced segmentation. See what’s included.'),
      'benefits' => [
        (string) $this->t('Longer-range trends'),
        (string) $this->t('Event comparisons'),
        (string) $this->t('PDF and spreadsheet exports'),
        (string) $this->t('Advanced audience segments'),
      ],
      'cta_label' => $isPro
        ? (string) $this->t('Open Pro tools')
        : (string) $this->t('See Pro analytics'),
      'cta_url' => $upgradeUrl,
      'export_label' => (string) $this->t('Exports'),
      'export_body' => $isPro
        ? (string) $this->t('Download PDF or spreadsheet reports from an event’s Analytics page.')
        : (string) $this->t('Exports are included in Pro. Upgrade to download PDF and spreadsheet reports.'),
    ];
  }

  /**
   * Counts upcoming published events for the organiser.
   *
   * @param list<int> $publishedEventIds
   *   Published managed event node IDs.
   *
   * @return int
   *   Upcoming event count.
   */
  private function countUpcomingPublishedEvents(array $publishedEventIds): int {
    if ($publishedEventIds === []) {
      return 0;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('nid', $publishedEventIds, 'IN')
        ->condition('status', 1);
      UpcomingEventEntityQueryHelper::addStartOrEndInFutureOrOngoing($query, (int) $this->time->getRequestTime());
      return (int) $query->count()->execute();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Upcoming events count failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Builds per-event intelligence rows and catalogue-wide readiness signals.
   *
   * Readiness signals are computed from every managed event before the
   * sales-sorted MAX_EVENTS cap, so low-activity drafts still affect
   * publishing / tickets / Door Mode checks.
   *
   * @param int $uid
   *   Organiser user ID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   *
   * @return array{0: list<array<string, mixed>>, 1: array{tickets_configured: bool, door_ready: bool, publishing_issues: int, event_count: int, load_failed?: bool}, 2: array{checkins_total: int, boosts_active: int}, 3: array{recent_activity: list<array<string, mixed>>, cache_event_nids: list<int>}}
   *   Capped intelligence rows, full-catalogue readiness signals,
   *   catalogue-wide Attendance / Marketing pulse totals, and hub extras
   *   (recent activity + cache node IDs for every managed event).
   */
  private function buildEventRows(int $uid, AccountInterface $account): array {
    $emptySignals = [
      'tickets_configured' => FALSE,
      'door_ready' => FALSE,
      'publishing_issues' => 0,
      'event_count' => 0,
      'load_failed' => FALSE,
    ];
    $emptyPulse = [
      'checkins_total' => 0,
      'boosts_active' => 0,
    ];
    $emptyExtras = [
      'recent_activity' => [],
      'cache_event_nids' => [],
    ];

    $ids = $this->userVendorMembershipQuery->getManagedEventNodeIds($uid, FALSE);
    $cacheEventNids = array_values(array_map(static fn($id): int => (int) $id, $ids));
    if ($ids === []) {
      return [[], $emptySignals, $emptyPulse, $emptyExtras];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    $eventNodes = [];
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface && $node->bundle() === 'event') {
        $eventNodes[] = $node;
      }
    }

    $rows = [];
    foreach ($eventNodes as $node) {
      $rows[] = $this->buildEventRow($node, $account);
    }

    $signals = $this->summariseReadinessSignals($rows);
    $cataloguePulse = [
      'checkins_total' => $this->sumCatalogueCheckIns($eventNodes),
      'boosts_active' => $this->countCatalogueActiveBoosts($eventNodes),
    ];
    // Recency feed from the full catalogue before the sales-rank MAX_EVENTS cap.
    $recentActivity = $this->buildRecentActivity($rows);
    $hubExtras = [
      'recent_activity' => $recentActivity,
      'cache_event_nids' => $cacheEventNids,
    ];

    usort($rows, static function (array $a, array $b): int {
      $scoreA = (int) ($a['_sort_tickets'] ?? 0) + (int) ($a['_sort_rsvps'] ?? 0);
      $scoreB = (int) ($b['_sort_tickets'] ?? 0) + (int) ($b['_sort_rsvps'] ?? 0);
      if ($scoreA !== $scoreB) {
        return $scoreB <=> $scoreA;
      }
      return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });

    $rows = array_slice($rows, 0, self::MAX_EVENTS);
    $this->enrichTopEventRows($rows);

    foreach ($rows as &$row) {
      unset($row['_sort_tickets'], $row['_sort_rsvps'], $row['_node'], $row['changed_ts'], $row['start_ts']);
    }
    unset($row);

    return [$rows, $signals, $cataloguePulse, $hubExtras];
  }

  /**
   * Returns managed event node IDs for hub cache tags (best-effort).
   *
   * @param int $uid
   *   Organiser user ID.
   *
   * @return list<int>
   *   Event node IDs.
   */
  private function managedEventCacheNids(int $uid): array {
    try {
      $ids = $this->userVendorMembershipQuery->getManagedEventNodeIds($uid, FALSE);
      return array_values(array_map(static fn($id): int => (int) $id, $ids));
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Managed event cache IDs failed for uid @uid: @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Sums checked-in guests across every managed event (hub Attendance pulse).
   *
   * Uses the lightweight MetricsAggregator::getCheckedInCount path — not the
   * full getEventOverview enrich reserved for top sales-ranked rows.
   *
   * @param list<\Drupal\node\NodeInterface> $eventNodes
   *   All managed event nodes.
   *
   * @return int
   *   Catalogue-wide check-in total.
   */
  private function sumCatalogueCheckIns(array $eventNodes): int {
    $total = 0;
    foreach ($eventNodes as $node) {
      try {
        $total += $this->metricsAggregator->getCheckedInCount($node);
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('myeventlane_analytics')->warning('Check-in total failed for nid @nid: @message', [
          '@nid' => (string) $node->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
    return $total;
  }

  /**
   * Counts events with an active Boost across the full catalogue.
   *
   * Hub Marketing Active Boosts must not rely on DETAIL_ENRICH_LIMIT rows.
   *
   * @param list<\Drupal\node\NodeInterface> $eventNodes
   *   All managed event nodes.
   *
   * @return int
   *   Number of events with Boost currently active.
   */
  private function countCatalogueActiveBoosts(array $eventNodes): int {
    $total = 0;
    foreach ($eventNodes as $node) {
      try {
        if ($this->metricsAggregator->isBoostActive($node)) {
          $total++;
        }
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('myeventlane_analytics')->warning('Active Boost count failed for nid @nid: @message', [
          '@nid' => (string) $node->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
    return $total;
  }

  /**
   * Summarises Launch Readiness signals from the full event-row set.
   *
   * @param list<array<string, mixed>> $rows
   *   Uncapped event rows (before MAX_EVENTS slice).
   *
   * @return array{tickets_configured: bool, door_ready: bool, publishing_issues: int, event_count: int}
   *   Catalogue-wide readiness signals.
   */
  private function summariseReadinessSignals(array $rows): array {
    $ticketsConfigured = FALSE;
    $doorReady = FALSE;
    $publishingIssues = 0;

    foreach ($rows as $row) {
      $type = (string) ($row['event_type_key'] ?? 'unknown');
      if (in_array($type, ['paid', 'rsvp', 'both'], TRUE)) {
        $ticketsConfigured = TRUE;
        $doorReady = TRUE;
      }
      if (($row['status_key'] ?? '') === 'draft') {
        $publishingIssues++;
      }
    }

    return [
      'tickets_configured' => $ticketsConfigured,
      'door_ready' => $doorReady,
      'publishing_issues' => $publishingIssues,
      'event_count' => count($rows),
    ];
  }

  /**
   * Enriches the strongest events with check-in / capacity / boost labels.
   *
   * Hub Attendance Check-ins and Marketing Active Boosts use catalogue-wide
   * pulse totals — this enrich is display-only for top sales-ranked rows.
   *
   * @param list<array<string, mixed>> $rows
   *   Event rows (modified in place).
   */
  private function enrichTopEventRows(array &$rows): void {
    $limit = min(self::DETAIL_ENRICH_LIMIT, count($rows));
    for ($i = 0; $i < $limit; $i++) {
      $node = $rows[$i]['_node'] ?? NULL;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      try {
        $overview = $this->metricsAggregator->getEventOverview($node);
        $capacity = (int) ($overview['capacity']['total'] ?? 0);
        $attendees = (int) ($overview['attendees']['total'] ?? 0);
        $checkins = (int) ($overview['attendees']['checked_in'] ?? 0);
        $rate = $overview['attendees']['check_in_rate'] ?? NULL;
        $boostActive = !empty($overview['boost']['active']);

        if ($capacity > 0) {
          $rows[$i]['capacity_label'] = (string) $this->t('@count capacity', ['@count' => $capacity]);
          $rows[$i]['capacity_raw'] = $capacity;
        }
        if ($attendees > 0) {
          $rows[$i]['attendance_label'] = (string) $this->t('@count guests', ['@count' => $attendees]);
          $rows[$i]['attendance_raw'] = $attendees;
        }
        if ($checkins > 0 || $attendees > 0) {
          $rows[$i]['checkins_label'] = (string) $this->t('@count checked in', ['@count' => $checkins]);
          $rows[$i]['checkins_raw'] = $checkins;
        }
        if (is_numeric($rate)) {
          $rows[$i]['checkin_rate_label'] = (string) $this->t('@rate% checked in', [
            '@rate' => (string) round((float) $rate, 0),
          ]);
        }
        $rows[$i]['boost_active'] = $boostActive;
        $rows[$i]['marketing_label'] = $boostActive
          ? (string) $this->t('Boost running')
          : (string) $this->t('No active Boost');
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('myeventlane_analytics')->warning('Event overview enrich failed for nid @nid: @message', [
          '@nid' => (string) $node->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Builds one event intelligence row.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   *
   * @return array<string, mixed>
   *   Event row payload.
   */
  private function buildEventRow(NodeInterface $node, AccountInterface $account): array {
    $nid = (int) $node->id();
    $published = $node->isPublished();
    $startTs = $this->getDateFieldTimestamp($node, 'field_event_start');
    $endTs = $this->getDateFieldTimestamp($node, 'field_event_end');
    $now = (int) $this->time->getRequestTime();

    if (!$published) {
      $statusKey = 'draft';
      $statusLabel = (string) $this->t('Draft');
    }
    elseif ($endTs > 0 && $endTs < $now) {
      $statusKey = 'past';
      $statusLabel = (string) $this->t('Past');
    }
    else {
      $statusKey = 'upcoming';
      $statusLabel = (string) $this->t('Upcoming');
    }

    $dateLabel = '';
    if ($startTs > 0) {
      $dateLabel = $this->dateFormatter->format($startTs, 'medium');
    }

    $domain = $this->eventStateResolver->getEventDomainState($node);
    $hasProduct = (bool) ($domain['has_product'] ?? FALSE);
    $rsvpState = (string) ($domain['rsvp_state'] ?? 'unset');
    $rsvpCapable = $rsvpState !== 'unset';

    $eventTypeKey = 'unknown';
    if ($hasProduct && $rsvpCapable) {
      $eventTypeKey = 'both';
    }
    elseif ($hasProduct) {
      $eventTypeKey = 'paid';
    }
    elseif ($rsvpCapable) {
      $eventTypeKey = 'rsvp';
    }

    $eventTypeLabel = match ($eventTypeKey) {
      'paid' => (string) $this->t('Paid tickets'),
      'rsvp' => (string) $this->t('RSVP'),
      'both' => (string) $this->t('Tickets + RSVP'),
      default => (string) $this->t('Booking mode pending'),
    };

    $salesSummary = [];
    try {
      $salesSummary = $this->ticketSalesService->getSalesSummary($node);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Analytics row sales summary failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $ticketsSold = (int) ($salesSummary['tickets_sold'] ?? 0);
    $capacity = (int) ($salesSummary['tickets_available'] ?? 0);
    $revenueLabel = isset($salesSummary['net']) ? (string) $salesSummary['net'] : ($salesSummary['gross'] ?? NULL);
    $refundLabel = NULL;
    if (!empty($salesSummary['refunded']) && ($salesSummary['refunded'] ?? '$0.00') !== '$0.00') {
      $refundLabel = (string) $salesSummary['refunded'];
    }
    $ticketsLabel = $ticketsSold > 0
      ? (string) $this->t('@count sold', ['@count' => $ticketsSold])
      : NULL;

    $rsvpCount = 0;
    try {
      $rsvpCount = $this->rsvpStatsService->getEventRsvpCount($nid);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Analytics row RSVP count failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $rsvpLabel = $rsvpCount > 0
      ? (string) $this->t('@count RSVPs', ['@count' => $rsvpCount])
      : NULL;

    $attendanceTotal = $rsvpCount + $ticketsSold;
    $attendanceLabel = NULL;
    if ($attendanceTotal > 0) {
      if ($ticketsSold > 0 && $rsvpCount > 0) {
        $attendanceLabel = (string) $this->t('@count guests', ['@count' => $attendanceTotal]);
      }
      elseif ($ticketsSold > 0) {
        $attendanceLabel = (string) $this->t('@count ticket holders', ['@count' => $ticketsSold]);
      }
      else {
        $attendanceLabel = $rsvpLabel;
      }
    }

    $healthTone = 'success';
    if ($statusKey === 'draft') {
      $healthTone = 'attention';
    }
    elseif ($ticketsSold === 0 && $rsvpCount === 0 && $statusKey === 'upcoming' && $published) {
      $healthTone = 'muted';
    }

    $analyticsUrl = $this->urlIfAccessible($account, 'myeventlane_event_studio.workspace_analytics', ['node' => $nid]);
    $deepAnalyticsUrl = $this->urlIfAccessible($account, 'myeventlane_analytics.event', ['node' => $nid]);
    $workspaceUrl = $this->urlIfAccessible($account, 'myeventlane_vendor.console.event_workspace', ['event' => $nid]);

    return [
      'nid' => $nid,
      'title' => (string) $node->getTitle(),
      'status_key' => $statusKey,
      'status_label' => $statusLabel,
      'health_tone' => $healthTone,
      'date_label' => $dateLabel,
      'event_type_key' => $eventTypeKey,
      'event_type_label' => $eventTypeLabel,
      'revenue_label' => $revenueLabel ? (string) $revenueLabel : NULL,
      'tickets_label' => $ticketsLabel,
      'tickets_sold_raw' => $ticketsSold,
      'capacity_label' => $capacity > 0 ? (string) $this->t('@count capacity', ['@count' => $capacity]) : NULL,
      'capacity_raw' => $capacity,
      'attendance_label' => $attendanceLabel,
      'attendance_raw' => $attendanceTotal,
      'checkins_label' => NULL,
      'checkins_raw' => 0,
      'refunds_label' => $refundLabel,
      'marketing_label' => NULL,
      'boost_active' => FALSE,
      'rsvp_label' => $rsvpLabel,
      'conversion_label' => NULL,
      'analytics_url' => $analyticsUrl?->toString(),
      'deep_analytics_url' => $deepAnalyticsUrl?->toString(),
      'workspace_url' => $workspaceUrl?->toString(),
      'changed_ts' => (int) $node->getChangedTime(),
      'start_ts' => $startTs,
      '_sort_tickets' => $ticketsSold,
      '_sort_rsvps' => $rsvpCount,
      '_node' => $node,
    ];
  }

  /**
   * Reads a datetime field timestamp from an event node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Event node.
   * @param string $field
   *   Field machine name.
   *
   * @return int
   *   Unix timestamp or 0.
   */
  private function getDateFieldTimestamp(NodeInterface $node, string $field): int {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return 0;
    }
    $item = $node->get($field)->first();
    if ($item && isset($item->date) && $item->date) {
      return (int) $item->date->getTimestamp();
    }
    return 0;
  }

  /**
   * Counts in-flight refunds awaiting organiser / Stripe action.
   *
   * Matches VendorPaymentsHubBuilder::buildRefundsSection() so Analytics and
   * Payments agree on pending volume and summary window.
   *
   * @return int
   *   Pending refund count.
   */
  private function countPendingRefunds(): int {
    if ($this->refundsRepository === NULL
      || $this->refundsMetrics === NULL
      || !method_exists($this->refundsRepository, 'findVendorSummary')
      || !method_exists($this->refundsMetrics, 'calculateForVendor')) {
      return 0;
    }
    try {
      $vendor = $this->vendorResolver->resolveFromCurrentUser();
      $owner = $vendor?->getOwner();
      if (!$owner) {
        return 0;
      }
      $windowDays = $this->resolveRefundSummaryWindowDays();
      $data = $this->refundsRepository->findVendorSummary((int) $owner->id(), $windowDays);
      $metrics = $this->refundsMetrics->calculateForVendor($data['logs'] ?? [], $data['requests'] ?? []);
      $byRequest = $metrics['requests_by_status'] ?? [];
      $byLog = $metrics['logs_by_status'] ?? [];
      // Buyer requests default to "requested" (awaiting organiser action).
      // "approved" means still in flight until Stripe completes.
      return (int) (
        ($byRequest['requested'] ?? 0)
        + ($byRequest['pending'] ?? 0)
        + ($byRequest['approved'] ?? 0)
        + ($byLog['pending'] ?? 0)
      );
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Analytics pending refunds failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Resolves the refund summary window used by Payments hub / refund activity.
   *
   * @return int
   *   Positive window days (defaults to 90).
   */
  private function resolveRefundSummaryWindowDays(): int {
    $days = (int) ($this->configFactory
      ->get('myeventlane_escalations_refunds.settings')
      ->get('vendor_summary_window_days') ?? 90);
    return $days > 0 ? $days : 90;
  }

  /**
   * Whether the organiser Messages brand has a sender name.
   *
   * @return bool
   *   TRUE when a sender name is available.
   */
  private function isMessagesBrandConfigured(): bool {
    try {
      $vendor = $this->vendorResolver->resolveFromCurrentUser();
      if (!$vendor instanceof Vendor) {
        return FALSE;
      }
      $from = '';
      if ($vendor->hasField('field_msg_from_name') && !$vendor->get('field_msg_from_name')->isEmpty()) {
        $from = trim((string) $vendor->get('field_msg_from_name')->value);
      }
      if ($from === '') {
        $from = trim((string) ($vendor->getName() ?? ''));
      }
      return $from !== '';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Builds the empty state when the organiser has no events.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current organiser account.
   * @param bool $noEvents
   *   TRUE when there are no event rows (and load succeeded).
   * @param bool $loadFailed
   *   TRUE when the event catalogue failed to load.
   *
   * @return array<string, string|\Drupal\Core\Url|null>
   *   Empty state payload.
   */
  private function buildEmptyState(AccountInterface $account, bool $noEvents, bool $loadFailed = FALSE): array {
    if ($loadFailed) {
      return [
        'title' => (string) $this->t('Event list unavailable'),
        'message' => (string) $this->t('We could not load your events just now. Refresh the page to try again.'),
        'action_label' => NULL,
        'url' => NULL,
      ];
    }

    if (!$noEvents) {
      return [
        'title' => '',
        'message' => '',
        'action_label' => NULL,
        'url' => NULL,
      ];
    }

    $copy = $this->readinessHelper->vendorActionNoEventsStrings();
    $url = NULL;
    if ($this->routeExists('myeventlane_vendor.create_event_gateway')) {
      try {
        if ($this->accessManager->checkNamedRoute('myeventlane_vendor.create_event_gateway', [], $account, TRUE)->isAllowed()) {
          $url = $this->safeUrlFromRoute('myeventlane_vendor.create_event_gateway');
        }
      }
      catch (\Throwable) {
        $url = NULL;
      }
    }

    return [
      'title' => $copy['title'],
      'message' => $copy['message'],
      'action_label' => $copy['action_label'],
      'url' => $url,
    ];
  }

  /**
   * Builds a route URL when the account may access it.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current account.
   * @param string $route
   *   Route name.
   * @param array<string, mixed> $parameters
   *   Route parameters.
   * @param array<string, mixed> $options
   *   URL options.
   *
   * @return \Drupal\Core\Url|null
   *   URL or NULL.
   */
  private function urlIfAccessible(AccountInterface $account, string $route, array $parameters, array $options = []): ?Url {
    if (!$this->routeExists($route)) {
      return NULL;
    }
    try {
      if (!$this->accessManager->checkNamedRoute($route, $parameters, $account, TRUE)->isAllowed()) {
        return NULL;
      }
    }
    catch (\Throwable) {
      return NULL;
    }
    return $this->safeUrlFromRoute($route, $parameters, $options);
  }

  /**
   * Builds a route URL without an access check.
   *
   * @param string $route
   *   Route name.
   * @param array<string, mixed> $parameters
   *   Route parameters.
   * @param array<string, mixed> $options
   *   URL options.
   *
   * @return \Drupal\Core\Url|null
   *   URL or NULL.
   */
  private function safeUrlFromRoute(string $route, array $parameters = [], array $options = []): ?Url {
    if (!$this->routeExists($route)) {
      return NULL;
    }
    try {
      return Url::fromRoute($route, $parameters, $options);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_analytics')->warning('Failed to build URL for route @route: @message', [
        '@route' => $route,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Checks whether a route name is registered.
   *
   * @param string $name
   *   Route name.
   *
   * @return bool
   *   TRUE if the route exists.
   */
  private function routeExists(string $name): bool {
    try {
      $this->routeProvider->getRouteByName($name);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

}
