<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Plugin\Menu;

use Drupal\Core\Menu\MenuLinkDefault;
use Drupal\Core\Menu\StaticMenuLinkOverridesInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Menu link plugin that dynamically provides the current user parameter.
 */
class MyRsvpsMenuLink extends MenuLinkDefault {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    StaticMenuLinkOverridesInterface $static_override,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $static_override);
    
    // Set route parameters dynamically based on current user.
    $current_user = \Drupal::currentUser();
    if (!$current_user->isAnonymous()) {
      $this->pluginDefinition['route_parameters'] = [
        'user' => $current_user->id(),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('menu_link.static.overrides')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters() {
    $current_user = \Drupal::currentUser();
    if (!$current_user->isAnonymous()) {
      return [
        'user' => $current_user->id(),
      ];
    }
    return [];
  }

}


















