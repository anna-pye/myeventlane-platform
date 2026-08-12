<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Resolves route conflicts for trust foundation pages.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('view.mel_blog.page_blog')) {
      $route->setPath('/blog/list');
    }
  }

}
