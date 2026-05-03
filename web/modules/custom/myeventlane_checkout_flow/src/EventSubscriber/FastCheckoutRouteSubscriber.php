<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds MEL fast checkout access checks to Stripe express confirmation.
 */
final class FastCheckoutRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('commerce_stripe.express_checkout.payment_confirm');
    if ($route === NULL) {
      return;
    }

    $route->setRequirement('_custom_access', 'myeventlane_checkout_flow.fast_checkout_access_check::access');
  }

}
