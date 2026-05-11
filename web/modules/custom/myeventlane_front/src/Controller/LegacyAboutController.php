<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;

/**
 * Redirects legacy About links to the current public help hub.
 */
final class LegacyAboutController extends ControllerBase {

  /**
   * Redirects the retired /about entry point.
   */
  public function redirectAbout(): LocalRedirectResponse {
    return new LocalRedirectResponse('/help', 301);
  }

}
