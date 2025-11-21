<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

final class RsvpThankYouController extends ControllerBase {

  public function page(NodeInterface $event): array {
    return [
      '#theme' => 'mel_rsvp_thankyou',
      '#title' => $this->t('RSVP Confirmed'),
      '#event' => $event,
    ];
  }
}