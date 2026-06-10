<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_ai\Service\AiUsageTracker;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\Service\EntityIdNormalizer;
use Drupal\myeventlane_core\Service\EventStateResolver;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_core\Utility\UpcomingEventEntityQueryHelper;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_growth\Service\GrowthInsightService;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
use Drupal\myeventlane_pro\Service\EventInsightService;
use Drupal\myeventlane_vendor\Service\CategoryAudienceService;
use Drupal\myeventlane_vendor\Service\MetricsAggregator;
use Drupal\myeventlane_vendor\Service\RsvpStatsService;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\myeventlane_vendor\Service\TicketSalesService;
use Drupal\myeventlane_vendor\Service\VendorDashboardViewModelBuilder;
use Drupal\myeventlane_vendor\Service\VendorEventRemovalService;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\myeventlane_domain_events\ProjectionReadModel\VendorMetricsReadModel;
use Drupal\myeventlane_event_studio\DTO\MelEventData;
use Drupal\myeventlane_event_studio\Service\EventRepository;
use Drupal\myeventlane_vendor_analytics\Service\VendorKpiService;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\myeventlane_surface\MelOperationalPolicyManager;
use Drupal\myeventlane_surface\MelWorkflowManager;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Organiser dashboard controller - Full functional control centre.
 *
 * MEL LANGUAGE STANDARD: Use "Organiser" not "Vendor", "Attendee" not "Customer".
 */
final class VendorDashboardController extends VendorConsoleBaseController {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Normalizes entity IDs before loadMultiple().
   */
  protected EntityIdNormalizer $entityIdNormalizer;

  /**
   * Event read model for vendor UI (no node entities to Twig).
   */
  protected ?EventRepository $eventRepository;

  /**
   * RSVP stats service.
   */
  protected RsvpStatsService $rsvpStats;

  /**
   * Ticket sales service.
   */
  protected TicketSalesService $ticketSales;

  /**
   * Boost status service.
   */
  protected BoostStatusService $boostStatus;

  /**
   * Vendor KPI service (STAGE A2).
   */
  protected VendorKpiService $vendorKpiService;

  /**
   * Metrics aggregator for dashboard analytics overview.
   */
  protected MetricsAggregator $metricsAggregator;

  /**
   * Capacity service (STAGE B, optional).
   */
  protected ?EventCapacityServiceInterface $capacityService;

  /**
   * The onboarding manager.
   */
  protected OnboardingManager $onboardingManager;

  /**
   * The event state resolver.
   */
  protected EventStateResolverInterface $eventStateResolver;

  /**
   * Domain event state: product, RSVP/capacity, boost/promo (myeventlane_core).
   */
  protected EventStateResolver $eventDomainStateResolver;

  /**
   * AI usage tracker (optional, for vendor AI panel).
   */
  protected ?AiUsageTracker $aiUsageTracker;

  /**
   * Config factory (optional, for daily_vendor_token_limit).
   */
  protected ?ConfigFactoryInterface $configFactory;

  /**
   * Category audience service (for audience summary).
   */
  protected ?CategoryAudienceService $categoryAudienceService;

  /**
   * Vendor metrics read model.
   */
  protected ?VendorMetricsReadModel $vendorMetricsReadModel;

  /**
   * Event insight service (optional, from myeventlane_pro).
   */
  protected ?EventInsightService $insightService;

  /**
   * Growth insight service (optional).
   */
  protected ?GrowthInsightService $growthInsight;

  /**
   * Growth tracking (optional).
   */
  protected ?GrowthTrackingService $growthTracking;

  protected ?CsrfTokenGenerator $csrfToken;

  protected StateInterface $state;

  protected LoggerInterface $melDebugLogger;

  protected TimeInterface $time;

  /**
   * Route provider (detect optional myeventlane_boost routes; module may be on without routes).
   */
  protected RouteProviderInterface $routeProvider;

  /**
   * Boost lifecycle metrics façade (optional, myeventlane_boost.performance).
   *
   * @var object|null
   */
  protected mixed $boostPerformance = NULL;

  /**
   * TASK 3 dashboard view model (data-only; Twig may consume in TASK 5).
   */
  protected VendorDashboardViewModelBuilder $dashboardViewModelBuilder;

  protected VendorEventRemovalService $vendorEventRemoval;

  /**
   * Optional MELOperationalPolicySystem (suppresses duplicate upsell paths).
   */
  protected ?MelOperationalPolicyManager $operationalPolicyManager = NULL;

