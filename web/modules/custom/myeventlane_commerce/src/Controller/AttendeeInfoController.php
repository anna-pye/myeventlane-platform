<?php

namespace Drupal\myeventlane_commerce\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides attendee info controller routes.
 */
final class AttendeeInfoController extends ControllerBase {

  /**
   * Page callback for the “edit” route.
   */
  public function edit(Request $request): array {
    // Return a render array or redirect as needed.
    return [
      '#type' => 'markup',
      '#markup' => $this->t('Attendee Info – Edit'),
    ];
  }

  /**
   * Page callback for the “build” route.
   */
  public function build(Request $request): array {
    return [
      '#type' => 'markup',
      '#markup' => $this->t('Attendee Info – Build'),
    ];
  }

}
