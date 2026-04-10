<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records support panel impressions and clicks.
 */
final class HelpPanelAnalytics {

  public function __construct(
    private readonly Connection $database,
    private readonly RequestStack $requestStack,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public function logImpression(string $key): void {
    $this->logEvent($key, 'impression');
  }

  public function logClick(string $key): void {
    $this->logEvent($key, 'click');
  }

  private function logEvent(string $key, string $eventType): void {
    $panelKey = trim($key);
    if ($panelKey === '') {
      return;
    }

    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
    $created = (int) $this->time->getRequestTime();

    try {
      $this->database->insert('mel_help_panel_analytics')
        ->fields([
          'panel_key' => mb_substr($panelKey, 0, 128),
          'event_type' => $eventType,
          'path' => mb_substr($path, 0, 255),
          'created' => $created,
        ])
        ->execute();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to record @type for help panel @key: @message', [
        '@type' => $eventType,
        '@key' => $panelKey,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
