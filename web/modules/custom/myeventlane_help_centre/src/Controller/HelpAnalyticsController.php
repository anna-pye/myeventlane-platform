<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\myeventlane_help_centre\Service\HelpPanelAnalytics;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles Help panel analytics events.
 */
final class HelpAnalyticsController implements ContainerInjectionInterface {

  public function __construct(
    private readonly HelpPanelAnalytics $analytics,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_help.analytics'),
    );
  }

  public function track(Request $request): JsonResponse {
    $data = json_decode($request->getContent() ?: '', TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid payload'], 400);
    }
    $key = trim((string) ($data['key'] ?? ''));
    if ($key === '') {
      return new JsonResponse(['status' => 'error', 'message' => 'Missing key'], 400);
    }

    $type = trim((string) ($data['type'] ?? ''));
    if ($type === 'boost_click') {
      $this->analytics->logBoostClick($key);
    }
    else {
      $this->analytics->logClick($key);
    }

    return new JsonResponse(['status' => 'ok']);
  }

}