  /**
   * Optional MEL workflow orchestration (dedupes dashboard onboarding CTAs).
   */
  protected ?MelWorkflowManager $melWorkflowManager = NULL;

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    VendorDashboardViewModelBuilder $dashboard_view_model_builder,
    VendorEventRemovalService $vendor_event_removal,
    RouteProviderInterface $route_provider,
    RsvpStatsService $rsvp_stats,
    EntityTypeManagerInterface $entity_type_manager,
    EntityIdNormalizer $entity_id_normalizer,
    ?EventRepository $event_repository,
    BoostStatusService $boost_status,
    TicketSalesService $ticket_sales,
    VendorKpiService $vendor_kpi_service,
    MetricsAggregator $metrics_aggregator,
    ?VendorMetricsReadModel $vendor_metrics_read_model,
    ?EventCapacityServiceInterface $capacity_service,
    OnboardingManager $onboarding_manager,
    EventStateResolverInterface $event_state_resolver,
    EventStateResolver $event_domain_state_resolver,
    StateInterface $state,
    LoggerInterface $mel_debug_logger,
    TimeInterface $time,
    ?AiUsageTracker $ai_usage_tracker = NULL,
    ?ConfigFactoryInterface $config_factory = NULL,
    ?CategoryAudienceService $category_audience_service = NULL,
    ?EventInsightService $insight_service = NULL,
    ?GrowthInsightService $growth_insight = NULL,
    ?GrowthTrackingService $growth_tracking = NULL,
    ?CsrfTokenGenerator $csrf_token = NULL,
    mixed $boost_performance = NULL,
    ?MelOperationalPolicyManager $operational_policy_manager = NULL,
    ?MelWorkflowManager $mel_workflow_manager = NULL,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
    $this->dashboardViewModelBuilder = $dashboard_view_model_builder;
    $this->vendorEventRemoval = $vendor_event_removal;
    $this->routeProvider = $route_provider;
    $this->rsvpStats = $rsvp_stats;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityIdNormalizer = $entity_id_normalizer;
    $this->eventRepository = $event_repository;
    $this->boostStatus = $boost_status;
    $this->ticketSales = $ticket_sales;
    $this->vendorKpiService = $vendor_kpi_service;
    $this->metricsAggregator = $metrics_aggregator;
    $this->vendorMetricsReadModel = $vendor_metrics_read_model;
    $this->capacityService = $capacity_service;
    $this->onboardingManager = $onboarding_manager;
    $this->eventStateResolver = $event_state_resolver;
    $this->eventDomainStateResolver = $event_domain_state_resolver;
    $this->state = $state;
    $this->melDebugLogger = $mel_debug_logger;
    $this->time = $time;
    $this->aiUsageTracker = $ai_usage_tracker;
    $this->configFactory = $config_factory;
    $this->categoryAudienceService = $category_audience_service;
    $this->insightService = $insight_service;
    $this->growthInsight = $growth_insight;
    $this->growthTracking = $growth_tracking;
    $this->csrfToken = $csrf_token;
    $this->boostPerformance = $boost_performance;
    $this->operationalPolicyManager = $operational_policy_manager;
    $this->melWorkflowManager = $mel_workflow_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_vendor.dashboard_view_model_builder'),
      $container->get('myeventlane_vendor.vendor_event_removal'),
      $container->get('router.route_provider'),
      $container->get('myeventlane_vendor.service.rsvp_stats'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_core.entity_id_normalizer'),
      $container->has('myeventlane_event_studio.repository') ? $container->get('myeventlane_event_studio.repository') : NULL,
      $container->get('myeventlane_vendor.service.boost_status'),
      $container->get('myeventlane_vendor.service.ticket_sales'),
      $container->get('myeventlane_vendor_analytics.vendor_kpi'),
      $container->get('myeventlane_vendor.service.metrics_aggregator'),
      $container->has('myeventlane_domain_events.read_model.vendor_metrics') ? $container->get('myeventlane_domain_events.read_model.vendor_metrics') : NULL,
      $container->has('myeventlane_capacity.service') ? $container->get('myeventlane_capacity.service') : NULL,
      $container->get('myeventlane_onboarding.manager'),
      $container->get('myeventlane_event_state.resolver'),
      $container->get('myeventlane_core.event_state_resolver'),
      $container->get('state'),
      $container->get('logger.channel.mel_debug'),
      $container->get('datetime.time'),
      $container->has('myeventlane_ai.usage_tracker') ? $container->get('myeventlane_ai.usage_tracker') : NULL,
      $container->get('config.factory'),
      $container->has('myeventlane_vendor.service.category_audience') ? $container->get('myeventlane_vendor.service.category_audience') : NULL,
      $container->has('myeventlane_pro.event_insight') ? $container->get('myeventlane_pro.event_insight') : NULL,
      $container->has('myeventlane_growth.insight') ? $container->get('myeventlane_growth.insight') : NULL,
      $container->has('myeventlane_growth.tracking') ? $container->get('myeventlane_growth.tracking') : NULL,
      $container->get('csrf_token'),
      $container->has('myeventlane_boost.performance') ? $container->get('myeventlane_boost.performance') : NULL,
      $container->has('myeventlane_surface.operational_policy_manager') ? $container->get('myeventlane_surface.operational_policy_manager') : NULL,
      $container->has('myeventlane_surface.workflow_manager') ? $container->get('myeventlane_surface.workflow_manager') : NULL,
    );
  }

  /**
   * Displays the vendor dashboard.
   */
  public function dashboard(): array {
    $userId = (int) $this->currentUser->id();

    // Resolve vendor context for current user.
    $vendor = $this->resolveDashboardVendor($userId);
    if (!$vendor) {
      \Drupal::logger('myeventlane_vendor')->warning('Vendor dashboard context resolution failed for uid @uid.', [
        '@uid' => $userId,
      ]);
    }
    $vendorEditUrl = NULL;
    if ($vendor) {
      $vendorEditUrl = Url::fromRoute('entity.myeventlane_vendor.edit_form', [
        'myeventlane_vendor' => $vendor->id(),
      ]);
    }

    // Load vendor's events once for all queries.
    $userEvents = $this->getUserEvents($userId, $vendor);
    $eventNodes = $this->loadMelDashboardEvents($userEvents);

    // Build all dashboard data.
    $kpis = $this->buildKpiCards($eventNodes, $vendor);
    $events = $this->getEventsTableData($userEvents);
    $suppress_promotional = $this->shouldSuppressVendorDashboardPromotions();
    $melTopBoostOpportunity = $suppress_promotional ? NULL : $this->getTopBoostOpportunity($events);
    $bestEvent = $this->getBestPerformingEvent($userEvents);
    $stripeStatus = $this->getStripeConnectStatus($userId, $vendor);
    $notifications = $this->getNotifications($userId, $userEvents, $vendor);
    $accountSummary = $this->getAccountSummary($userId, $vendor);
    $quickActions = $this->getQuickActions();
    $upcomingCount = $this->getUpcomingEventsCount($this->getPublishedUserEvents($userId, $vendor));

    // Check if new vendor (show welcome banner).
    $showWelcome = empty($userEvents);

    // Boost export: visible only if vendor has at least one Boost.
    $publishedEventIds = $this->getPublishedUserEvents($userId, $vendor);
    $hasBoost = $this->vendorHasAnyBoost($publishedEventIds);
    $boostExportUrl = $hasBoost ? Url::fromRoute('myeventlane_vendor.console.boost_vendor_export')->toString() : NULL;
    $activeBoostEntitlements = $this->getActiveBoostEntitlements($userId);

    // Format stripe status message for template (phase-driven copy in stripe-panel).
    $stripeStatusFormatted = $stripeStatus;
    $melState = (string) ($stripeStatus['mel_stripe_state'] ?? 'not_connected');
    $stripeStatusFormatted['status_message'] = match ($melState) {
      'not_connected' => $this->t('Connect Stripe to receive card payments and payouts for your events.'),
      'continue_setup' => $this->t('Finish setting up your Stripe account to go live with ticket sales.'),
      'under_review' => $this->t('Stripe is reviewing your information. This can take a short time — check back soon.'),
      'action_required' => $this->t('Action is required in Stripe to keep accepting charges or receiving payouts. Review your Stripe account.'),
      'payouts_restricted' => $this->t('You can sell tickets, but bank payouts are still restricted. Complete your payout details in Stripe.'),
      'ready_to_sell' => (bool) ($stripeStatus['payouts_enabled'] ?? FALSE)
        ? $this->t('Stripe is ready: you can sell tickets and receive payouts.')
        : $this->t('Stripe is ready to process charges. Complete any remaining payout or bank details in your Stripe account.'),
      'disabled' => $this->t('This Stripe account cannot be used. Contact support or review messages in your Stripe account.'),
      default => $this->t('Connect Stripe to receive payments from ticket sales and donations.'),
    };

    // STAGE A2: KPI strip. Resolve store from vendor; call VendorKpiService; omit if no store.
    $vendorKpis = NULL;
    $store = NULL;
    if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
    }
    if ($store) {
      $range = $this->vendorKpiService->getDefaultRangeLast30Days();
      $kpi = $this->vendorKpiService->getKpisForStore($store, $range['start'], $range['end'], 'AUD');
      $currency = $kpi['currency'] ?? 'AUD';
      $revenueFormatted = $this->formatCurrencyCents((int) $kpi['revenue_net_cents'], $currency);
      $vendorKpis = [
        ['label' => $this->t('Revenue'), 'value' => $revenueFormatted, 'sub' => $this->t('Last 30 days')],
        ['label' => $this->t('Orders'), 'value' => (int) $kpi['orders_count'], 'sub' => $this->t('Completed')],
        ['label' => $this->t('Tickets sold'), 'value' => (int) $kpi['tickets_sold'], 'sub' => $this->t('Paid tickets')],
        ['label' => $this->t('RSVPs'), 'value' => (int) $kpi['rsvps_confirmed'], 'sub' => $this->t('Confirmed')],
      ];
    }

    $aiUsagePanel = NULL;
    if ($vendor && $this->aiUsageTracker && $this->configFactory) {
      $usage = $this->aiUsageTracker->getVendorUsage((int) $vendor->id());
      $limit = (int) ($this->configFactory->get('myeventlane_ai.settings')->get('daily_vendor_token_limit') ?? 200000);
      $aiEnabled = $vendor->isAiEnabled();
      $aiUsagePanel = [
        'tokens_used' => (int) ($usage['tokens'] ?? 0),
        'token_limit' => $limit,
        'ai_enabled' => $aiEnabled,
      ];
    }

    $audienceSummary = ['total_attendees' => 0, 'repeat_attendees' => 0];
    if ($this->categoryAudienceService) {
      $audienceSummary = $this->categoryAudienceService->getAudienceSummary($userId);
    }

    $pageVars = [
      'vendor_dashboard_view_model' => $this->dashboardViewModelBuilder->build($this->currentUser),
      'homepage_readiness' => $this->dashboardViewModelBuilder->buildHomepageReadinessPanel($vendor, $this->currentUser),
      'vendor' => $vendor,
      'vendor_edit_url' => $vendorEditUrl,
      'ai_usage_panel' => $aiUsagePanel,
      'audience_summary' => $audienceSummary,
      'kpis' => $kpis,
      'charts' => [],
      'events' => $events,
      'mel_top_boost_opportunity' => $melTopBoostOpportunity,
      'best_event' => $bestEvent,
      'stripe' => $stripeStatusFormatted,
      'notifications' => $notifications,
      'account' => $accountSummary,
      'quick_actions' => $quickActions,
      'upcoming_count' => $upcomingCount,
      'show_welcome' => $showWelcome,
      'has_boost' => $hasBoost,
      'boost_export_url' => $boostExportUrl,
      'active_boost_entitlements' => $activeBoostEntitlements,
      'dashboard_kpis' => $this->buildDashboardKpis($kpis),
      'dashboard_action_cards' => $this->buildDashboardActionCards(),
      'dashboard_activity_items' => $this->buildDashboardActivity($userEvents, $events),
      'dashboard_alerts' => $this->buildDashboardAlerts($stripeStatusFormatted, $eventNodes),
      'dashboard_event_performance' => array_slice($events, 0, 4),
      // Legacy dashboard template compatibility (myeventlane_theme).
      'upcoming_events' => $this->buildLegacyUpcomingEvents($events),
      'past_events' => [],
      'quick_links' => $this->buildLegacyQuickLinks($quickActions),
      'stripe_status' => $stripeStatusFormatted,
      'welcome_message' => (string) $this->t('Welcome back, @name. Here is an overview of your events.', [
        '@name' => $accountSummary['display_name'] ?? $this->currentUser->getDisplayName(),
      ]),
      'dashboard' => [
        'metrics' => $this->buildLegacyDashboardMetrics($kpis),
      ],
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/global-styling',
          'myeventlane_vendor_theme/dashboard',
          'myeventlane_vendor_theme/mel_event_card_remove',
        ],
      ],
    ];
    if ($vendorKpis !== NULL) {
      $pageVars['vendor_kpis'] = $vendorKpis;
    }

    $analyticsOverview = $this->buildAnalyticsOverview($userId, $vendor, $store);
    if ($analyticsOverview !== NULL) {
      $pageVars['analytics'] = $analyticsOverview['analytics'];
      $pageVars['analytics_cards'] = $analyticsOverview['cards'];
    }

    try {
      $pageVars['event_performance'] = $this->metricsAggregator->getEventPerformanceRows($userId);
    }
    catch (\Throwable $e) {
      $this->melDebugLogger->warning('Vendor dashboard event performance failed for uid @uid: @message', [
        '@uid' => (string) $userId,
        '@message' => $e->getMessage(),
      ]);
      $pageVars['event_performance'] = [];
    }

    // Onboarding panel and badge: only when vendor exists and not yet complete.
    $pageVars['onboarding_panel'] = NULL;
    $pageVars['show_onboarding_badge'] = FALSE;
    $pageVars['next_onboarding_route'] = NULL;
    if ($vendor && $vendor->id()) {
      try {
        $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
        if ($user instanceof UserInterface) {
          $state = $this->onboardingManager->loadOrCreateVendor($user, $vendor);
          $this->onboardingManager->refreshFlags($state);
          $is_complete = $this->onboardingManager->isCompleted($state);
          $show_panel = !$is_complete && !$this->onboardingManager->isInviteReady($state);
          if (!$is_complete) {
            $next_route = $this->onboardingManager->getNextVendorOnboardRouteForAuthenticated($state);
            $pageVars['show_onboarding_badge'] = TRUE;
            $pageVars['next_onboarding_route'] = $next_route ?: 'myeventlane_vendor.onboard.profile';
          }
          $governed_primary = $this->melWorkflowManager !== NULL
            && $this->melWorkflowManager->willRenderPrimaryWorkflowRegion();
          if ($show_panel && !$governed_primary) {
            $stage = $state->getStage();
            $stage_labels = [
              'probe' => $this->t('Get started'),
              'present' => $this->t('Profile'),
              'listen' => $this->t('Payments'),
              'ask' => $this->t('First event'),
              'invite' => $this->t('Boost'),
              'complete' => $this->t('Complete'),
            ];
            $next = $this->onboardingManager->getNextActionForAuthenticatedVendor($state);
            $pageVars['onboarding_panel'] = [
              '#theme' => 'myeventlane_vendor_onboarding_panel',
              '#stage_label' => $stage_labels[$stage] ?? $stage,
              '#flags' => $state->getFlags(),
              '#next_action' => $next,
              '#vendor' => $vendor,
            ];
          }
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('myeventlane_vendor')->warning('Onboarding panel failed on dashboard: @m', ['@m' => $e->getMessage()]);
      }
    }

    $pageVars['growth_cards'] = [];
    if (!$suppress_promotional && $this->growthInsight && $this->growthTracking) {
      $publishedIds = $this->getPublishedUserEvents($userId, $vendor);
      $publishedCount = count($publishedIds);
      $lastLoginTs = 0;
      try {
        $accountUser = $this->entityTypeManager->getStorage('user')->load($userId);
        if ($accountUser instanceof UserInterface) {
          $lastLoginTs = (int) ($accountUser->getLastLoginTime() ?? 0);
        }
      }
      catch (\Throwable) {
      }
      $growthCtx = [
        'uid' => $userId,
        'account' => $this->currentUser,
        'surface' => 'dashboard',
        'events' => $events,
        'audience_summary' => $audienceSummary,
        'published_event_count' => $publishedCount,
        'last_login_timestamp' => $lastLoginTs,
      ];
      try {
        $growthCards = $this->growthInsight->buildInsights($growthCtx);
        foreach ($growthCards as $card) {
          if (!is_array($card)) {
            continue;
          }
          $eid = isset($card['event_id']) ? (int) $card['event_id'] : NULL;
          if ($eid !== NULL && $eid <= 0) {
            $eid = NULL;
          }
          $this->growthTracking->recordImpression(
            (string) ($card['key'] ?? ''),
            $userId,
            $eid,
            'dashboard',
            ['title' => (string) ($card['title'] ?? '')],
          );
        }
        $pageVars['growth_cards'] = $growthCards;
        if ($growthCards !== [] && $this->csrfToken) {
          $pageVars['#attached']['library'][] = 'myeventlane_growth/dashboard_cards';
          $pageVars['#attached']['drupalSettings']['melGrowth'] = [
            'dismissUrl' => Url::fromRoute('myeventlane_growth.dismiss')->toString(TRUE)->getGeneratedUrl(),
            'csrfToken' => $this->csrfToken->get('rest'),
          ];
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('myeventlane_vendor')->warning('Growth cards failed on dashboard: @m', ['@m' => $e->getMessage()]);
      }
    }

    return $this->buildVendorPage('myeventlane_vendor_dashboard', $pageVars);
  }

  /**
   * Resolves the active vendor for dashboard context.
   *
   * Resolution order:
   * 1) CurrentVendorResolver/current vendor ownership resolver paths.
   * 2) Vendor linked to current user directly.
   * 3) Vendor found via the user's owned store.
   */
  private function resolveDashboardVendor(int $userId) {
    if ($userId <= 0) {
      return NULL;
    }

    // Primary path: canonical resolver on vendor module.
    $vendor = $this->getCurrentVendorOrNull();
    if ($vendor) {
      return $vendor;
    }

    try {
      $user = $this->entityTypeManager->getStorage('user')->load($userId);
      if ($user instanceof UserInterface) {
        // Common account->vendor reference field patterns.
        foreach (['field_vendor', 'field_vendor_account'] as $candidateField) {
          if ($user->hasField($candidateField) && !$user->get($candidateField)->isEmpty()) {
            $entity = $user->get($candidateField)->entity;
            if ($entity) {
              return $entity;
            }
          }
        }
      }
    }
    catch (\Throwable) {
      // Continue to store-based fallback.
    }

    // Fallback path: explicit vendor ownership/member lookups.
    try {
      $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $owner_ids = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $userId)
        ->range(0, 1)
        ->execute();
      if (!empty($owner_ids)) {
        return $vendorStorage->load((int) reset($owner_ids));
      }

      $member_ids = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_vendor_users', $userId)
        ->range(0, 1)
        ->execute();
      if (!empty($member_ids)) {
        return $vendorStorage->load((int) reset($member_ids));
      }
    }
    catch (\Throwable) {
      // Continue to store-based fallback.
    }

    // Fallback path: account -> store -> vendor.
    try {
      $store_ids = $this->entityTypeManager
        ->getStorage('commerce_store')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $userId)
        ->range(0, 1)
        ->execute();
      if (!empty($store_ids)) {
        $store_id = (int) reset($store_ids);
        $vendor_ids = $this->entityTypeManager
          ->getStorage('myeventlane_vendor')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('field_vendor_store', $store_id)
          ->range(0, 1)
          ->execute();
        if (!empty($vendor_ids)) {
          return $this->entityTypeManager->getStorage('myeventlane_vendor')->load((int) reset($vendor_ids));
        }
      }
    }
    catch (\Throwable) {
      // Fall through to NULL.
    }

    return NULL;
  }

  /**
   * Loads Mel event DTOs for known IDs (vendor read path; no node loadMultiple here).
   *
   * @param array<int, int|string> $eventIds
   *   Event IDs.
   *
   * @return list<MelEventData>
   */
  private function loadMelDashboardEvents(array $eventIds): array {
    if ($eventIds === []) {
      return [];
    }

    $eventIds = $this->entityIdNormalizer->normalizeNodeIds(array_values($eventIds));
    if ($eventIds === []) {
      return [];
    }

    if ($this->eventRepository === NULL) {
      return [];
    }

    try {
      return $this->eventRepository->loadMany($eventIds);
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Shell-level dashboard entrypoint for /dashboard and /vendor.
   */
  public function entrypointRedirect(): RedirectResponse {
    if ($this->currentUser->hasPermission('access vendor dashboard')) {
      $target = Url::fromRoute('myeventlane_vendor.console.dashboard')->toString();
      return new RedirectResponse($target, 302);
    }

    if ($this->currentUser->hasPermission('access myeventlane platform control centre')
      || $this->currentUser->hasPermission('administer site configuration')
      || $this->currentUser->id() === 1) {
      $target = Url::fromRoute('myeventlane_admin_dashboard.platform_control')->toString();
      return new RedirectResponse($target, 302);
    }

    if ($this->currentUser->isAuthenticated()) {
      $target = Url::fromRoute('myeventlane_account.dashboard')->toString();
      return new RedirectResponse($target, 302);
    }

    $target = Url::fromRoute('user.login')->toString();
    return new RedirectResponse($target, 302);
  }

  /**
   * Builds vendor analytics overview payload for Stage 1 dashboard cards.
   *
   * @return array{analytics: array<string, mixed>, cards: list<array<string, string>>}|null
   *   Analytics data and formatted cards, or NULL when vendor/store unavailable.
   */
  private function buildAnalyticsOverview(int $userId, $vendor, $store): ?array {
    if (!$vendor || !method_exists($vendor, 'id') || !$store instanceof StoreInterface) {
      return NULL;
    }

    try {
      $metrics = $this->metricsAggregator->getVendorAnalyticsOverview($userId, $vendor, $store);
    }
    catch (\Throwable $e) {
      $this->melDebugLogger->warning('Vendor dashboard analytics overview failed for uid @uid: @message', [
        '@uid' => (string) $userId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    $currency = (string) ($metrics['currency'] ?? 'AUD');
    $revenueFormatted = $this->formatCurrencyCents((int) ($metrics['revenue_net_cents'] ?? 0), $currency);
    $followConversion = (float) ($metrics['follow_conversion'] ?? 0.0);
    $boostCtr = (float) ($metrics['boost_ctr'] ?? 0.0);
    $totalEventViews = (int) ($metrics['total_event_views'] ?? 0);
    $avgTicketConversion = (float) ($metrics['avg_ticket_conversion'] ?? 0.0);

    $analytics = [
      'profile_views' => (int) ($metrics['profile_views'] ?? 0),
      'followers' => (int) ($metrics['followers'] ?? 0),
      'follow_conversion' => $followConversion,
      'revenue' => $revenueFormatted,
      'orders' => (int) ($metrics['orders_count'] ?? 0),
      'tickets_sold' => (int) ($metrics['tickets_sold'] ?? 0),
      'rsvps' => (int) ($metrics['rsvps_confirmed'] ?? 0),
      'boost_views' => (int) ($metrics['boost_views'] ?? 0),
      'boost_clicks' => (int) ($metrics['boost_clicks'] ?? 0),
      'boost_ctr' => $boostCtr,
      'total_event_views' => $totalEventViews,
      'avg_ticket_conversion' => $avgTicketConversion,
    ];

    $last30Days = (string) $this->t('Last 30 days');
    $allTime = (string) $this->t('All time');
    $completedOrders = (string) $this->t('Completed');
    $paidTickets = (string) $this->t('Paid tickets');
    $confirmedRsvps = (string) $this->t('Confirmed');
    $impressionsToClicks = (string) $this->t('Impressions → Clicks');

    $cards = [
      [
        'key' => 'profile_views',
        'label' => (string) $this->t('Profile Views'),
        'value' => number_format((int) $analytics['profile_views']),
        'sub' => $last30Days,
        'aria_label' => (string) $this->t('Profile views'),
      ],
      [
        'key' => 'followers',
        'label' => (string) $this->t('Followers'),
        'value' => number_format((int) $analytics['followers']),
        'sub' => NULL,
        'aria_label' => (string) $this->t('Followers'),
      ],
      [
        'key' => 'follow_conversion',
        'label' => (string) $this->t('Follow Conversion'),
        'value' => number_format($followConversion, 1) . '%',
        'sub' => $last30Days,
        'aria_label' => (string) $this->t('Follow conversion'),
      ],
      [
        'key' => 'total_event_views',
        'label' => (string) $this->t('Total Event Views'),
        'value' => number_format($totalEventViews),
        'sub' => $allTime,
        'aria_label' => (string) $this->t('Total event views'),
      ],
      [
        'key' => 'avg_ticket_conversion',
        'label' => (string) $this->t('Avg Ticket Conversion'),
        'value' => number_format($avgTicketConversion, 1) . '%',
        'sub' => $allTime,
        'aria_label' => (string) $this->t('Average ticket conversion'),
      ],
      [
        'key' => 'revenue',
        'label' => (string) $this->t('Revenue'),
        'value' => $revenueFormatted,
        'sub' => $last30Days,
        'aria_label' => (string) $this->t('Revenue'),
      ],
      [
        'key' => 'orders',
        'label' => (string) $this->t('Orders'),
        'value' => number_format((int) $analytics['orders']),
        'sub' => $completedOrders,
        'aria_label' => (string) $this->t('Orders'),
      ],
      [
        'key' => 'tickets_sold',
        'label' => (string) $this->t('Tickets Sold'),
        'value' => number_format((int) $analytics['tickets_sold']),
        'sub' => $paidTickets,
        'aria_label' => (string) $this->t('Tickets sold'),
      ],
      [
        'key' => 'rsvps',
        'label' => (string) $this->t('RSVPs'),
        'value' => number_format((int) $analytics['rsvps']),
        'sub' => $confirmedRsvps,
        'aria_label' => (string) $this->t('RSVPs'),
      ],
      [
        'key' => 'boost_views',
        'label' => (string) $this->t('Boost Views'),
        'value' => number_format((int) $analytics['boost_views']),
        'sub' => NULL,
        'aria_label' => (string) $this->t('Boost views'),
      ],
      [
        'key' => 'boost_clicks',
        'label' => (string) $this->t('Boost Clicks'),
        'value' => number_format((int) $analytics['boost_clicks']),
        'sub' => NULL,
        'aria_label' => (string) $this->t('Boost clicks'),
      ],
      [
        'key' => 'boost_ctr',
        'label' => (string) $this->t('Boost CTR'),
        'value' => number_format($boostCtr, 1) . '%',
        'sub' => $impressionsToClicks,
        'aria_label' => (string) $this->t('Boost click-through rate'),
      ],
    ];

    return [
      'analytics' => $analytics,
      'cards' => $cards,
    ];
  }

  /**
   * Formats cents as currency (PHP only; do not format in Twig).
   *
   * Uses NumberFormatter when available, else number_format with $ prefix.
   */
  private function formatCurrencyCents(int $cents, string $currency = 'AUD'): string {
    $amount = $cents / 100;
    if (extension_loaded('intl') && class_exists(\NumberFormatter::class)) {
      $fmt = new \NumberFormatter('en_AU', \NumberFormatter::CURRENCY);
      $formatted = $fmt->formatCurrency($amount, $currency);
      return $formatted !== FALSE ? $formatted : '$' . number_format($amount, 2);
    }
    return '$' . number_format($amount, 2);
  }

  /**
   * Get all events owned by user (includes drafts).
   *
   * Used for display purposes (event list, notifications).
   * For analytics, use getPublishedUserEvents() instead.
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Array of event node IDs. Returns empty array if no events or invalid user.
   */
  private function getUserEvents(int $userId, $vendor = NULL): array {
    if ($userId <= 0 && !$vendor) {
      return [];
    }

    try {
      $ids = [];
      $storage = $this->entityTypeManager->getStorage('node');

      if ($userId > 0) {
        $ids += $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('uid', $userId)
          ->sort('changed', 'DESC')
          ->execute();
      }

      if ($vendor && method_exists($vendor, 'id')) {
        $vendorId = (int) $vendor->id();
        if ($vendorId > 0) {
          try {
            $ids += $storage->getQuery()
              ->accessCheck(FALSE)
              ->condition('type', 'event')
              ->condition('field_event_vendor', $vendorId)
              ->sort('changed', 'DESC')
              ->execute();
          }
          catch (\Throwable) {
            // Field may not exist in this environment.
          }

          try {
            $ids += $storage->getQuery()
              ->accessCheck(FALSE)
              ->condition('type', 'event')
              ->condition('field_vendor', $vendorId)
              ->sort('changed', 'DESC')
              ->execute();
          }
          catch (\Throwable) {
            // Field may not exist in this environment.
          }
        }
      }

      return $ids;
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Get published events owned by user (excludes drafts).
   *
   * Used for analytics calculations.
   * All vendor analytics exclude draft events.
   *
   * @param int $userId
   *   The vendor user ID.
   *
   * @return array
   *   Array of published event node IDs. Returns empty array if no events or invalid user.
   */
  private function getPublishedUserEvents(int $userId, $vendor = NULL): array {
    if ($userId <= 0 && !$vendor) {
      return [];
    }

    try {
      $ids = [];
      $storage = $this->entityTypeManager->getStorage('node');

      if ($userId > 0) {
        $ids += $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'event')
          ->condition('uid', $userId)
          ->condition('status', 1)
          ->sort('changed', 'DESC')
          ->execute();
      }

      if ($vendor && method_exists($vendor, 'id')) {
        $vendorId = (int) $vendor->id();
        if ($vendorId > 0) {
          try {
            $ids += $storage->getQuery()
              ->accessCheck(FALSE)
              ->condition('type', 'event')
              ->condition('field_event_vendor', $vendorId)
              ->condition('status', 1)
              ->sort('changed', 'DESC')
              ->execute();
          }
          catch (\Throwable) {
            // Field may not exist in this environment.
          }

          try {
            $ids += $storage->getQuery()
              ->accessCheck(FALSE)
              ->condition('type', 'event')
              ->condition('field_vendor', $vendorId)
              ->condition('status', 1)
              ->sort('changed', 'DESC')
              ->execute();
          }
          catch (\Throwable) {
            // Field may not exist in this environment.
          }
        }
      }

      return $ids;
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Checks whether any of the given events have at least one Boost order item.
   *
   * @param array $eventIds
   *   Event node IDs.
   *
   * @return bool
   *   TRUE if any event has a Boost order item.
   */
  private function vendorHasAnyBoost(array $eventIds): bool {
    if (empty($eventIds)) {
      return FALSE;
    }

    try {
      $count = $this->entityTypeManager
        ->getStorage('commerce_order_item')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'boost')
        ->condition('field_target_event', $eventIds, 'IN')
        ->count()
        ->execute();
      return (int) $count > 0;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Safely gets the order from an order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order entity, or NULL if not available.
   */
  private function getOrderFromItem(OrderItemInterface $order_item) {
    if (!$order_item->hasField('order_id') || $order_item->get('order_id')->isEmpty()) {
      return NULL;
    }

    // Get the target_id from field value to avoid triggering Commerce's lazy loading.
    // Access the field value directly without triggering entity loading.
    $field_value = $order_item->get('order_id')->getValue();
    if (empty($field_value) || !isset($field_value[0]['target_id'])) {
      return NULL;
    }

    $order_id = $field_value[0]['target_id'];
    if (!$order_id) {
      return NULL;
    }

    try {
      return $this->entityTypeManager
        ->getStorage('commerce_order')
        ->load($order_id);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Build comprehensive KPI card data.
   *
   * Uses TicketSalesService and RsvpStatsService to avoid duplicate calculations.
   * All metrics exclude draft events (only published events are counted).
   * Metrics:
   * - Total Revenue: Gross revenue from completed orders (published events only)
   * - Last 30 Days Revenue: Revenue from orders completed in last 30 days
   * - Upcoming Events: Published events with start date in the future
   * - Total Events: All events (published + drafts) for context
   * - Tickets Sold: Total tickets from completed orders (published events only)
   * - RSVPs: Total confirmed RSVPs (published events only)
   *
   * @param int $userId
   *   The vendor user ID.
   * @param array $userEvents
   *   All events (includes drafts) - used for total event count display only.
   *
   * @return array
   *   Array of KPI card arrays. Returns empty array if services unavailable.
   */
  private function buildKpiCards(array $eventNodes, $vendor = NULL): array {
    // Defensive guard: ensure services are available.
    if (!$this->rsvpStats || !$this->ticketSales) {
      return [];
    }

    if (empty($eventNodes)) {
      return [];
    }

    $publishedNodes = array_values(array_filter(
      $eventNodes,
      static fn($e): bool => $e instanceof MelEventData && $e->published
    ));

    $vendorId = ($vendor && method_exists($vendor, 'id')) ? (int) $vendor->id() : 0;
    $useReadModel = $vendorId > 0 && $this->vendorMetricsReadModel !== NULL;
    $vendorMetrics = $useReadModel ? $this->vendorMetricsReadModel->getVendorMetrics($vendorId) : [];

    $totalRevenue = (float) ($vendorMetrics['total_revenue'] ?? 0.0);
    $ticketsSold = (int) ($vendorMetrics['tickets_sold'] ?? 0);
    $activeEvents = (int) ($vendorMetrics['active_events'] ?? 0);
    $totalRsvps = 0;
    $last30DaysRevenue = 0.0;
    $thirtyDaysAgo = strtotime('-30 days');

    foreach ($publishedNodes as $eventDto) {
      if (!$eventDto instanceof MelEventData) {
        continue;
      }
      $eventId = $eventDto->id;
      if (!$useReadModel) {
        try {
          $salesSummary = $this->ticketSales->getSalesSummaryForEventId($eventId);
          $eventRevenue = (float) ($salesSummary['gross_raw'] ?? 0.0);
          $totalRevenue += $eventRevenue;
          $ticketsSold += (int) ($salesSummary['tickets_sold'] ?? 0);

          if ($eventDto->changed >= (int) $thirtyDaysAgo) {
            $last30DaysRevenue += $eventRevenue;
          }
        }
        catch (\Throwable) {
          // Keep dashboard resilient; continue with remaining events.
        }
      }

      try {
        $totalRsvps += $this->rsvpStats->getEventRsvpCount($eventId);
      }
      catch (\Throwable) {
        // Keep dashboard resilient; continue with remaining events.
      }
    }

    if (!$useReadModel) {
      $activeEvents = $this->getUpcomingEventsCount(array_map(
        static fn(MelEventData $e): int => $e->id,
        $publishedNodes
      ));
    }
    $totalAttendees = $ticketsSold + $totalRsvps;

    return [
      [
        'key' => 'total_revenue',
        'label' => 'Total revenue',
        'value' => number_format($totalRevenue, 0),
        'currency' => '$',
        'icon' => 'revenue',
        'color' => 'coral',
        'delta' => $last30DaysRevenue > 0 ? [
          'value' => '$' . number_format($last30DaysRevenue, 0),
          'label' => 'last 30 days',
          'positive' => TRUE,
        ] : NULL,
        'highlight' => TRUE,
      ],
      [
        'key' => 'active_events',
        'label' => 'Active events',
        'value' => (string) $activeEvents,
        'icon' => 'calendar',
        'color' => 'blue',
        'delta' => [
          'value' => (string) count($eventNodes),
          'label' => 'total events',
          'positive' => TRUE,
        ],
      ],
      [
        'key' => 'tickets_sold',
        'label' => 'Tickets sold',
        'value' => (string) $ticketsSold,
        'icon' => 'tickets',
        'color' => 'green',
        'delta' => NULL,
      ],
      [
        'key' => 'total_attendees',
        'label' => 'Total attendees',
        'value' => (string) $totalAttendees,
        'icon' => 'users',
        'color' => 'purple',
        'delta' => [
          'value' => (string) $totalRsvps,
          'label' => 'RSVPs included',
          'positive' => TRUE,
        ],
      ],
      [
        'key' => 'rsvps',
        'label' => 'RSVPs',
        'value' => (string) $totalRsvps,
        'icon' => 'users',
        'color' => 'purple',
        'delta' => NULL,
      ],
    ];
  }

  /**
   * Get upcoming events count (published events that are future or ongoing).
   *
   * Counts: Published events (status=1) with start date or end date >= now.
   * Excludes: Draft events, past events.
   * Tables: node (event).
   *
   * @param array $eventIds
   *   Array of event node IDs (should be published events only).
   *
   * @return int
   *   Count of upcoming events. Returns 0 if no events, empty array, or on error.
   */
  private function getUpcomingEventsCount(array $eventIds): int {
    if (empty($eventIds)) {
      return 0;
    }

    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');

      $query = $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('nid', $eventIds, 'IN')
        ->condition('status', 1);
      UpcomingEventEntityQueryHelper::addStartOrEndInFutureOrOngoing($query, (int) $this->time->getRequestTime());
      return (int) $query
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      $this->melDebugLogger->error('Unable to count upcoming dashboard events: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Whether a named route is registered (handles module on but router stale/partial).
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

  /**
   * Get events table data with full details.
   *
   * Loads event nodes and builds table data including revenue, tickets, RSVPs.
   * Uses TicketSalesService and RsvpStatsService to avoid duplicate calculations.
   * Includes both published and draft events for display purposes.
   *
   * @param array $userEvents
   *   Array of event node IDs (can include drafts).
   *
   * @return array
   *   Array of event data arrays. Returns empty array if no events, services unavailable,
   *   or on error. Each event array includes: id, title, venue, date, status, revenue,
   *   tickets_sold, rsvps, boost data, URLs, etc.
   */
  private function getEventsTableData(array $userEvents): array {
    if (empty($userEvents)) {
      return [];
    }

    // Guard against null services during container rebuilds.
    if (!$this->boostStatus || !$this->ticketSales || !$this->rsvpStats) {
      return [];
    }

    if ($this->eventRepository === NULL) {
      return [];
    }

    try {
      $normalizedEvents = $this->entityIdNormalizer->normalizeNodeIds(array_values($userEvents));
      $melEvents = $normalizedEvents === [] ? [] : $this->eventRepository->loadMany($normalizedEvents);
    }
    catch (\Exception $e) {
      // Entity storage may fail during container rebuilds.
      return [];
    }

    if ($melEvents === []) {
      return [];
    }

    $events = [];

    $vendorBoostRouteOk = $this->routeExists('myeventlane_boost.vendor_event_boost');
    $boostWizardStep1Ok = $this->routeExists('myeventlane_boost.wizard.step1');

    foreach ($melEvents as $dto) {
      if (!$dto instanceof MelEventData) {
        continue;
      }

      $eventId = $dto->id;
      $eventNode = NULL;
      try {
        $loaded = $this->entityTypeManager->getStorage('node')->load($eventId);
        if ($loaded instanceof NodeInterface) {
          $eventNode = $loaded;
        }
      }
      catch (\Throwable) {
        $eventNode = NULL;
      }
      $eventDomainState = $eventNode instanceof NodeInterface
        ? $this->eventDomainStateResolver->getEventDomainState($eventNode)
        : [
          'has_product' => FALSE,
          'is_boosted' => FALSE,
          'rsvp_state' => 'unset',
          'capacity' => 0,
        ];
      $thumb = $eventNode instanceof NodeInterface
        ? $this->vendorEventRemoval->buildEventThumbnailData($eventNode)
        : $this->vendorEventRemoval->buildPlaceholderThumbnailData($dto->title);
      $eventImageUrl = $thumb['url'];

      $startDate = $dto->start_timestamp > 0 ? date('M j, Y', $dto->start_timestamp) : '';
      $startTimestamp = $dto->start_timestamp;
      $endTimestamp = $dto->end_timestamp;

      $venue = $dto->venue_label;

      $status = 'draft';
      $statusLabel = 'Draft';
      if ($dto->published) {
        if ($startTimestamp > 0 && $startTimestamp < time()) {
          $status = 'past';
          $statusLabel = 'Past';
        }
        else {
          $status = 'on-sale';
          $statusLabel = 'On Sale';
        }
      }

      $eventState = $dto->lifecycle_state;

      $salesSummary = [];
      try {
        if ($this->ticketSales) {
          $salesSummary = $this->ticketSales->getSalesSummaryForEventId($eventId);
        }
      }
      catch (\Exception $e) {
        // Service may fail, use defaults.
      }
      $revenue = $salesSummary['gross_raw'] ?? 0.0;
      $ticketsSold = $salesSummary['tickets_sold'] ?? 0;

      $rsvps = 0;
      try {
        if ($this->rsvpStats) {
          $rsvps = $this->rsvpStats->getEventRsvpCount($eventId);
        }
      }
      catch (\Exception $e) {
        // Service may fail, use default 0.
      }

      $capacity = $dto->capacity;
      $pctFilled = NULL;
      $isSoldOut = $dto->is_sold_out;
      if ($capacity !== NULL && $capacity > 0) {
        $filled = $ticketsSold + $rsvps;
        $pctFilled = (float) round($filled / $capacity * 100, 1);
      }
      // Bar width for progress (0–100); no math in Twig.
      $barWidth = NULL;
      if ($pctFilled !== NULL) {
        $barWidth = min(100.0, $pctFilled);
      }
      $percentSold = $barWidth !== NULL ? round($barWidth, 1) : 0;

      // Post-event: event has ended (field_event_end < now).
      $now = time();
      $isEnded = $endTimestamp > 0 && $endTimestamp < $now;

      // STAGE B: Status badge. Sold out overrides; else Draft, Upcoming, Past.
      $statusBadge = 'draft';
      if ($isSoldOut) {
        $statusBadge = 'sold-out';
      }
      elseif ($dto->published) {
        $statusBadge = $startTimestamp > time() ? 'upcoming' : 'past';
      }
      $statusBadgeLabels = [
        'sold-out' => 'Sold out',
        'draft' => 'Draft',
        'upcoming' => 'Upcoming',
        'past' => 'Past',
      ];
      $statusBadgeLabel = $this->t($statusBadgeLabels[$statusBadge] ?? $statusBadge);
      $filledCount = $ticketsSold + $rsvps;

      // STAGE B: Export CSV URL (attendees), if route exists.
      $exportCsvUrl = NULL;
      try {
        $exportCsvUrl = Url::fromRoute('myeventlane_event_attendees.vendor_export', [
          'node' => $eventId,
        ])->toString();
      }
      catch (\Exception $e) {
        // Route or module may not exist.
      }

      // Get waitlist analytics.
      $waitlistAnalytics = $this->getEventWaitlistAnalytics($eventId);

      // Get RSVP stats and boost status with defensive checks.
      try {
        $stats = $this->rsvpStats->getStatsForEvent($eventId);
      }
      catch (\Exception $e) {
        $stats = ['total' => 0, 'recent' => 0];
      }

      try {
        $boostData = $this->boostStatus->getBoostStatuses($eventId);
      }
      catch (\Exception $e) {
        $boostData = [
          'eligible' => FALSE,
          'active' => FALSE,
          'reason' => 'error',
        ];
      }

      $isBoosted = !empty($boostData['active']);
      $isPublished = $dto->published;
      $isEligible = !empty($boostData['eligible']);

      // Build boost button data with proper state handling.
      $boost = [
        'allowed' => $isPublished && $isEligible,
        'label' => $isBoosted ? 'Boost active' : ($isPublished ? 'Boost event' : 'Publish to boost'),
        'url' => ($isPublished && $isEligible && $vendorBoostRouteOk)
          ? Url::fromRoute('myeventlane_boost.vendor_event_boost', ['event' => $eventId])->toString()
          : NULL,
        'is_boosted' => $isBoosted,
        'message' => !$isPublished ? 'Publish this event to enable boosting.' : ($boostData['reason'] ?? NULL),
      ];

      // Boost wizard entrypoint (Step 1), per event. Only if user has boost
      // permission, event is published, and event meets eligibility.
      $boostWizardUrl = NULL;
      if ($boostWizardStep1Ok && $isPublished && $isEligible && $this->currentUser->hasPermission('purchase boost for events')) {
        $boostWizardUrl = Url::fromRoute('myeventlane_boost.wizard.step1', ['event' => $eventId])->toString();
      }

      if ($this->boostPerformance !== NULL) {
        $boostPerformancePayload = [];
        try {
          if ($eventNode instanceof NodeInterface) {
            $boostPerformancePayload = $this->boostPerformance->getPerformanceForEvent($eventNode);
          }
        }
        catch (\Throwable $e) {
          \Drupal::logger('myeventlane_vendor')->warning('Boost performance payload failed for event @nid: @message', [
            '@nid' => (string) $eventId,
            '@message' => $e->getMessage(),
          ]);
        }
        $boost['performance'] = $boostPerformancePayload;
      }

      $isSeriesTemplate = $dto->is_series_template;

      $eventRow = [
        'id' => $eventId,
        'title' => $dto->title,
        'image' => $eventImageUrl,
        'is_series_template' => $isSeriesTemplate,
        'venue' => $venue,
        'date' => $startDate,
        'start_timestamp' => $startTimestamp,
        'end_timestamp' => $endTimestamp,
        'is_ended' => $isEnded,
        'percent_sold' => $percentSold,
        'attendees_url' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $eventId])->toString(),
        'duplicate_url' => Url::fromRoute('myeventlane_event.duplicate', ['node' => $eventId])->toString(),
        'status' => $status,
        'status_label' => $statusLabel,
        'status_badge' => $statusBadge,
        'status_badge_label' => $statusBadgeLabel,
        'mel_event_state' => $eventState,
        'filled_count' => $filledCount,
        'revenue' => $revenue,
        'revenue_formatted' => '$' . number_format($revenue, 0),
        'tickets_sold' => $ticketsSold,
        'rsvps' => $rsvps,
        'capacity' => $capacity,
        'pct_filled' => $pctFilled,
        'bar_width' => $barWidth,
        'is_sold_out' => $isSoldOut,
        'export_csv_url' => $exportCsvUrl,
        'waitlist' => $waitlistAnalytics,
        'rsvp' => $stats,
        'event_domain_state' => $eventDomainState,
        'boost' => $boost,
        'boost_wizard_url' => $boostWizardUrl,
        'view_url' => Url::fromRoute('entity.node.canonical', ['node' => $eventId])->toString(),
        'edit_url' => Url::fromRoute('myeventlane_event_studio.edit', ['node' => $eventId])->toString(),
        'manage_url' => '/vendor/events/' . $eventId . '/overview',
        'tickets_url' => '/vendor/events/' . $eventId . '/tickets',
        'analytics_url' => $this->safeRouteUrl('myeventlane_vendor.console.event_analytics', ['event' => $eventId]) ?? '',
        'attendees_url' => '/vendor/events/' . $eventId . '/attendees',
        'waitlist_url' => '/vendor/event/' . $eventId . '/waitlist',
        'series_url' => $isSeriesTemplate ? Url::fromRoute('myeventlane_vendor.manage_event.series', ['event' => $eventId])->toString() : NULL,
      ];

      if ($this->insightService) {
        $insightData = [
          'id' => $eventId,
          'tickets_sold' => $ticketsSold,
          'capacity' => $capacity,
          'percent_sold' => $percentSold,
          'is_published' => $dto->published,
          'boost_allowed' => $boost['allowed'],
          'boost_url' => $boost['url'],
          'boost_wizard_url' => $boostWizardUrl,
        ];
        $eventRow['insights'] = $this->insightService->buildInsights($insightData);
      }
      else {
        $eventRow['insights'] = [];
      }

      $events[] = $eventRow;
    }

    // Sort by start date descending (newest first).
    usort($events, fn($a, $b) => $b['start_timestamp'] <=> $a['start_timestamp']);

    return $events;
  }

  /**
   * Selects the single best boost opportunity from pre-built event rows.
   *
   * Uses only BoostPerformanceService output under boost.performance (no
   * recomputation, no extra analytics calls).
   *
   * @param array<int, array<string, mixed>> $events
   *   Event rows from getEventsTableData().
   *
   * @return array<string, mixed>|null
   *   Payload for mel_top_boost_opportunity, or NULL when none qualify.
   */
  private function getTopBoostOpportunity(array $events): ?array {
    $candidates = [];
    foreach ($events as $row) {
      if (!is_array($row) || empty($row['id'])) {
        continue;
      }
      $performance = $row['boost']['performance'] ?? NULL;
      if (!is_array($performance)) {
        continue;
      }
      $ui = $performance['ui'] ?? [];
      $forceVisible = $this->isMelDevUiFallbackEnabled();
      if (empty($ui['show']) && !$forceVisible) {
        continue;
      }
      if ((bool) $this->state->get('mel.debug_boost_candidates', FALSE)) {
        // Explicit opt-in only (TASK 15): mel.dev_mode may affect UI without flooding watchdog.
        $this->melDebugLogger->debug('BOOST CANDIDATE → @title | show=@show', [
          '@title' => (string) ($row['title'] ?? 'unknown'),
          '@show' => !empty($ui['show']) ? 'YES' : 'NO',
        ]);
      }
      $cta = $ui['cta'] ?? [];
      if (empty($cta['url']) || ($cta['label'] ?? '') === '') {
        if (!$forceVisible) {
          continue;
        }
      }
      $candidates[] = $row;
    }

    if ($candidates === []) {
      return NULL;
    }

    usort($candidates, [$this, 'compareTopBoostOpportunityRows']);

    $best = $candidates[0];
    $performance = $best['boost']['performance'] ?? [];
    if (!is_array($performance)) {
      return NULL;
    }

    return [
      'event_id' => (int) $best['id'],
      'title' => (string) ($best['title'] ?? ''),
      'image' => $best['image'] ?? NULL,
      'performance' => $performance,
    ];
  }

  /**
   * Dev/staging: align with BoostPerformanceService visibility (state mel.dev_mode only).
   */
  private function isMelDevUiFallbackEnabled(): bool {
    return (bool) $this->state->get('mel.dev_mode', FALSE);
  }

  /**
   * Sort callback: action_state, confidence_level, then impact from metrics only.
   *
   * @param array<string, mixed> $a
   * @param array<string, mixed> $b
   */
  private function compareTopBoostOpportunityRows(array $a, array $b): int {
    $pa = $a['boost']['performance'] ?? [];
    $pb = $b['boost']['performance'] ?? [];
    if (!is_array($pa)) {
      $pa = [];
    }
    if (!is_array($pb)) {
      $pb = [];
    }

    $cmp = $this->compareBoostActionStatePriority(
      (string) ($pa['action_state'] ?? 'do_nothing'),
      (string) ($pb['action_state'] ?? 'do_nothing'),
    );
    if ($cmp !== 0) {
      return $cmp;
    }

    $cmp = $this->compareBoostConfidencePriority(
      (string) ($pa['confidence_level'] ?? 'low'),
      (string) ($pb['confidence_level'] ?? 'low'),
    );
    if ($cmp !== 0) {
      return $cmp;
    }

    $ia = $this->impactScoreFromBoostPerformanceMetrics($pa);
    $ib = $this->impactScoreFromBoostPerformanceMetrics($pb);

    return $ib <=> $ia;
  }

  /**
   * Lower return value = higher priority (sort ascending).
   */
  private function compareBoostActionStatePriority(string $a, string $b): int {
    $rank = [
      'boost_again' => 0,
      'fix_event' => 1,
      'do_nothing' => 2,
    ];
    return ($rank[$a] ?? 3) <=> ($rank[$b] ?? 3);
  }

  /**
   * Lower return value = higher priority (sort ascending).
   */
  private function compareBoostConfidencePriority(string $a, string $b): int {
    $rank = [
      'high' => 0,
      'medium' => 1,
      'low' => 2,
    ];
    return ($rank[$a] ?? 3) <=> ($rank[$b] ?? 3);
  }

  /**
   * Impact tie-breaker from existing performance.metrics only (no new queries).
   *
   * @param array<string, mixed> $performance
   *   boost.performance array from BoostPerformanceService.
   */
  private function impactScoreFromBoostPerformanceMetrics(array $performance): float {
    $metrics = $performance['metrics'] ?? [];
    if (!is_array($metrics)) {
      $metrics = [];
    }
    $svBefore = (float) ($metrics['sales_velocity_before'] ?? 0.0);
    $svAfter = (float) ($metrics['sales_velocity_after'] ?? 0.0);
    $delta = abs($svAfter - $svBefore);
    $ticketsBaseline = (int) ($metrics['tickets_before'] ?? 0)
      + (int) ($metrics['tickets_during'] ?? 0)
      + (int) ($metrics['tickets_after'] ?? 0);

    return ($delta * 10000.0) + (float) $ticketsBaseline;
  }

  /**
   * Extracts event image URL for dashboard event cards.
   *
   * Supports direct file references and media image references.
   */
  private function extractEventImageUrl(NodeInterface $event): ?string {
    if (!$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return NULL;
    }

    $image_item = $event->get('field_event_image')->first();
    if (!$image_item || !isset($image_item->entity) || !$image_item->entity) {
      return NULL;
    }

    $image_entity = $image_item->entity;
    if (method_exists($image_entity, 'createFileUrl')) {
      return $image_entity->createFileUrl();
    }

    if (method_exists($image_entity, 'hasField') && $image_entity->hasField('field_media_image') && !$image_entity->get('field_media_image')->isEmpty()) {
      $media_image = $image_entity->get('field_media_image')->entity;
      if ($media_image && method_exists($media_image, 'createFileUrl')) {
        return $media_image->createFileUrl();
      }
    }

    return NULL;
  }

  /**
   * Safely gets event revenue using TicketSalesService.
   *
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft/cancelled/refunded orders.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return float
   *   Total revenue. Returns 0.0 if service unavailable, no sales, or on error.
   */
  private function getEventRevenueSafe(NodeInterface $event): float {
    if (!$this->ticketSales) {
      return 0.0;
    }

    try {
      $salesSummary = $this->ticketSales->getSalesSummary($event);
      return $salesSummary['gross_raw'] ?? 0.0;
    }
    catch (\Exception $e) {
      return 0.0;
    }
  }

  /**
   * Safely gets event tickets sold using TicketSalesService.
   *
   * Counts: Completed orders only (order state='completed').
   * Excludes: Draft/cancelled/refunded orders.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return int
   *   Total tickets sold. Returns 0 if service unavailable, no sales, or on error.
   */
  private function getEventTicketsSoldSafe(NodeInterface $event): int {
    if (!$this->ticketSales) {
      return 0;
    }

    try {
      $salesSummary = $this->ticketSales->getSalesSummary($event);
      return $salesSummary['tickets_sold'] ?? 0;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Get best performing event.
   */
  private function getBestPerformingEvent(array $userEvents): ?array {
    if (empty($userEvents)) {
      return NULL;
    }

    $events = $this->getEventsTableData($userEvents);
    if (empty($events)) {
      return NULL;
    }

    // Calculate score for each event.
    // Score = tickets_sold * 0.7 + revenue * 0.2 + rsvps * 0.1.
    $bestEvent = NULL;
    $bestScore = 0;

    foreach ($events as $event) {
      $score = ($event['tickets_sold'] * 0.7)
        + ($event['revenue'] * 0.002) // Normalize revenue
        + ($event['rsvps'] * 0.1);

      if ($score > $bestScore) {
        $bestScore = $score;
        $bestEvent = $event;
      }
    }

    if ($bestEvent) {
      $bestEvent['score'] = $bestScore;
      // Calculate conversion rate (placeholder - would need views data).
      $bestEvent['conversion_rate'] = NULL;
    }

    return $bestEvent;
  }

  /**
   * Uses MELOperationalPolicySystem suppression instead of ad hoc Twig checks.
   */
  private function shouldSuppressVendorDashboardPromotions(): bool {
    if ($this->operationalPolicyManager === NULL) {
      return FALSE;
    }
    try {
      $interpretation = $this->operationalPolicyManager->buildPageInterpretation();
      $suppression = $interpretation['suppression'] ?? [];
      if (!is_array($suppression)) {
        return FALSE;
      }
      return !empty($suppression['suppress_boost_prompts'])
        || !empty($suppression['suppress_marketing_guidance'])
        || !empty($suppression['suppress_cross_sell_guidance']);
    }
    catch (\Throwable $e) {
      $this->melDebugLogger->warning('Vendor dashboard operational policy read failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Get Stripe Connect status for vendor.
   *
   * Uses same criteria as assertStripeConnected: vendor's store must have
   * field_stripe_connected or field_stripe_charges_enabled. Account ID alone
   * is insufficient (callback may not have run).
   *
   * @param int $userId
   *   The user ID.
   * @param \Drupal\myeventlane_vendor\Entity\Vendor|null $vendor
   *   The vendor entity, or NULL.
   *
   * @return array
   *   Status array with connected, status, status_label, account_id, etc.
   */
  private function getStripeConnectStatus(int $userId, $vendor = NULL): array {
    $status = [
      'connected' => FALSE,
      'status' => 'not_connected',
      'status_label' => (string) $this->t('Not connected'),
      'account_id' => NULL,
      'next_payout_date' => NULL,
      'total_paid_out' => 0,
      'pending_balance' => 0,
      'stripe_dashboard_url' => NULL,
      'connect_url' => '/stripe/connect',
      'charges_enabled' => FALSE,
      'payouts_enabled' => FALSE,
      'is_onboarding_complete' => FALSE,
      'stripe_phase' => 'needs_connect',
      'mel_stripe_state' => 'not_connected',
      'primary_action' => 'connect',
      'action_url' => NULL,
      'manage_stripe_url' => NULL,
      'payouts_restricted' => FALSE,
      'resume_url' => '/vendor/stripe/connect',
    ];

    try {
      $qDash = [
        'destination' => '/vendor/dashboard',
      ];
      $qOnboard = [
        'destination' => '/vendor/onboard/stripe',
      ];
      $status['connect_url'] = Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
        'query' => $qDash,
      ])->toString();
      $status['resume_url'] = Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
        'query' => $qOnboard,
      ])->toString();
    }
    catch (\Throwable) {
    }

    try {
      $status['manage_stripe_url'] = Url::fromRoute('myeventlane_vendor.stripe_manage')->toString();
    }
    catch (\Throwable) {
    }

    try {
      $store = NULL;
      if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
        $cand = $vendor->get('field_vendor_store')->entity;
        if ($cand instanceof StoreInterface) {
          $store = $cand;
        }
      }
      if (!$store) {
        $status['action_url'] = $status['connect_url'];
        return $status;
      }

      if ($store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
        $id = trim((string) $store->get('field_stripe_account_id')->value);
        if ($id !== '' && str_starts_with($id, 'acct_')) {
          $status['account_id'] = $id;
        }
      }
      $status['stripe_dashboard_url'] = 'https://dashboard.stripe.com';

      $raw_flags = 'pending';
      if ($store->hasField('field_stripe_status') && !$store->get('field_stripe_status')->isEmpty()) {
        $raw_flags = (string) $store->get('field_stripe_status')->value;
      }

      $charges_enabled = $store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()
        ? (bool) $store->get('field_stripe_charges_enabled')->value
        : FALSE;
      $payouts_enabled = $store->hasField('field_stripe_payouts_enabled') && !$store->get('field_stripe_payouts_enabled')->isEmpty()
        ? (bool) $store->get('field_stripe_payouts_enabled')->value
        : FALSE;

      $status['charges_enabled'] = $charges_enabled;
      $status['payouts_enabled'] = $payouts_enabled;
      $status['payouts_restricted'] = $charges_enabled && !$payouts_enabled;

      if (empty($status['account_id'])) {
        $status['mel_stripe_state'] = 'not_connected';
        $status['primary_action'] = 'connect';
        $status['action_url'] = $status['connect_url'];
        $status['status_label'] = (string) $this->t('Not connected');
        $status['stripe_phase'] = 'needs_connect';
        return $status;
      }

      if ($charges_enabled && $payouts_enabled) {
        $status['connected'] = TRUE;
        $status['status'] = 'connected';
        $status['status_label'] = (string) $this->t('Payouts enabled');
        $status['mel_stripe_state'] = 'ready_to_sell';
        $status['primary_action'] = 'open_dashboard';
        $status['action_url'] = $status['manage_stripe_url'] ?? $status['connect_url'];
        $status['stripe_phase'] = 'payouts_ready';
        $status['is_onboarding_complete'] = TRUE;
        return $status;
      }

      if ($charges_enabled && !$payouts_enabled) {
        $status['connected'] = TRUE;
        $status['status'] = 'connected';
        $status['status_label'] = (string) $this->t('Payouts restricted');
        $status['mel_stripe_state'] = 'payouts_restricted';
        $status['primary_action'] = 'open_dashboard';
        $status['action_url'] = $status['manage_stripe_url'] ?? $status['connect_url'];
        $status['stripe_phase'] = 'payouts_ready';
        $status['is_onboarding_complete'] = TRUE;
        return $status;
      }

      if ($raw_flags === 'restricted') {
        $status['status'] = 'pending';
        $status['status_label'] = (string) $this->t('Action required');
        $status['mel_stripe_state'] = 'action_required';
        $status['primary_action'] = 'review_requirements';
        $status['action_url'] = $status['resume_url'];
        $status['stripe_phase'] = 'needs_completion';
        $status['is_onboarding_complete'] = FALSE;
        return $status;
      }

      $status['status'] = 'pending';
      $status['status_label'] = (string) $this->t('Continue setup');
      $status['mel_stripe_state'] = 'continue_setup';
      $status['primary_action'] = 'continue';
      $status['action_url'] = $status['resume_url'];
      $status['stripe_phase'] = 'needs_completion';
      $status['is_onboarding_complete'] = FALSE;
    }
    catch (\Exception) {
    }

    if ($status['action_url'] === NULL) {
      $status['action_url'] = $status['connect_url'];
    }

    return $status;
  }

  /**
   * Get notifications/alerts for vendor.
   *
   * @param int $userId
   *   The user ID.
   * @param array $userEvents
   *   Event IDs for the user.
   * @param \Drupal\myeventlane_vendor\Entity\Vendor|null $vendor
   *   Optional vendor entity for Stripe status check.
   *
   * @return array
   *   List of notification items.
   */
  private function getNotifications(int $userId, array $userEvents, $vendor = NULL): array {
    $notifications = [];

    if (empty($userEvents)) {
      return $notifications;
    }

    $normalized = $this->entityIdNormalizer->normalizeNodeIds(array_values($userEvents));
    $melList = ($this->eventRepository === NULL || $normalized === [])
      ? []
      : $this->eventRepository->loadMany($normalized);
    $now = time();
    $threeDaysFromNow = $now + (3 * 24 * 60 * 60);

    foreach ($melList as $dto) {
      if (!$dto instanceof MelEventData) {
        continue;
      }

      $startTimestamp = $dto->start_timestamp;

      if ($startTimestamp > $now && $startTimestamp <= $threeDaysFromNow) {
        $daysUntil = ceil(($startTimestamp - $now) / 86400);
        $notifications[] = [
          'type' => 'info',
          'icon' => 'calendar',
          'message' => t('@title starts in @days day(s)', [
            '@title' => $dto->title,
            '@days' => $daysUntil,
          ]),
          'url' => '/vendor/events/' . $dto->id . '/overview',
        ];
      }

      if (!$dto->has_cover_image) {
        $notifications[] = [
          'type' => 'warning',
          'icon' => 'image',
          'message' => t('@title is missing a cover image', [
            '@title' => $dto->title,
          ]),
          'url' => Url::fromRoute('myeventlane_event_studio.edit', ['node' => $dto->id])->toString(),
        ];
      }

      if (!$dto->published) {
        $notifications[] = [
          'type' => 'neutral',
          'icon' => 'edit',
          'message' => t('@title is still in draft', [
            '@title' => $dto->title,
          ]),
          'url' => Url::fromRoute('myeventlane_event_studio.edit', ['node' => $dto->id])->toString(),
        ];
      }
    }

    // Check Stripe status.
    $stripeStatus = $this->getStripeConnectStatus($userId, $vendor);
    $mel = (string) ($stripeStatus['mel_stripe_state'] ?? 'not_connected');
    if ($mel === 'payouts_restricted') {
      $notifications[] = [
        'type' => 'info',
        'icon' => 'credit-card',
        'message' => t('Add or verify your bank in Stripe to receive ticket payouts'),
        'url' => $stripeStatus['action_url'] ?? $stripeStatus['connect_url'] ?? '/vendor/stripe/connect',
      ];
    }
    if (($stripeStatus['stripe_phase'] ?? '') !== 'payouts_ready') {
      $is_finish = ($stripeStatus['stripe_phase'] ?? '') === 'needs_completion';
      $is_action = $mel === 'action_required';
      $stripe_url = $is_finish || $is_action
        ? ($stripeStatus['resume_url'] ?? $stripeStatus['connect_url'] ?? '/vendor/stripe/connect')
        : ($stripeStatus['connect_url'] ?? '/vendor/stripe/connect');
      $msg = t('Connect Stripe to receive payments');
      if ($is_action) {
        $msg = t('Stripe needs more information. Review requirements in Connect.');
      }
      elseif ($is_finish) {
        $msg = t('Finish Stripe setup to enable payouts');
      }
      $notifications[] = [
        'type' => 'warning',
        'icon' => 'credit-card',
        'message' => $msg,
        'url' => $stripe_url,
      ];
    }

    // Limit to 5 notifications.
    return array_slice($notifications, 0, 5);
  }

  /**
   * Get account summary for vendor.
   */
  private function getAccountSummary(int $userId, $vendor = NULL): array {
    $account = [
      'display_name' => '',
      'email' => '',
      'store_name' => '',
      'last_login' => NULL,
    ];

    try {
      $user = $this->entityTypeManager->getStorage('user')->load($userId);
      if ($user instanceof UserInterface) {
        $account['display_name'] = $user->getDisplayName();
        $account['email'] = $user->getEmail();
        $lastLogin = $user->getLastLoginTime();
        if ($lastLogin) {
          $account['last_login'] = date('M j, Y g:ia', (int) $lastLogin);
        }

        if ($vendor) {
          $account['store_name'] = $vendor->label();
        }
        else {
          // Get vendor entity if exists.
          $vendors = $this->entityTypeManager->getStorage('myeventlane_vendor')
            ->loadByProperties(['uid' => $userId]);
          if (!empty($vendors)) {
            $vendor = reset($vendors);
            $account['store_name'] = $vendor->label();
          }
        }
      }
    }
    catch (\Exception $e) {
      // User loading failed.
    }

    return $account;
  }

  /**
   * Get quick actions for dashboard.
   */
  private function getQuickActions(): array {
    return [
      [
        'label' => 'Create Event',
        'url' => Url::fromRoute('myeventlane_event_studio.create')->toString(),
        'icon' => 'plus',
        'style' => 'primary',
      ],
      [
        'label' => (string) $this->t('Saved events'),
        'url' => $this->safeRouteUrl('view.mel_saved_events.page_1') ?? '/my-saved-events',
        'icon' => 'calendar',
        'style' => 'secondary',
      ],
      [
        'label' => 'Manage Payouts',
        'url' => '/vendor/payouts',
        'icon' => 'dollar',
        'style' => 'secondary',
      ],
      [
        'label' => 'View Attendees',
        'url' => '/vendor/audience',
        'icon' => 'users',
        'style' => 'secondary',
      ],
      [
        'label' => 'Boost Event',
        'url' => '/vendor/boost',
        'icon' => 'zap',
        'style' => 'secondary',
      ],
      [
        'label' => 'Contact Audience',
        'url' => '/vendor/audience',
        'icon' => 'mail',
        'style' => 'secondary',
      ],
      [
        'label' => 'Messaging brand',
        'url' => '/vendor/dashboard/messaging/brand',
        'icon' => 'mail',
        'style' => 'secondary',
      ],
      [
        'label' => 'Edit Profile',
        'url' => '/vendor/settings',
        'icon' => 'settings',
        'style' => 'secondary',
      ],
    ];
  }

  /**
   * Builds dashboard KPI cards in a stable display order.
   *
   * @param array<int, array<string, mixed>> $kpis
   *   Raw KPI cards from buildKpiCards().
   * @param int $upcomingCount
   *   Upcoming event count.
   *
   * @return array<int, array<string, mixed>>
   *   Dashboard KPI cards.
   */
  private function buildDashboardKpis(array $kpis): array {
    $index = [];
    foreach ($kpis as $kpi) {
      $key = (string) ($kpi['key'] ?? '');
      if ($key !== '') {
        $index[$key] = $kpi;
      }
    }

    return [
      [
        'label' => (string) $this->t('Total revenue'),
        'value' => (string) ($index['total_revenue']['value'] ?? '0'),
        'currency' => (string) ($index['total_revenue']['currency'] ?? '$'),
        'icon' => 'revenue',
        'color' => 'coral',
        'meta' => (string) $this->t('Gross sales'),
      ],
      [
        'label' => (string) $this->t('Tickets sold'),
        'value' => (string) ($index['tickets_sold']['value'] ?? '0'),
        'icon' => 'tickets',
        'color' => 'green',
        'meta' => (string) $this->t('Completed orders'),
      ],
      [
        'label' => (string) $this->t('Active events'),
        'value' => (string) ($index['active_events']['value'] ?? '0'),
        'icon' => 'calendar',
        'color' => 'blue',
        'meta' => (string) $this->t('Published and scheduled'),
      ],
      [
        'label' => (string) $this->t('Total attendees'),
        'value' => (string) ($index['total_attendees']['value'] ?? '0'),
        'icon' => 'users',
        'color' => 'purple',
        'meta' => (string) $this->t('Tickets + RSVPs'),
      ],
    ];
  }

  /**
   * Builds action cards for quick operational tasks.
   *
   * @return array<int, array<string, mixed>>
   *   Action card definitions.
   */
  private function buildDashboardActionCards(): array {
    $cards = [];
    $mel_billing = $this->safeRouteUrl('myeventlane_donations.vendor_mel_billing');
    if ($mel_billing) {
      $cards[] = [
        'title' => (string) $this->t('MEL contributions'),
        'description' => (string) $this->t('View accruals, invoices, and pay your MyEventLane contribution balance.'),
        'url' => $mel_billing,
      ];
    }
    return array_merge($cards, [
      [
        'title' => (string) $this->t('Finish setup'),
        'description' => (string) $this->t('Complete your organiser profile and settings.'),
        'url' => $this->safeRouteUrl('myeventlane_vendor.console.settings'),
      ],
      [
        'title' => (string) $this->t('Boost an event'),
        'description' => (string) $this->t('Increase visibility for your published events.'),
        'url' => $this->safeRouteUrl('myeventlane_vendor.console.boost'),
      ],
      [
        'title' => (string) $this->t('Review attendees'),
        'description' => (string) $this->t('See attendee trends and engagement insights.'),
        'url' => $this->safeRouteUrl('myeventlane_vendor.console.audience'),
      ],
      [
        'title' => (string) $this->t('Check payouts'),
        'description' => (string) $this->t('Review transfers and payout readiness.'),
        'url' => $this->safeRouteUrl('myeventlane_vendor.console.payouts'),
      ],
    ]);
  }

  /**
   * Normalizes dashboard recent activity rows.
   *
   * @param array<int, array<string, mixed>> $notifications
   *   Notification rows.
   *
   * @return array<int, array<string, mixed>>
   *   Activity rows.
   */
  private function buildDashboardActivity(array $eventIds, array $eventRows): array {
    if (empty($eventIds)) {
      return [];
    }

    $eventIds = $this->entityIdNormalizer->normalizeNodeIds(array_values($eventIds));
    if ($eventIds === []) {
      return [];
    }

    $eventTitles = [];
    foreach ($eventRows as $row) {
      if (!empty($row['id']) && !empty($row['title'])) {
        $eventTitles[(int) $row['id']] = (string) $row['title'];
      }
    }

    $items = [];

    // 1) New ticket purchases.
    try {
      $orderItems = $this->entityTypeManager
        ->getStorage('commerce_order_item')
        ->loadByProperties(['field_target_event' => $eventIds]);
      foreach (array_slice($orderItems, 0, 12) as $orderItem) {
        if (!$orderItem instanceof OrderItemInterface) {
          continue;
        }
        $order = $this->getOrderFromItem($orderItem);
        if (!$order || $order->getState()->getId() !== 'completed') {
          continue;
        }
        $eventId = (int) ($orderItem->hasField('field_target_event') && !$orderItem->get('field_target_event')->isEmpty()
          ? $orderItem->get('field_target_event')->target_id
          : 0);
        if ($eventId <= 0) {
          continue;
        }
        $items[] = [
          'timestamp' => (int) ($order->getCompletedTime() ?? $order->getChangedTime()),
          'type' => 'success',
          'message' => (string) $this->t('New ticket purchase for @event.', [
            '@event' => $eventTitles[$eventId] ?? $this->t('your event'),
          ]),
          'url' => '/vendor/events/' . $eventId . '/orders',
        ];
        if (count($items) >= 2) {
          break;
        }
      }
    }
    catch (\Throwable) {
      // Keep dashboard resilient when Commerce is unavailable.
    }

    // 2) New RSVPs.
    try {
      if ($this->entityTypeManager->hasDefinition('rsvp_submission')) {
        $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');
        $rsvpIds = $rsvpStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('event_id', $eventIds, 'IN')
          ->condition('status', 'confirmed')
          ->sort('created', 'DESC')
          ->range(0, 2)
          ->execute();
        $rsvpIds = $this->entityIdNormalizer->normalizeEntityIds(array_values($rsvpIds));
        foreach (($rsvpIds === [] ? [] : $rsvpStorage->loadMultiple($rsvpIds)) as $rsvp) {
          $eventId = (int) ($rsvp->hasField('event_id') ? $rsvp->get('event_id')->target_id : 0);
          $items[] = [
            'timestamp' => (int) ($rsvp->hasField('created') ? $rsvp->get('created')->value : time()),
            'type' => 'info',
            'message' => (string) $this->t('New RSVP confirmed for @event.', [
              '@event' => $eventTitles[$eventId] ?? $this->t('your event'),
            ]),
            'url' => $eventId > 0 ? '/vendor/events/' . $eventId . '/rsvps' : NULL,
          ];
        }
      }
    }
    catch (\Throwable) {
      // Keep dashboard resilient when RSVP module is unavailable.
    }

    // 3) Recent event edits.
    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $editedIds = $nodeStorage->getQuery()
        ->accessCheck(TRUE)
        ->condition('nid', $eventIds, 'IN')
        ->sort('changed', 'DESC')
        ->range(0, 2)
        ->execute();
      $editedIds = $this->entityIdNormalizer->normalizeNodeIds(array_values($editedIds));
      $editedMel = ($this->eventRepository === NULL || $editedIds === [])
        ? []
        : $this->eventRepository->loadMany($editedIds);
      foreach ($editedMel as $edited) {
        if (!$edited instanceof MelEventData) {
          continue;
        }
        if ($edited->changed <= $edited->created) {
          continue;
        }
        $eventId = $edited->id;
        $items[] = [
          'timestamp' => $edited->changed,
          'type' => 'neutral',
          'message' => (string) $this->t('Recent event update: @event.', [
            '@event' => $edited->title,
          ]),
          'url' => '/vendor/events/' . $eventId . '/overview',
        ];
      }
    }
    catch (\Throwable) {
      // Keep dashboard resilient on entity query failures.
    }

    // 4) Recent check-ins.
    try {
      if ($this->entityTypeManager->hasDefinition('myeventlane_ticket')) {
        $ticketStorage = $this->entityTypeManager->getStorage('myeventlane_ticket');
        $ticketIds = $ticketStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('event_id', $eventIds, 'IN')
          ->condition('status', 'checked_in')
          ->sort('checked_in_at', 'DESC')
          ->range(0, 2)
          ->execute();
        $ticketIds = $this->entityIdNormalizer->normalizeEntityIds(array_values($ticketIds));
        foreach (($ticketIds === [] ? [] : $ticketStorage->loadMultiple($ticketIds)) as $ticket) {
          $eventId = (int) ($ticket->hasField('event_id') ? $ticket->get('event_id')->target_id : 0);
          $items[] = [
            'timestamp' => (int) ($ticket->hasField('checked_in_at') ? $ticket->get('checked_in_at')->value : time()),
            'type' => 'success',
            'message' => (string) $this->t('Recent check-in for @event.', [
              '@event' => $eventTitles[$eventId] ?? $this->t('your event'),
            ]),
            'url' => $eventId > 0 ? '/vendor/events/' . $eventId . '/attendees' : NULL,
          ];
        }
      }
    }
    catch (\Throwable) {
      // Keep dashboard resilient when tickets module is unavailable.
    }

    usort($items, static fn(array $a, array $b): int => ((int) ($b['timestamp'] ?? 0)) <=> ((int) ($a['timestamp'] ?? 0)));
    $items = array_slice($items, 0, 6);
    foreach ($items as &$item) {
      unset($item['timestamp']);
    }

    return $items;
  }

  /**
   * Builds dashboard alert boxes for next-step guidance.
   *
   * @param array<string, mixed> $stripe
   *   Stripe status.
   * @param array<int, array<string, mixed>> $notifications
   *   Notification rows.
   * @param bool $show_welcome
   *   Whether this vendor is in welcome/empty state.
   *
   * @return array<int, array<string, string|null>>
   *   Alert rows.
   */
  private function buildDashboardAlerts(array $stripe, array $eventNodes): array {
    $alerts = [];

    $actionUrl = $stripe['action_url'] ?? $stripe['connect_url'] ?? $this->safeRouteUrl('myeventlane_vendor.stripe_connect');
    $mel = (string) ($stripe['mel_stripe_state'] ?? 'not_connected');
    if ($mel === 'payouts_restricted') {
      $alerts[] = [
        'type' => 'info',
        'title' => (string) $this->t('Finish payout setup'),
        'message' => (string) $this->t('You can take card payments. Complete bank and verification in Stripe to receive your transfers.'),
        'url' => $actionUrl,
        'link_label' => (string) $this->t('Open Stripe Dashboard'),
      ];
    }
    $stripe_phase = $stripe['stripe_phase'] ?? (empty($stripe['connected']) ? 'needs_connect' : 'needs_completion');
    if ($stripe_phase !== 'payouts_ready') {
      $is_finish = $stripe_phase === 'needs_completion';
      $is_action = $mel === 'action_required';
      $alerts[] = [
        'type' => $is_action ? 'warning' : ($is_finish ? 'warning' : 'info'),
        'title' => $is_action
          ? (string) $this->t('Stripe: action required')
          : ($is_finish
            ? (string) $this->t('Finish setting up Stripe')
            : (string) $this->t('Connect Stripe to receive payments')),
        'message' => $is_action
          ? (string) $this->t('Log in to Stripe to resolve outstanding requirements, or continue the Connect flow.')
          : ($is_finish
            ? (string) $this->t('Complete your Stripe account so you can get paid for ticket sales.')
            : (string) $this->t('Connect Stripe so ticket sales can pay out automatically.')),
        'url' => $is_finish || $is_action
          ? ($stripe['resume_url'] ?? $stripe['connect_url'] ?? $this->safeRouteUrl('myeventlane_vendor.stripe_connect'))
          : ($stripe['connect_url'] ?? $this->safeRouteUrl('myeventlane_vendor.stripe_connect')),
        'link_label' => $is_action
          ? (string) $this->t('Review Stripe requirements')
          : ($is_finish
            ? (string) $this->t('Continue Stripe setup')
            : (string) $this->t('Connect Stripe')),
      ];
    }

    $hasEvents = !empty($eventNodes);
    if (!$hasEvents) {
      $alerts[] = [
        'type' => 'info',
        'title' => (string) $this->t('Ready for your first launch'),
        'message' => (string) $this->t('Create your first event and we will guide you through publishing.'),
        'url' => $this->safeRouteUrl('myeventlane_event_studio.create'),
        'link_label' => (string) $this->t('Create event'),
      ];
    }

    foreach ($eventNodes as $event) {
      if (!$event instanceof MelEventData) {
        continue;
      }

      $eventTitle = $event->title;
      $eventId = $event->id;

      if ($event->needs_ticket_product) {
        $alerts[] = [
          'type' => 'warning',
          'title' => (string) $this->t('Ticket setup required'),
          'message' => (string) $this->t('@event has no ticket product configured.', ['@event' => $eventTitle]),
          'url' => '/vendor/events/' . $eventId . '/tickets',
          'link_label' => (string) $this->t('Configure tickets'),
        ];
      }

      if (!$event->has_cover_image) {
        $alerts[] = [
          'type' => 'warning',
          'title' => (string) $this->t('Event image missing'),
          'message' => (string) $this->t('@event is missing a cover image.', ['@event' => $eventTitle]),
          'url' => '/vendor/events/' . $eventId . '/overview',
          'link_label' => (string) $this->t('Add image'),
        ];
      }

      if (!$event->published) {
        $alerts[] = [
          'type' => 'info',
          'title' => (string) $this->t('Publish pending'),
          'message' => (string) $this->t('@event is still in draft and needs publishing.', ['@event' => $eventTitle]),
          'url' => '/vendor/events/' . $eventId . '/publish',
          'link_label' => (string) $this->t('Review publish'),
        ];
      }

      if (count($alerts) >= 3) {
        break;
      }
    }

    return $alerts;
  }

  /**
   * Resolves a route URL safely.
   *
   * @param string $route_name
   *   Route name.
   * @param array<string, mixed> $route_parameters
   *   Optional route parameters.
   *
   * @return string|null
   *   URL string or NULL if route is unavailable.
   */
  private function safeRouteUrl(string $route_name, array $route_parameters = []): ?string {
    try {
      return Url::fromRoute($route_name, $route_parameters)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Maps modern event rows to legacy upcoming_events schema.
   *
   * @param array<int, array<string, mixed>> $events
   *   Dashboard event rows.
   *
   * @return array<int, array<string, mixed>>
   *   Legacy event rows.
   */
  private function buildLegacyUpcomingEvents(array $events): array {
    $rows = [];
    foreach ($events as $event) {
      $rows[] = [
        'id' => (int) ($event['id'] ?? 0),
        'title' => (string) ($event['title'] ?? ''),
        'url' => (string) ($event['view_url'] ?? ''),
        'edit_url' => (string) ($event['edit_url'] ?? ''),
        'start_date' => (string) ($event['date'] ?? ''),
        'start_time' => '',
        'attendee_count' => (int) ($event['tickets_sold'] ?? 0),
        'rsvp_count' => (int) ($event['rsvps'] ?? 0),
        'revenue' => (float) ($event['revenue'] ?? 0),
        'event_mode' => 'paid',
        'status' => (string) ($event['status_label'] ?? ''),
        'location' => (string) ($event['venue'] ?? ''),
        'date' => (string) ($event['date'] ?? ''),
      ];
    }
    return array_slice($rows, 0, 8);
  }

  /**
   * Maps quick action cards to legacy quick_links schema.
   *
   * @param array<int, array<string, mixed>> $quickActions
   *   Modern quick actions.
   *
   * @return array<int, array<string, mixed>>
   *   Legacy quick links.
   */
  private function buildLegacyQuickLinks(array $quickActions): array {
    $rows = [];
    foreach ($quickActions as $action) {
      $rows[] = [
        'title' => (string) ($action['label'] ?? ''),
        'url' => (string) ($action['url'] ?? ''),
        'icon' => (string) ($action['icon'] ?? ''),
      ];
    }
    return $rows;
  }

  /**
   * Maps KPI cards to legacy dashboard.metrics schema.
   *
   * @param array<int, array<string, mixed>> $kpis
   *   Modern KPI cards.
   *
   * @return array<int, array<string, mixed>>
   *   Legacy metric rows.
   */
  private function buildLegacyDashboardMetrics(array $kpis): array {
    $rows = [];
    foreach ($kpis as $kpi) {
      $currency = (string) ($kpi['currency'] ?? '');
      $value = (string) ($kpi['value'] ?? '0');
      $rows[] = [
        'label' => (string) ($kpi['label'] ?? ''),
        'value' => $currency . $value,
        'subtext' => is_array($kpi['delta'] ?? NULL) ? (string) ($kpi['delta']['label'] ?? '') : NULL,
      ];
    }
    return array_slice($rows, 0, 4);
  }

  /**
   * Gets active boost entitlements for the dashboard.
   *
   * @param int $uid
   *   Vendor user ID.
   *
   * @return array<int, array<string, mixed>>
   *   Entitlement rows.
   */
  private function getActiveBoostEntitlements(int $uid): array {
    if ($uid <= 0) {
      return [];
    }
    if (!$this->entityTypeManager->hasDefinition('myeventlane_boost_entitlement')) {
      return [];
    }

    $vendorBoostRouteOk = $this->routeExists('myeventlane_boost.vendor_event_boost');

    try {
      $now = time();
      $storage = $this->entityTypeManager->getStorage('myeventlane_boost_entitlement');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->condition('status', 'active')
        ->condition('ends', $now, '>')
        ->sort('ends', 'ASC')
        ->range(0, 10)
        ->execute();
      if (empty($ids)) {
        return [];
      }

      $ids = $this->entityIdNormalizer->normalizeEntityIds(array_values($ids));
      if ($ids === []) {
        return [];
      }

      $rows = [];
      $entitlements = $storage->loadMultiple($ids);
      foreach ($entitlements as $entitlement) {
        $event = $entitlement->get('event')->entity;
        $event_id = (int) ($entitlement->get('event')->target_id ?? 0);
        $ends = (int) ($entitlement->get('ends')->value ?? 0);
        $rows[] = [
          'event_id' => $event_id,
          'event_title' => $event ? $event->label() : $this->t('Unknown event'),
          'ends_at' => $ends > 0 ? date('M j, Y g:ia', $ends) : $this->t('Unknown'),
          'manage_url' => ($event_id > 0 && $vendorBoostRouteOk)
            ? Url::fromRoute('myeventlane_boost.vendor_event_boost', ['event' => $event_id])->toString()
            : NULL,
        ];
      }

      return $rows;
    }
    catch (\Exception $e) {
      return [];
    }
  }


  /**
   * Get waitlist analytics for a specific event.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with waitlist analytics data.
   */
  private function getEventWaitlistAnalytics(int $eventId): array {
    try {
      $waitlistManager = \Drupal::service('myeventlane_event_attendees.waitlist');
      return $waitlistManager->getWaitlistAnalytics($eventId);
    }
    catch (\Exception $e) {
      // Return empty analytics if service unavailable.
      return [
        'total_waitlist' => 0,
        'total_promoted' => 0,
        'conversion_rate' => 0.0,
        'average_wait_time' => 0.0,
        'current_waitlist' => 0,
      ];
    }
  }

}
