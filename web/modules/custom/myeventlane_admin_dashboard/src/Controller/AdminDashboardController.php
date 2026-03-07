<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Shell controller for the admin dashboard root.
 */
final class AdminDashboardController {

  /**
   * Constructs the shell admin controller.
   */
  public function __construct(
    private readonly PlatformControlCentreController $platformControlCentreController,
  ) {}

  /**
   * Delegates admin root dashboard rendering.
   */
  public function dashboard(Request $request): array {
    return $this->platformControlCentreController->overview($request);
  }

}
