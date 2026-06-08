<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_core\Service\AnalyticsService;
use Drupal\myeventlane_core\Service\DiscoveryAttributionSources;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles lightweight public analytics events.
 */
final class PublicAnalyticsController extends ControllerBase {

  /**
   * Constructs a PublicAnalyticsController object.
   */
  public function __construct(
    private readonly AnalyticsService $analytics,
    private readonly DiscoveryAttributionSources $discoveryAttributionSources,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.analytics'),
      $container->get('myeventlane_core.discovery_attribution_sources'),
    );
  }

  /**
   * Tracks a public event-card click.
   */
  public function eventClick(Request $request): JsonResponse {
    $event_id = (int) $request->request->get('event_id', 0);
    if ($event_id <= 0) {
      return new JsonResponse(['status' => 'error'], 400);
    }

    $source = trim((string) $request->request->get('source', ''));
    $source = $source === '' ? NULL : $source;
    if ($source !== NULL && !$this->discoveryAttributionSources->isAllowed($source)) {
      return new JsonResponse(['status' => 'error'], 400);
    }

    $tracked = $this->analytics->track(
      'node',
      $event_id,
      AnalyticsService::EVENT_EVENT_CLICK,
      NULL,
      $source,
    );
    return new JsonResponse(['status' => $tracked ? 'ok' : 'error'], $tracked ? 200 : 500);
  }

}
