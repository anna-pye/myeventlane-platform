<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Simple controller wrapper for RSVP form.
 */
class RsvpFormController extends ControllerBase {

  /**
   * Builds RSVP form page.
   */
  public function form($node) {
    $form = $this->formBuilder()->getForm('\Drupal\myeventlane_rsvp\Form\RsvpPublicForm', $node);

    return [
      '#theme' => 'rsvp_page_wrapper',
      '#form' => $form,
      '#node' => $node,
    ];
  }

}