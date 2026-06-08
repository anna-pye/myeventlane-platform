<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Derives a single public interest label from save count, viewer count, and node age.
 */
final class EventInterestService {

  public function __construct(
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Returns at most one label; priority order: high demand → selling fast → popular → just added.
   *
   * @param 'auto'|'show'|'hide' $socialProofMode
   *
   * @return array{label: string, type: 'high'|'urgent'|'social'|'new'}|array{}
   */
  public function getInterestState(int $saveCount, int $viewerCount, int $createdTime, string $socialProofMode = 'auto'): array {
    if ($socialProofMode === 'hide') {
      return [];
    }

    $now = $this->time->getCurrentTime();
    $age = $now - $createdTime;

    if ($socialProofMode === 'show') {
      if ($saveCount >= 20 && $viewerCount >= 5) {
        return [
          'label' => '🔥 High demand',
          'type' => 'high',
        ];
      }
      if ($viewerCount > 0) {
        return [
          'label' => '🔥 Selling fast',
          'type' => 'urgent',
        ];
      }
      if ($saveCount > 0) {
        return [
          'label' => '💖 Popular',
          'type' => 'social',
        ];
      }
      if ($age < 172800) {
        return [
          'label' => '✨ Just added',
          'type' => 'new',
        ];
      }
      return [];
    }

    if ($saveCount >= 20 && $viewerCount >= 5) {
      return [
        'label' => '🔥 High demand',
        'type' => 'high',
      ];
    }

    if ($viewerCount >= 5) {
      return [
        'label' => '🔥 Selling fast',
        'type' => 'urgent',
      ];
    }

    if ($saveCount >= 10) {
      return [
        'label' => '💖 Popular',
        'type' => 'social',
      ];
    }

    if ($age < 172800) {
      return [
        'label' => '✨ Just added',
        'type' => 'new',
      ];
    }

    return [];
  }

}
