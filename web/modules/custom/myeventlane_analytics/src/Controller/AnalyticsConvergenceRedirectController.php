<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Converges legacy Insights / Charts / Export Centre URLs into Analytics.
 *
 * Product name is Analytics. These routes remain as soft redirects only.
 */
final class AnalyticsConvergenceRedirectController extends ControllerBase {

  /**
   * Redirects /vendor/insights → Analytics hub.
   */
  public function vendorInsights(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('myeventlane_analytics.dashboard')->toString(),
      302,
    );
  }

  /**
   * Redirects event Insights tabs → Event Workspace Analytics (free pulse).
   */
  public function eventInsights(NodeInterface $event): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('myeventlane_event_studio.workspace_analytics', [
        'node' => $event->id(),
      ])->toString(),
      302,
    );
  }

  /**
   * Redirects Export Centre → Analytics Pro exports section.
   */
  public function exportCentre(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('myeventlane_analytics.dashboard', [], [
        'fragment' => 'exports',
      ])->toString(),
      302,
    );
  }

}
