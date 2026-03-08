<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for custom error pages.
 */
final class ErrorController extends ControllerBase {

  /**
   * Renders the custom 403 page.
   */
  public function accessDenied(): array {
    return [
      '#theme' => 'mel_403',
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Renders the custom 404 page.
   */
  public function pageNotFound(): array {
    return [
      '#theme' => 'mel_404',
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
