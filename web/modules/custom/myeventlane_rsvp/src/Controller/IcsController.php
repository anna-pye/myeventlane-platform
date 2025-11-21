<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;

final class IcsController extends ControllerBase {

  public function download(NodeInterface $node): Response {
    $ics = $this->ics()->generate($node);

    $filename = 'event-' . $node->id() . '.ics';

    $response = new Response($ics);
    $response->headers->set('Content-Type', 'text/calendar; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

  private function ics() {
    return \Drupal::service('myeventlane_rsvp.ics');
  }
}