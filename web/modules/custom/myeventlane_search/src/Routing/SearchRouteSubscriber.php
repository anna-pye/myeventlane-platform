<?php

declare(strict_types=1);

namespace Drupal\myeventlane_search\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Removes the core Search module /search route so MEL search owns /search.
 */
final class SearchRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($collection->get('search.view') !== NULL) {
      $collection->remove('search.view');
    }
  }

}
