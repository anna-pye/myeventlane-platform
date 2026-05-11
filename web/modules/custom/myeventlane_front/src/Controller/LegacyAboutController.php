<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;

/**
 * Redirects legacy front-page content routes to the current public help hub.
 */
final class LegacyAboutController extends ControllerBase {

  /**
   * Redirects retired footer entry points.
   */
  public function redirectToHelp(): LocalRedirectResponse {
    return new LocalRedirectResponse('/help', 301);
  }

}
