<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\myeventlane_vendor\Form\EventTicketsWorkspaceForm;
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
   * Renders the ticket types management form.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Render array.
   */
  public function tickets(NodeInterface $event): array {
    $form = $this->formBuilder()->getForm(EventTicketsWorkspaceForm::class, $event);

    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-manage-event-content', 'mel-tickets-step']],
      'form' => $form,
    ];

    return $this->buildPage($event, 'myeventlane_vendor.manage_event.tickets', $content);
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageTitle(NodeInterface $event): string {
    return (string) $this->t('Ticket types');
  }

}
