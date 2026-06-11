<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;

/**
 * Canonical redirects for consolidated trust destinations.
 */
final class TrustRedirectController extends ControllerBase {

  /**
   * Redirects legacy /refund-policy to the Help Centre refund article.
   */
  public function refundPolicy(): LocalRedirectResponse {
    return new LocalRedirectResponse('/help/policies/refund-policy', 301);
  }

}
