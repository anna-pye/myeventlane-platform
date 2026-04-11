<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records support panel impressions, clicks, and feedback.
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

  public function logFeedback(string $key, bool $helpful): void {
    $this->logEvent($key, 'feedback', $helpful);
  }

  public function getCacheTag(): string {
    return 'mel_help_panel_analytics';
  }

  private function logEvent(string $key, string $eventType, ?bool $isHelpful = NULL): void {
    $panelKey = trim($key);
    if ($panelKey === '') {
      return;
    }

    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
    $created = (int) $this->time->getRequestTime();

    try {
      $fields = [
        'panel_key' => mb_substr($panelKey, 0, 128),
        'event_type' => $eventType,
        'path' => mb_substr($path, 0, 255),
        'created' => $created,
      ];
      if ($isHelpful !== NULL) {
        $fields['is_helpful'] = $isHelpful ? 1 : 0;
      }

      $this->database->insert('mel_help_panel_analytics')
        ->fields($fields)
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
