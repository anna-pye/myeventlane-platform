<?php

namespace Drupal\myeventlane_views\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * A simple controller to test access plugin.
 */
class TestAccessController extends ControllerBase {

  /**
   *
   */
  public function content() {
    return [
      '#markup' => $this->t('✅ Access plugin worked — you made it into the route.'),
    ];
  }

}
