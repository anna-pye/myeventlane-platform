<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_vendor\Service\ManageEventNavigation;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for placeholder steps (Promote, Payments, Comms, Advanced).
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
    return new static(
      $container->get('myeventlane_vendor.manage_event_navigation'),
      $container->get('current_route_match'),
    );
  }

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
    $route = $this->routeMatch->getRouteObject();
    $step = $this->routeMatch->getRouteName() ?? '';
    $title = '';

    if ($route) {
      $defaults = $route->getDefaults();
      $title = $defaults['title'] ?? '';
    }
    $content = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mel-manage-event-content',
          'mel-placeholder',
          'mel-coming-soon-inner',
        ],
      ],
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

    $page = $this->buildPage($event, $step, $content, (string) $title);
    $page['#placeholder_shell'] = TRUE;
    $page['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'robots',
          'content' => 'noindex, nofollow',
        ],
      ],
      'mel_manage_event_placeholder_robots',
    ];

    return $page;
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageTitle(NodeInterface $event): string {
    $route = $this->routeMatch->getRouteObject();
    if ($route) {
      $defaults = $route->getDefaults();
      return (string) ($defaults['title'] ?? '');
    }
    return '';
  }

}
