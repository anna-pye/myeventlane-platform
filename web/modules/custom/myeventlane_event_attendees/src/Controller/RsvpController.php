<?php

namespace Drupal\myeventlane_event_attendees\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wrapper controller that displays the RSVP Form.
 */
class RsvpController extends ControllerBase implements ContainerInjectionInterface {

  /**
   *
   */
  public static function create(ContainerInterface $container) {
    return new static();
  }

  /**
   *
   */
  public function form(NodeInterface $node) {
    return [
      '#type' => 'form',
      '#form_id' => 'myeventlane_rsvp_form',
      '#node' => $node,
      '#attached' => [
        'library' => [
          // Add front-end theming later if desired.
        ],
      ],
      '#title' => $node->label(),
    ];
  }

}
