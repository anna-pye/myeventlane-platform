<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\myeventlane_core\Controller\CartPageController;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route subscriber: use single-cart controller for commerce_cart.page.
 */
final class CartPageRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('commerce_cart.page');
    if ($route) {
      $route->setDefault('_controller', CartPageController::class . '::cartPage');
    }
  }

}
