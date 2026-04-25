<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Tracks recent page view timestamps per event in State for a soft viewer count.
 */
final class EventViewerCountService {

  private const WINDOW = 600;

  public function __construct(
    private StateInterface $state,
    private TimeInterface $time,
  ) {
  }

  /**
   * Appends a view timestamp; keeps only the last 10 minutes of data.
   */
  public function recordView(int $nid): void {
    $this->recordViewAndGetCount($nid);
  }

  /**
   * Records this request and returns the in-window count in one state round-trip.
   *
   * Prefer this over recordView() + getViewerCount() in the same request to avoid
   * redundant get/set cycles on the State entry.
   */
  public function recordViewAndGetCount(int $nid): int {
    if ($nid < 1) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $key = $this->key($nid);
    $raw = $this->state->get($key, []);
    $views = is_array($raw) ? $this->prune($raw, $now) : [];
    $views[] = $now;
    $this->state->set($key, array_values($views));
    return count($views);
  }

  /**
   * Counts “views” in the last ~10 minutes and persists a pruned list.
   */
  public function getViewerCount(int $nid): int {
    if ($nid < 1) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $key = $this->key($nid);
    $raw = $this->state->get($key, []);
    if (!is_array($raw)) {
      return 0;
    }
    $views = $this->prune($raw, $now);
    $this->state->set($key, array_values($views));
    return count($views);
  }

  private function key(int $nid): string {
    return 'mel_event_views_' . $nid;
  }

  /**
   * @param list<mixed> $views
   *
   * @return list<int>
   */
  private function prune(array $views, int $now): array {
    $out = [];
    $cut = $now - self::WINDOW;
    foreach ($views as $t) {
      if (!is_int($t) && !(is_string($t) && is_numeric($t))) {
        continue;
      }
      $ts = (int) $t;
      if ($ts > $cut) {
        $out[] = $ts;
      }
    }
    return $out;
  }

}
