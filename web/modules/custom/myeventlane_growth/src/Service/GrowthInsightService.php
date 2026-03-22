<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProAccessService;
use Drupal\myeventlane_pro\Service\ProSubscriptionStatusService;
use Drupal\user\UserInterface;

/**
 * Deterministic growth insights for organisers (Australian English copy).
 */
final class GrowthInsightService {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly GrowthTrackingService $tracking,
    private readonly GrowthOrganiserSignals $signals,
    private readonly ProAccessService $proAccess,
    private readonly ProSubscriptionStatusService $subscriptionStatus,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Builds applicable insights for the given organiser context.
   *
   * Expected context keys:
   * - uid (int, required)
   * - account (\Drupal\Core\Session\AccountInterface, required)
   * - surface (string, default dashboard)
   * - events (array<int, array> rows from vendor dashboard, optional)
   * - audience_summary (array{total_attendees?: int, repeat_attendees?: int}, optional)
   * - last_login_timestamp (int, optional)
   * - published_event_count (int, optional)
   *
   * @return array<int, array<string, mixed>>
   */
  public function buildInsights(array $context): array {
    $config = $this->configFactory->get('myeventlane_growth.settings');
    if (!(bool) ($config->get('enabled') ?? TRUE)) {
      return [];
    }

    $uid = (int) ($context['uid'] ?? 0);
    $account = $context['account'] ?? NULL;
    if ($uid <= 0 || !$account instanceof AccountInterface || $account->isAnonymous()) {
      return [];
    }

    $surface = (string) ($context['surface'] ?? 'dashboard');
    $events = $context['events'] ?? [];
    if (!is_array($events)) {
      $events = [];
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return [];
    }

    $proStatus = $this->subscriptionStatus->getStatusForUser($user);
    $isPro = (bool) ($proStatus['is_pro'] ?? FALSE);
    $isInGrace = (bool) ($proStatus['is_in_grace'] ?? FALSE);
    $hasActiveSubscription = (bool) ($proStatus['has_active_subscription'] ?? FALSE);

    $thresholds = $config->get('thresholds') ?? [];
    $cooldowns = $config->get('cooldowns') ?? [];
    $minActivity = (int) ($thresholds['min_activity_for_pro_nudge'] ?? 1);
    $slowPct = (int) ($thresholds['slow_sales_percent'] ?? 20);
    $tractionPct = (int) ($thresholds['traction_percent'] ?? 70);
    $repeatEvents = (int) ($thresholds['repeat_events_for_pro_nudge'] ?? 2);
    $strongMin = (int) ($thresholds['strong_event_min_filled'] ?? 10);
    $draftIdleDays = (int) ($thresholds['draft_idle_days'] ?? 21);
    $postEventDays = (int) ($thresholds['post_event_followup_days'] ?? 14);

    $cdDefault = (int) ($cooldowns['default_days'] ?? 14);
    $cdBoost = (int) ($cooldowns['boost_days'] ?? 7);
    $cdPro = (int) ($cooldowns['pro_upgrade_days'] ?? 21);
    $cdRetention = (int) ($cooldowns['retention_days'] ?? 10);

    $publishedCount = (int) ($context['published_event_count'] ?? $this->countPublishedEventsForOrganiser($uid, $context));
    $totalActivity = $this->aggregateActivity($events);

    $insights = [];

    try {
      // Rule group C — billing / churn (highest priority messaging).
      if ($isPro && $isInGrace) {
        $insights[] = $this->buildInsight(
          'pro_grace_access_risk',
          'recovery',
          'warning',
          'Your MEL Pro access is at risk',
          'Update your payment method to keep your organiser tools active.',
          'myeventlane_pro.manage',
          [],
          'pro_organiser',
          FALSE,
          10,
          'mel-growth-card--warning',
        );
      }

      if ($isPro && $this->signals->consumeBillingRecoveredCelebration($uid)) {
        $insights[] = $this->buildInsight(
          'pro_billing_recovered',
          'celebration',
          'success',
          'Your MEL Pro access is active again',
          'Thanks — your organiser tools are available as normal.',
          'myeventlane_pro.manage',
          [],
          'pro_organiser',
          TRUE,
          20,
          'mel-growth-card--success',
        );
      }

      if ($isPro && $hasActiveSubscription && $this->signals->hasRecentCancelIntent($uid)) {
        $insights[] = $this->buildInsight(
          'pro_cancel_intent_soft_hold',
          'retention',
          'info',
          'Before you leave, here is what MEL Pro helps you keep',
          'Advanced analytics, audience messaging, and Boost priority stay with your subscription. You can manage billing any time.',
          'myeventlane_pro.manage',
          [],
          'pro_organiser',
          TRUE,
          15,
          'mel-growth-card--upgrade',
        );
      }

      // Rule group A — Pro upgrade (free organisers).
      if (!$isPro && $publishedCount >= 1 && $totalActivity >= $minActivity) {
        if (!$this->tracking->shouldSuppressInsight($uid, 'pro_upgrade_meaningful_activity', NULL, $cdPro, TRUE, $cdDefault)) {
          $insights[] = $this->buildInsight(
            'pro_upgrade_meaningful_activity',
            'upgrade',
            'info',
            'You are building momentum',
            'Upgrade to MEL Pro to unlock advanced organiser tools, including automation and deeper analytics.',
            'myeventlane_pro.overview',
            [],
            'free_organiser',
            TRUE,
            40,
            'mel-growth-card--upgrade',
          );
        }
      }

      if (!$isPro && $this->isProGateSignalRelevant($uid)) {
        if (!$this->tracking->shouldSuppressInsight($uid, 'pro_upgrade_gated_feature', NULL, $cdPro, TRUE, $cdDefault)) {
          $insights[] = $this->buildInsight(
            'pro_upgrade_gated_feature',
            'upgrade',
            'info',
            'This feature is available with MEL Pro',
            'Upgrade to automate reminders, use audience tools, and see deeper analytics.',
            'myeventlane_pro.overview',
            [],
            'free_organiser',
            TRUE,
            42,
            'mel-growth-card--upgrade',
          );
          $this->signals->clearProGateSignal($uid);
        }
      }

      if (!$isPro && ($publishedCount >= $repeatEvents || $this->organiserHasDuplicateDraftPattern($uid))) {
        if (!$this->tracking->shouldSuppressInsight($uid, 'pro_upgrade_repeat_events', NULL, $cdPro, TRUE, $cdDefault)) {
          $insights[] = $this->buildInsight(
            'pro_upgrade_repeat_events',
            'upgrade',
            'info',
            'You are running events regularly',
            'MEL Pro can save you time with smarter organiser tools and audience features.',
            'myeventlane_pro.overview',
            [],
            'free_organiser',
            TRUE,
            41,
            'mel-growth-card--upgrade',
          );
        }
      }

      // Rule group B — Boost.
      $now = time();
      foreach ($events as $row) {
        if (!is_array($row)) {
          continue;
        }
        $eid = (int) ($row['id'] ?? 0);
        if ($eid <= 0) {
          continue;
        }
        $published = !empty($row['status']) && $row['status'] !== 'draft';
        if (!$published) {
          continue;
        }
        $startTs = (int) ($row['start_timestamp'] ?? 0);
        $endTs = (int) ($row['end_timestamp'] ?? 0);
        $upcoming = $startTs > $now;
        $ended = $endTs > 0 && $endTs < $now;
        $pct = (float) ($row['percent_sold'] ?? 0);
        $filled = (int) ($row['filled_count'] ?? ($row['tickets_sold'] ?? 0) + ($row['rsvps'] ?? 0));
        $soldOut = !empty($row['is_sold_out']);
        $boosted = !empty($row['boost']['is_boosted']);
        $boostAllowed = !empty($row['boost']['allowed']);

        if ($upcoming && !$boosted && $boostAllowed && $pct < $slowPct) {
          if (!$this->tracking->shouldSuppressInsight($uid, 'boost_slow_sales', $eid, $cdBoost, TRUE, $cdDefault)) {
            $insights[] = $this->buildInsight(
              'boost_slow_sales',
              'boost',
              'info',
              'Ticket sales are slower than expected',
              'Boost your event to help more people discover it.',
              'myeventlane_boost.wizard.step1',
              ['event' => $eid],
              'organiser',
              TRUE,
              35,
              'mel-growth-card--boost',
              $eid,
            );
          }
        }

        if ($upcoming && !$boosted && $boostAllowed && !$soldOut && $pct >= $tractionPct) {
          if (!$this->tracking->shouldSuppressInsight($uid, 'boost_traction_final_push', $eid, $cdBoost, TRUE, $cdDefault)) {
            $insights[] = $this->buildInsight(
              'boost_traction_final_push',
              'boost',
              'info',
              'Your event is gaining traction',
              'A final Boost could help fill the remaining spots.',
              'myeventlane_boost.wizard.step1',
              ['event' => $eid],
              'organiser',
              TRUE,
              36,
              'mel-growth-card--boost',
              $eid,
            );
          }
        }

        if ($ended && !$boosted && $filled >= $strongMin) {
          if (!$this->tracking->shouldSuppressInsight($uid, 'boost_rerun_after_success', $eid, $cdBoost, TRUE, $cdDefault)) {
            $insights[] = $this->buildInsight(
              'boost_rerun_after_success',
              'boost',
              'info',
              'This event performed well',
              'Duplicate it now or Boost your next one earlier to keep the momentum.',
              'myeventlane_event.duplicate',
              ['node' => $eid],
              'organiser',
              TRUE,
              34,
              'mel-growth-card--boost',
              $eid,
            );
          }
        }
      }

      // Rule group D — Retention / engagement.
      if ($this->organiserHasStaleDrafts($uid, $draftIdleDays)) {
        if (!$this->tracking->shouldSuppressInsight($uid, 'retention_draft_publish_nudge', NULL, $cdRetention, TRUE, $cdDefault)) {
          $insights[] = $this->buildInsight(
            'retention_draft_publish_nudge',
            'retention',
            'info',
            'Your draft is nearly ready',
            'Publish when you are ready to start building momentum.',
            'myeventlane_event.wizard.create',
            [],
            'organiser',
            TRUE,
            30,
            'mel-growth-card--upgrade',
          );
        }
      }

      $lastLogin = (int) ($context['last_login_timestamp'] ?? 0);
      if ($this->shouldNudgeAfterPastEvent($events, $postEventDays, $lastLogin)) {
        if (!$this->tracking->shouldSuppressInsight($uid, 'retention_post_event_review', NULL, $cdRetention, TRUE, $cdDefault)) {
          $insights[] = $this->buildInsight(
            'retention_post_event_review',
            'engagement',
            'info',
            'Your event has wrapped up',
            'Review your attendees or run it again in a few clicks.',
            'myeventlane_vendor.console.audience',
            [],
            'organiser',
            TRUE,
            25,
            'mel-growth-card--upgrade',
          );
        }
      }

      $audience = $context['audience_summary'] ?? [];
      $repeat = (int) ($audience['repeat_attendees'] ?? 0);
      $audienceTools = $isPro || $this->proAccess->canAccessFeature($account, 'audience_messaging');
      if ($repeat > 0 && $audienceTools) {
        if (!$this->tracking->shouldSuppressInsight($uid, 'engagement_audience_growth', NULL, $cdRetention, TRUE, $cdDefault)) {
          $insights[] = $this->buildInsight(
            'engagement_audience_growth',
            'engagement',
            'info',
            'You have started building an audience',
            'Use attendee tools to grow your next event.',
            'myeventlane_vendor.console.audience',
            [],
            'pro_organiser',
            TRUE,
            24,
            'mel-growth-card--upgrade',
          );
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Growth insights failed for uid @uid: @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    usort($insights, static fn(array $a, array $b): int => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));

    $max = (int) ($config->get('limits.max_dashboard_cards') ?? 3);
    $max = max(1, min(5, $max));
    $insights = array_slice($insights, 0, $max);

    foreach ($insights as &$insight) {
      $insight['cta_track_url'] = $this->buildTrackableCtaUrl($insight, $surface);
    }

    return $insights;
  }

  /**
   * @param array<string, mixed> $insight
   */
  private function buildTrackableCtaUrl(array $insight, string $surface): ?string {
    $key = (string) ($insight['key'] ?? '');
    $route = (string) ($insight['cta_route'] ?? '');
    if ($key === '' || $route === '') {
      return NULL;
    }
    try {
      $params = $insight['cta_params'] ?? [];
      $destination = Url::fromRoute($route, is_array($params) ? $params : [])->setAbsolute()->toString();
      return Url::fromRoute('myeventlane_growth.cta', [], [
        'query' => [
          'key' => $key,
          'surface' => $surface,
          'destination' => $destination,
          'eid' => (string) ($insight['event_id'] ?? ''),
        ],
      ])->setAbsolute()->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function buildInsight(
    string $key,
    string $type,
    string $severity,
    string $title,
    string $message,
    string $cta_route,
    array $cta_params,
    string $audience,
    bool $dismissible,
    int $priority,
    string $card_class,
    ?int $event_id = NULL,
  ): array {
    return [
      'key' => $key,
      'type' => $type,
      'severity' => $severity,
      'title' => $title,
      'message' => $message,
      'cta_route' => $cta_route,
      'cta_params' => $cta_params,
      'audience' => $audience,
      'trackable' => TRUE,
      'dismissible' => $dismissible,
      'priority' => $priority,
      'card_class' => $card_class,
      'event_id' => $event_id,
    ];
  }

  private function isProGateSignalRelevant(int $uid): bool {
    $signal = $this->signals->getProGateSignal($uid);
    if ($signal === NULL) {
      return FALSE;
    }
    $route = $signal['route'];
    $needles = [
      'message',
      'attendees',
      'audience',
      'automation',
      'analytics',
      'pro',
    ];
    foreach ($needles as $needle) {
      if (stripos($route, $needle) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<int, array<string, mixed>> $events
   */
  private function aggregateActivity(array $events): int {
    $sum = 0;
    foreach ($events as $row) {
      if (!is_array($row)) {
        continue;
      }
      if (($row['status'] ?? '') === 'draft') {
        continue;
      }
      $sum += (int) ($row['tickets_sold'] ?? 0) + (int) ($row['rsvps'] ?? 0);
    }
    return $sum;
  }

  /**
   * @param array<int, array<string, mixed>> $events
   */
  private function shouldNudgeAfterPastEvent(array $events, int $withinDays, int $lastLoginTs): bool {
    if ($events === []) {
      return FALSE;
    }
    $now = time();
    $windowStart = $now - ($withinDays * 86400);
    $latestEnd = 0;
    foreach ($events as $row) {
      if (!is_array($row)) {
        continue;
      }
      $end = (int) ($row['end_timestamp'] ?? 0);
      if ($end > 0 && $end < $now && $end >= $windowStart) {
        $latestEnd = max($latestEnd, $end);
      }
    }
    if ($latestEnd === 0) {
      return FALSE;
    }
    if ($lastLoginTs > 0 && $lastLoginTs > $latestEnd) {
      return FALSE;
    }
    return TRUE;
  }

  private function countPublishedEventsForOrganiser(int $uid, array $context): int {
    if (!empty($context['published_event_count'])) {
      return (int) $context['published_event_count'];
    }
    try {
      return (int) $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $uid)
        ->condition('status', 1)
        ->count()
        ->execute();
    }
    catch (\Throwable) {
      return 0;
    }
  }

  private function organiserHasDuplicateDraftPattern(int $uid): bool {
    try {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $uid)
        ->condition('title', '%(Copy)%', 'LIKE')
        ->range(0, 1)
        ->execute();
      return !empty($nids);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  private function organiserHasStaleDrafts(int $uid, int $idleDays): bool {
    if ($idleDays <= 0) {
      return FALSE;
    }
    $cutoff = time() - ($idleDays * 86400);
    try {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'event')
        ->condition('uid', $uid)
        ->condition('status', 0)
        ->condition('changed', $cutoff, '<')
        ->range(0, 1)
        ->execute();
      return !empty($nids);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
