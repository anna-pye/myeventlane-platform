<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;

/**
 * Provides automation nudges and candidates for the organiser dashboard.
 *
 * Uses Pro access layer for feature gating. No role checks in Twig.
 */
final class AutomationDashboardService {

  public function __construct(
    private readonly AutomationRuleService $ruleService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly ?\Drupal\myeventlane_pro\Service\ProAccessService $proAccess = NULL,
  ) {}

  /**
   * Builds automation cards for dashboard display.
   *
   * @param array<int, array<string, mixed>> $events
   *   Event rows from dashboard (id, title, is_published, is_ended, etc).
   *
   * @return array<int, array<string, mixed>>
   *   Automation cards with: type, key, severity, title, message, cta_url,
   *   pro_feature, pro_locked, event_id, event_title.
   */
  public function getAutomationsForEvents(array $events): array {
    $config = $this->configFactory->get('myeventlane_automation.settings');
    if (!$config->get('enabled')) {
      return [];
    }

    $account = $this->currentUser->getAccount();
    $isPro = $this->proAccess && $this->proAccess->isPro($account);

    $cards = [];

    foreach ($events as $event) {
      $eventData = $this->mapEventRowToEventData($event);
      $candidates = $this->ruleService->evaluateEventAutomations($eventData);

      foreach ($candidates as $c) {
        $proFeature = $c['pro_feature'] ?? NULL;
        $proLocked = $proFeature !== NULL && !$isPro;

        $ctaUrl = NULL;
        if (!empty($c['cta_route'])) {
          try {
            $params = $c['cta_params'] ?? ['node' => (int) ($event['id'] ?? 0)];
            $ctaUrl = Url::fromRoute($c['cta_route'], $params)->toString();
          }
          catch (\Throwable) {
            // Route may not exist.
          }
        }

        $cards[] = [
          'type' => $c['type'] ?? 'organiser_nudge',
          'key' => $c['key'] ?? '',
          'severity' => $c['severity'] ?? 'info',
          'title' => $c['title'] ?? '',
          'message' => $c['message'] ?? '',
          'cta_url' => $ctaUrl,
          'cta_label' => $this->getCtaLabel($c['key'], $proLocked),
          'pro_feature' => $proFeature,
          'pro_locked' => $proLocked,
          'pro_upsell' => $proLocked ? t('Available with MEL Pro') : NULL,
          'event_id' => (int) ($event['id'] ?? 0),
          'event_title' => (string) ($event['title'] ?? ''),
        ];
      }
    }

    return array_slice($cards, 0, 10);
  }

  private function mapEventRowToEventData(array $event): array {
    return [
      'id' => (int) ($event['id'] ?? 0),
      'title' => (string) ($event['title'] ?? ''),
      'is_published' => (string) ($event['status_badge'] ?? '') !== 'draft',
      'is_ended' => (bool) ($event['is_ended'] ?? FALSE),
      'capacity' => $event['capacity'] ?? NULL,
      'tickets_sold' => (int) ($event['tickets_sold'] ?? 0),
      'rsvps' => (int) ($event['rsvps'] ?? 0),
      'percent_sold' => (float) ($event['percent_sold'] ?? 0),
      'start_timestamp' => (int) ($event['start_timestamp'] ?? 0),
      'end_timestamp' => (int) ($event['end_timestamp'] ?? 0),
      'is_boosted' => (bool) ($event['boost']['is_boosted'] ?? FALSE),
      'boost_eligible' => (bool) ($event['boost']['allowed'] ?? FALSE),
      'cta_params' => ['node' => (int) ($event['id'] ?? 0)],
      'attendee_count' => (int) ($event['filled_count'] ?? 0),
      'has_meaningful_setup' => TRUE,
      'created_timestamp' => 0,
    ];
  }

  private function getCtaLabel(string $key, bool $proLocked): string {
    if ($proLocked) {
      return (string) t('Automate attendee reminders with MEL Pro');
    }
    $labels = [
      'low_sales_boost' => t('Boost event'),
      'no_sales_yet' => t('Share event'),
      'nearly_sold_out' => t('View event'),
      'event_ended_rerun' => t('Run again'),
      'draft_reminder' => t('Finish & publish'),
      'attendee_reminder' => t('Send reminder'),
      'post_event_followup' => t('View attendees'),
      'auto_boost_candidate' => t('Review boost'),
    ];
    return (string) ($labels[$key] ?? t('View'));
  }

}
