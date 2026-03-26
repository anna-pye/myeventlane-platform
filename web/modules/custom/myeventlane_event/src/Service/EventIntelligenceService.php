<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Rules-based, deterministic guidance for event workspace pages.
 *
 * MEL Intelligence Engine: scoring, primary/secondary/upsell classification,
 * context-aware triggers, and subtle monetisation hooks.
 */
final class EventIntelligenceService {

  use StringTranslationTrait;

  private const PRIMARY_MAX = 1;

  private const SECONDARY_MAX = 2;

  private const UPSELL_MAX = 1;

  /**
   * Days within which an event is considered "soon".
   */
  private const SOON_DAYS = 7;

  public function __construct(
    TranslationInterface $string_translation,
    private readonly EventStatsService $eventStats,
    private readonly EventModeManager $eventModeManager,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Returns structured insights: score, primary, secondary, upsell.
   *
   * @return array{
   *   score: int,
   *   primary: array<int, array{title: string, description: string, cta: string, url: string}>,
   *   secondary: array<int, array{title: string, description: string, cta: string, url: string}>,
   *   upsell: array<int, array{title: string, description: string, cta: string, url: string}>
   * }
   */
  public function getInsights(NodeInterface $event): array {
    $empty = [
      'score' => 0,
      'primary' => [],
      'secondary' => [],
      'upsell' => [],
    ];

    if ($event->bundle() !== 'event') {
      return $empty;
    }

    $nid = (int) $event->id();
    $stats = $this->eventStats->getEventStats($nid);
    $attendees = (int) ($stats['attendees_count'] ?? 0);
    $rsvps = (int) ($stats['rsvp_count'] ?? 0);

    $hasImage = $this->hasCoverImage($event);
    $descriptionLength = $this->getDescriptionLength($event);
    $hasDescription = $descriptionLength > 120;

    $capacity = $this->resolveCapacity($event);
    $ticketsEnabled = $this->eventModeManager->isTicketsEnabled($event);
    $rsvpEnabled = $this->eventModeManager->isRsvpEnabled($event);
    $rsvpOnly = $rsvpEnabled && !$ticketsEnabled;

    $fillRate = $capacity > 0 ? $attendees / $capacity : 0.0;
    $lowEngagement = $capacity > 0 && $fillRate < 0.2;
    $highEngagement = $capacity > 0 && $fillRate > 0.6;
    $eventIsSoon = $this->isEventSoon($event);

    $primary = [];
    $secondary = [];
    $upsell = [];

    // --- PRIMARY (max 1): most important next action ---

    // No bookings yet — get first bookings.
    if ($attendees === 0) {
      $url = $ticketsEnabled
        ? $this->safeRouteUrl('myeventlane_boost.boost_page', ['node' => $nid])
        : $this->safeRouteUrl('myeventlane_vendor.console.event_rsvps', ['event' => $nid]);
      if ($url !== '') {
        $primary[] = [
          'title' => (string) $this->t('Start getting your first bookings'),
          'description' => (string) $this->t('Share your event or boost visibility.'),
          'cta' => (string) $this->t('Promote event'),
          'url' => $url,
        ];
      }
    }

    // Event soon + low attendance — push visibility.
    if (empty($primary) && $eventIsSoon && $lowEngagement && $attendees > 0) {
      $url = $this->safeRouteUrl('myeventlane_boost.boost_page', ['node' => $nid]);
      if ($url !== '') {
        $primary[] = [
          'title' => (string) $this->t('Your event is coming up soon'),
          'description' => (string) $this->t('Now is the time to push visibility.'),
          'cta' => (string) $this->t('Boost event'),
          'url' => $url,
        ];
      }
    }

    // Missing image — highest-quality improvement.
    if (empty($primary) && !$hasImage) {
      $url = $this->safeRouteUrl('myeventlane_event.wizard.edit', ['node' => $nid]);
      if ($url !== '') {
        $primary[] = [
          'title' => (string) $this->t('Add a cover image'),
          'description' => (string) $this->t('Events with images get significantly more attention.'),
          'cta' => (string) $this->t('Add image'),
          'url' => $url,
        ];
      }
    }

    // Low engagement (tickets) — Boost.
    if (empty($primary) && $lowEngagement && $ticketsEnabled) {
      $url = $this->safeRouteUrl('myeventlane_boost.boost_page', ['node' => $nid]);
      if ($url !== '') {
        $primary[] = [
          'title' => (string) $this->t('Boost your event'),
          'description' => (string) $this->t('This event could use more visibility.'),
          'cta' => (string) $this->t('Boost event'),
          'url' => $url,
        ];
      }
    }

    // Low engagement (RSVP only).
    if (empty($primary) && $lowEngagement && $rsvpOnly) {
      $url = $this->safeRouteUrl('myeventlane_vendor.console.event_rsvps', ['event' => $nid]);
      if ($url !== '') {
        $primary[] = [
          'title' => (string) $this->t('Room for more guests'),
          'description' => (string) $this->t('There is still capacity — a nudge to your audience can help.'),
          'cta' => (string) $this->t('View RSVPs'),
          'url' => $url,
        ];
      }
    }

    // Nearly full — manage capacity.
    if (empty($primary) && $capacity > 0 && $attendees >= (int) floor($capacity * 0.8)) {
      if ($ticketsEnabled) {
        $url = $this->safeRouteUrl('myeventlane_vendor.console.event_tickets', ['event' => $nid]);
        $cta = (string) $this->t('Manage tickets');
        $description = (string) $this->t('Consider adding more tickets or a waitlist.');
      }
      elseif ($rsvpEnabled) {
        $url = $this->safeRouteUrl('myeventlane_vendor.console.event_rsvps', ['event' => $nid]);
        $cta = (string) $this->t('Manage RSVPs');
        $description = (string) $this->t('Consider increasing RSVP capacity or enabling a waitlist.');
      }
      else {
        $url = '';
        $cta = (string) $this->t('Review event');
        $description = '';
      }
      if ($url !== '') {
        $primary[] = [
          'title' => (string) $this->t('Nearly at capacity'),
          'description' => $description,
          'cta' => $cta,
          'url' => $url,
        ];
      }
    }

    // --- SECONDARY (max 2): improvements ---

    // Weak description.
    if (!$hasDescription) {
      $url = $this->safeRouteUrl('myeventlane_event.wizard.edit', ['node' => $nid]);
      if ($url !== '') {
        $secondary[] = [
          'title' => (string) $this->t('Expand your event details'),
          'description' => (string) $this->t('Clear details help people decide faster.'),
          'cta' => (string) $this->t('Edit details'),
          'url' => $url,
        ];
      }
    }

    // --- UPSELL (max 1): monetisation, subtle ---

    // Low engagement → Boost.
    if ($lowEngagement && $ticketsEnabled) {
      $url = $this->safeRouteUrl('myeventlane_boost.boost_page', ['node' => $nid]);
      if ($url !== '') {
        $upsell[] = [
          'title' => (string) $this->t('Get more visibility'),
          'description' => (string) $this->t('Reach more people with event Boost.'),
          'cta' => (string) $this->t('Boost event'),
          'url' => $url,
        ];
      }
    }

    // High engagement → Pro analytics.
    if ($highEngagement) {
      $url = $this->safeRouteUrl('myeventlane_pro.overview', []);
      if ($url !== '') {
        $upsell[] = [
          'title' => (string) $this->t('Unlock deeper insights'),
          'description' => (string) $this->t('See conversion and traffic data.'),
          'cta' => (string) $this->t('Upgrade to Pro'),
          'url' => $url,
        ];
      }
    }

    // Limit output.
    $primary = array_slice($primary, 0, self::PRIMARY_MAX);
    $secondary = array_slice($secondary, 0, self::SECONDARY_MAX);
    $upsell = array_slice($upsell, 0, self::UPSELL_MAX);

    // Avoid duplicate: if primary is already a Boost CTA, don’t repeat in upsell.
    $boostCta = (string) $this->t('Boost event');
    $primaryCta = $primary[0]['cta'] ?? '';
    if ($primaryCta === $boostCta && !empty($upsell)) {
      $upsell = array_values(array_filter($upsell, fn (array $u): bool => ($u['cta'] ?? '') !== $boostCta));
      $upsell = array_slice($upsell, 0, self::UPSELL_MAX);
    }

    $score = $this->calculateScore($event, $stats);

    return [
      'score' => $score,
      'primary' => $primary,
      'secondary' => $secondary,
      'upsell' => $upsell,
    ];
  }

  /**
   * Calculates event quality score (0–100).
   */
  private function calculateScore(NodeInterface $event, array $stats): int {
    $score = 0;

    if ($this->hasCoverImage($event)) {
      $score += 20;
    }

    $descriptionLength = $this->getDescriptionLength($event);
    if ($descriptionLength > 200) {
      $score += 20;
    }
    elseif ($descriptionLength > 100) {
      $score += 10;
    }

    $capacity = $this->resolveCapacity($event);
    $attendees = (int) ($stats['attendees_count'] ?? 0);

    if ($capacity > 0) {
      $fillRate = $attendees / $capacity;
      if ($fillRate > 0.8) {
        $score += 25;
      }
      elseif ($fillRate > 0.4) {
        $score += 15;
      }
    }

    return min($score, 100);
  }

  private function hasCoverImage(NodeInterface $event): bool {
    if (!$event->hasField('field_event_image')) {
      return TRUE;
    }
    return !$event->get('field_event_image')->isEmpty();
  }

  private function getDescriptionLength(NodeInterface $event): int {
    $chunks = [];
    if ($event->hasField('body') && !$event->get('body')->isEmpty()) {
      $chunks[] = (string) ($event->get('body')->value ?? '');
    }
    if ($event->hasField('field_event_intro') && !$event->get('field_event_intro')->isEmpty()) {
      $chunks[] = (string) ($event->get('field_event_intro')->value ?? '');
    }
    $text = strip_tags(implode("\n", $chunks));

    return strlen(trim($text));
  }

  private function resolveCapacity(NodeInterface $event): int {
    if ($event->hasField('field_event_capacity_total') && !$event->get('field_event_capacity_total')->isEmpty()) {
      return (int) $event->get('field_event_capacity_total')->value;
    }
    if ($event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      return (int) $event->get('field_capacity')->value;
    }

    return 0;
  }

  private function isEventSoon(NodeInterface $event): bool {
    if (!$event->hasField('field_event_start') || $event->get('field_event_start')->isEmpty()) {
      return FALSE;
    }
    try {
      $start = $event->get('field_event_start')->date;
      if (!$start instanceof DrupalDateTime) {
        return FALSE;
      }
      $now = new \DateTimeImmutable('now', $start->getTimezone());
      $startImmutable = \DateTimeImmutable::createFromMutable($start->getPhpDateTime());
      $diff = $now->diff($startImmutable);
      $days = (int) $diff->format('%r%a');

      return $days >= 0 && $days <= self::SOON_DAYS;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  private function safeRouteUrl(string $route, array $parameters): string {
    try {
      return Url::fromRoute($route, $parameters)->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
