<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\node\NodeInterface;

/**
 * Controller for placeholder steps (Promote, Payments, Comms, Advanced).
 */
final class ManageEventPlaceholderController extends ManageEventControllerBase {

  /**
   * Renders a placeholder page.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Render array.
   */
  public function placeholder(NodeInterface $event): array {
    // Get step and title from current route.
    $route_name = \Drupal::routeMatch()->getRouteName();
    $route = \Drupal::routeMatch()->getRouteObject();
    $step = $route_name;
    $title = '';

    if ($route) {
      $defaults = $route->getDefaults();
      $title = $defaults['title'] ?? '';
    }
    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-manage-event-content', 'mel-placeholder']],
      'message' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['mel-placeholder-message']],
        'icon' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['mel-placeholder-icon']],
          '#value' => '🚧',
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('This feature is coming soon.'),
        ],
      ],
    ];

    return $this->buildPage($event, $step, $content, $title);
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageTitle(NodeInterface $event): string {
    $route = \Drupal::routeMatch()->getRouteObject();
    if ($route) {
      $defaults = $route->getDefaults();
      return (string) ($defaults['title'] ?? '');
    }
    return '';
  }

}
