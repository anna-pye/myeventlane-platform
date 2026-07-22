<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Service\ManageEventNavigation;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects retired manage-event placeholder steps to Convergence destinations.
 *
 * Prefer redirects over “coming soon” dead ends (VX2-00 trust).
 */
final class ManageEventPlaceholderController extends ManageEventControllerBase {

  public function __construct(
    ManageEventNavigation $navigation,
    private readonly RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($navigation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('myeventlane_vendor.manage_event_navigation'),
      $container->get('current_route_match'),
    );
    $instance->domainDetector = $container->get('myeventlane_core.domain_detector');
    return $instance;
  }

  /**
   * Redirects placeholder routes to Event Workspace / Payments destinations.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the Convergence destination.
   */
  public function placeholder(NodeInterface $event): RedirectResponse {
    $from = (string) ($this->routeMatch->getRouteName() ?? '');
    [$route, $params] = $this->destinationForPlaceholder($from, $event);
    return new RedirectResponse(Url::fromRoute($route, $params)->toString(), 302);
  }

  /**
   * Maps a retired placeholder route to a Convergence destination.
   *
   * @param string $from
   *   Legacy placeholder route name.
   * @param \Drupal\node\NodeInterface $event
   *   The event node in context.
   *
   * @return array{0: string, 1: array<string, int|string>}
   *   Destination route name and parameters.
   */
  private function destinationForPlaceholder(string $from, NodeInterface $event): array {
    $nid = (int) $event->id();
    return match ($from) {
      // Promote / Marketing → Boost (not Messages / Publishing & updates).
      'myeventlane_vendor.manage_event.promote' => [
        'myeventlane_boost.boost_page',
        ['node' => $nid],
      ],
      'myeventlane_vendor.manage_event.comms' => [
        'myeventlane_event_studio.workspace_messaging',
        ['node' => $nid],
      ],
      'myeventlane_vendor.manage_event.payments' => [
        'myeventlane_vendor.console.payouts',
        [],
      ],
      'myeventlane_vendor.manage_event.advanced' => [
        'myeventlane_event_studio.workspace_settings',
        ['node' => $nid],
      ],
      default => [
        'myeventlane_event_studio.workspace',
        ['node' => $nid],
      ],
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageTitle(NodeInterface $event): string {
    return '';
  }

}
