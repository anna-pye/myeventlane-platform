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

  /**
   * Redirects retired blog entry points.
   */
  public function redirectToBlog(): LocalRedirectResponse {
    return new LocalRedirectResponse('/blog', 301);
  }

  /**
   * Redirects retired privacy entry points.
   */
  public function redirectToPrivacy(): LocalRedirectResponse {
    return new LocalRedirectResponse('/privacy', 301);
  }

  /**
   * Redirects retired terms entry points.
   */
  public function redirectToTerms(): LocalRedirectResponse {
    return new LocalRedirectResponse('/terms', 301);
  }

  /**
   * Redirects retired cookie policy entry points.
   */
  public function redirectToCookies(): LocalRedirectResponse {
    return new LocalRedirectResponse('/cookies', 301);
  }

}
