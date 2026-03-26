<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\EventSubscriber;

use Drupal\myeventlane_help_centre\Event\HelpAnalyticsRecordedEvent;
use Drupal\myeventlane_help_improvement\Service\DocumentationOpportunityCaptureService;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens for Help analytics writes and records documentation opportunities.
 */
final class HelpAnalyticsOpportunitySubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DocumentationOpportunityCaptureService $capture,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      HelpAnalyticsRecordedEvent::NAME => ['onAnalytics', 0],
    ];
  }

  public function onAnalytics(HelpAnalyticsRecordedEvent $event): void {
    try {
      $this->capture->recordFromAnalyticsEvent($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Help analytics opportunity subscriber failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
