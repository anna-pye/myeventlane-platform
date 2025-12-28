<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class TrendingScoreService {

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function score(int $event_nid): int {
    $week_ago = $this->time->getRequestTime() - (7 * 86400);

    $recent = (int) $this->database->select('myeventlane_rsvp', 'r')
      ->condition('event_nid', $event_nid)
      ->condition('created', $week_ago, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Check if event is boosted via node fields.
    $boosted = FALSE;
    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $event = $nodeStorage->load($event_nid);
      if ($event && $event->bundle() === 'event') {
        $promoted = (bool) ($event->get('field_promoted')->value ?? FALSE);
        $expiresValue = $event->get('field_promo_expires')->value ?? NULL;
        
        if ($promoted && $expiresValue) {
          try {
            $expires = new \DateTimeImmutable($expiresValue, new \DateTimeZone('UTC'));
            $boosted = $expires->getTimestamp() > $this->time->getRequestTime();
          }
          catch (\Exception) {
            // Invalid date.
          }
        }
      }
    }
    catch (\Exception) {
      // Entity manager not available.
    }

    return ($recent * 2) + ($boosted ? 10 : 0);
  }

}
