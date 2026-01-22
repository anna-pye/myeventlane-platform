<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Plugin\Menu;

use Drupal\Core\Menu\MenuLinkDefault;
use Drupal\Core\Menu\StaticMenuLinkOverridesInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Menu link plugin that dynamically provides the current user parameter.
 */
class MyRsvpsMenuLink extends MenuLinkDefault {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    StaticMenuLinkOverridesInterface $static_override,
    AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $static_override);
    $this->currentUser = $currentUser;

    // Set route parameters dynamically based on current user.
    if (!$this->currentUser->isAnonymous()) {
      $this->pluginDefinition['route_parameters'] = [
        'user' => $this->currentUser->id(),
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
      $container->get('menu_link.static.overrides'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters() {
    if (!$this->currentUser->isAnonymous()) {
      return [
        'user' => $this->currentUser->id(),
      ];
    }
    // Use 0 so URL generation never receives []; _user_is_logged_in hides link.
    return ['user' => 0];
  }

}
