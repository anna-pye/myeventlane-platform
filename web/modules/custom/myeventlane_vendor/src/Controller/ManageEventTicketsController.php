<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the "Ticket types" step.
 */
final class ManageEventTicketsController extends ManageEventControllerBase {

  /**
   * Redirects legacy singular /vendor/event/{event}/tickets to plural workspace URL.
   */
  public function redirectToCanonicalTickets(NodeInterface $event): RedirectResponse {
    return $this->redirect('myeventlane_vendor.console.event_tickets', [
      'event' => $event->id(),
    ], 302);
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageTitle(NodeInterface $event): string {
    return (string) $this->t('Ticket types');
  }

}
