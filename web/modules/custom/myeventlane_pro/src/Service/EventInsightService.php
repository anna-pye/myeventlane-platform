<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

/**
 * Turns raw event analytics into actionable insights.
 *
 * Deterministic and explainable — no AI or external dependencies.
 * Extensible for optional AI layer later.
 */
final class EventInsightService {

  /**
   * Builds actionable insights from event data.
   *
   * @param array<string, mixed> $event_data
   *   Event data including tickets_sold, capacity, percent_sold, id.
   *
   * @return array<int, array{type: string, message: string, cta?: string}>
   *   Array of insight items.
   */
  public function buildInsights(array $event_data): array {
    $insights = [];

    $sold = (int) ($event_data['tickets_sold'] ?? 0);
    $capacity = $event_data['capacity'] ?? NULL;
    $percent = (float) ($event_data['percent_sold'] ?? 0);
    $eventId = (int) ($event_data['id'] ?? 0);
    $isPublished = (bool) ($event_data['is_published'] ?? TRUE);
    $isEligibleBoost = (bool) ($event_data['boost_allowed'] ?? FALSE);
    $boostUrl = $event_data['boost_url'] ?? $event_data['boost_wizard_url'] ?? NULL;
    if ($boostUrl !== NULL) {
      $boostUrl = (string) $boostUrl;
    }

    if ($capacity !== NULL) {
      $capacity = (int) $capacity;
    }

    // Nearly sold out (80%+).
    if ($capacity > 0 && $percent >= 80) {
      $insights[] = [
        'type' => 'success',
        'message' => t('Your event is nearly sold out 🎉'),
      ];
    }

    // Low engagement — suggest Boost (when event is published and eligible).
    if ($percent < 20 && $sold > 0 && $isPublished && $isEligibleBoost) {
      $cta = NULL;
      if ($boostUrl !== '' && $boostUrl !== NULL) {
        $cta = $boostUrl;
      }
      elseif ($eventId > 0) {
        $cta = '/event/' . $eventId . '/boost';
      }
      $insights[] = [
        'type' => 'upsell',
        'message' => t('Ticket sales are slow — consider boosting your event.'),
        'cta' => $cta,
      ];
    }

    // Low engagement — generic warning when no Boost CTA.
    if ($percent < 20 && $sold > 0 && empty(array_filter($insights, fn($i) => ($i['type'] ?? '') === 'upsell'))) {
      $insights[] = [
        'type' => 'warning',
        'message' => t('Ticket sales are slow — consider boosting your event.'),
      ];
    }

    // No tickets yet.
    if ($sold === 0) {
      $insights[] = [
        'type' => 'info',
        'message' => t('No tickets sold yet — share your event to get started.'),
      ];
    }

    // Capacity opportunity (90%+).
    if ($capacity > 0 && $percent >= 90) {
      $insights[] = [
        'type' => 'upsell',
        'message' => t('High demand — you could add more tickets.'),
      ];
    }

    return $insights;
  }

}
