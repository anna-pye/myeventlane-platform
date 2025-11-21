<?php

namespace Drupal\myeventlane_rsvp\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\node\NodeInterface;

/**
 * @Block(
 *   id = "mel_rsvp_form_block",
 *   admin_label = @Translation("MyEventLane RSVP Form")
 * )
 */
final class RsvpFormBlock extends BlockBase {

  public function build() {
    $node = \Drupal::routeMatch()->getParameter('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      return \Drupal::formBuilder()->getForm(
        '\Drupal\myeventlane_rsvp\Form\RsvpPublicForm',
        $node
      );
    }

    return ['#markup' => ''];
  }
}