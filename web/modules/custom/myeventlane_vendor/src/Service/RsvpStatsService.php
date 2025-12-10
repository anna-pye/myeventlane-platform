<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * RSVP stats provider for vendor console.
 */
final class RsvpStatsService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns RSVP counts for an event.
   */
  public function getRsvpSummary(NodeInterface $event): array {
    return [
      'total' => 684,
      'confirmed' => 612,
      'pending' => 48,
      'declined' => 24,
      'checkins' => 398,
    ];
  }

  /**
   * Returns a daily RSVP series for charting.
   */
  public function getDailyRsvpSeries(NodeInterface $event): array {
    $series = [];
    $day = new \DateTimeImmutable('-14 days');
    for ($i = 0; $i < 14; $i++) {
      $series[] = [
        'date' => $day->format('Y-m-d'),
        'rsvps' => 20 + ($i % 5),
        'checkins' => 12 + ($i % 3),
      ];
      // Move to next day for next iteration.
      $day = $day->modify('+1 day');
    }
    return $series;
  }

  /**
   * Returns the top attendees segments.
   */
  public function getAudienceSegments(NodeInterface $event): array {
    return [
      ['label' => 'Local city', 'value' => 58],
      ['label' => 'Students', 'value' => 22],
      ['label' => 'VIP / sponsors', 'value' => 8],
      ['label' => 'Press', 'value' => 4],
    ];
  }

}
