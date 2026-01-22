<?php

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_rsvp\Service\IcsGenerator;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for ICS calendar download.
 */
final class IcsController extends ControllerBase {

  /**
   * Constructs IcsController.
   *
   * @param \Drupal\myeventlane_rsvp\Service\IcsGenerator $icsGenerator
   *   The ICS generator service.
   */
  public function __construct(
    private readonly IcsGenerator $icsGenerator,
  ) {}

  /**
   * Downloads ICS file for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The ICS file response.
   */
  public function download(NodeInterface $node): Response {
    $ics = $this->icsGenerator->generate($node);

    $filename = 'event-' . $node->id() . '.ics';

    $response = new Response($ics);
    $response->headers->set('Content-Type', 'text/calendar; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}
