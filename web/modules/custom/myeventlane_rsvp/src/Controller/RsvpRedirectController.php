<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirects old RSVP form route to unified Commerce booking route.
 */
final class RsvpRedirectController extends ControllerBase {

  /**
   * Redirects to the Commerce booking page.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to Commerce booking page.
   */
  public function redirectToBooking(NodeInterface $event): RedirectResponse {
    $url = Url::fromRoute('myeventlane_commerce.event_book', ['node' => $event->id()], ['absolute' => TRUE]);
    return new RedirectResponse($url->toString(), 301);
  }

}
