<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records support panel impressions, clicks, and feedback.
 *
 * mel_help_panel_analytics.event_type values include:
 * - impression, click, feedback (standard help panel)
 * - boost_click (Boost CTA on vendor support panel)
 * - conversion_after_boost (conversion improved vs session baseline; optional metrics)
 *
 * @see \Drupal\myeventlane_help_centre\Service\HelpPanelAnalytics::EVENT_TYPE_CONVERSION_AFTER_BOOST
 */
final class HelpPanelAnalytics {

  public const EVENT_TYPE_CONVERSION_AFTER_BOOST = 'conversion_after_boost';

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

  /**
   * Records a Boost CTA click (same storage as clicks; distinct event_type).
   */
  public function logBoostClick(string $key): void {
    $this->logEvent($key, 'boost_click');
  }

  public function logFeedback(string $key, bool $helpful): void {
    $this->logEvent($key, 'feedback', ['helpful' => $helpful]);
  }

  /**
   * Records improved aggregate conversion vs stored baseline (Boost optimisation loop).
   *
   * @param float|null $baselineConversion
   *   Prior baseline rate (e.g. session snapshot); stored when provided.
   */
  public function logConversionAfterBoost(string $key, float $conversion, ?float $baselineConversion = NULL): void {
    $options = ['conversion' => $conversion];
    if ($baselineConversion !== NULL) {
      $options['baseline'] = $baselineConversion;
    }
    $this->logEvent($key, self::EVENT_TYPE_CONVERSION_AFTER_BOOST, $options);
  }

  public function getCacheTag(): string {
    return 'mel_help_panel_analytics';
  }

  /**
   * @param array{helpful?: bool, conversion?: float, baseline?: float} $options
   */
  private function logEvent(string $key, string $eventType, array $options = []): void {
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
      if (array_key_exists('helpful', $options)) {
        $fields['is_helpful'] = $options['helpful'] ? 1 : 0;
      }
      if (isset($options['conversion'])) {
        $fields['metric_value'] = $options['conversion'];
      }
      if (isset($options['baseline'])) {
        $fields['metric_value_baseline'] = $options['baseline'];
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
