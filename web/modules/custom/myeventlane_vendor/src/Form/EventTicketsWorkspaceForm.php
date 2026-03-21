<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_vendor\Ticketing\EventTicketsBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form API root for standalone vendor ticket workspace pages; UI is built by service.
 */
final class EventTicketsWorkspaceForm extends FormBase {

  protected EventTicketsBuilder $ticketBuilder;

  public function __construct(EventTicketsBuilder $ticket_builder) {
    $this->ticketBuilder = $ticket_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_vendor.ticket_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mel_event_tickets_workspace';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    $event = $event ?? $form_state->get('event');
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return $form;
    }

    $form_state->set('event', $event);
    $form['#event'] = $event;

    $form_state->set('mel_ticket_builder_value_prefix', []);
    $this->ticketBuilder->build($form, $form_state, $event);

    return $form;
  }

  /**
   * Resolves the event node from form state (standalone workspace root).
   */
  private function getEventOrInjectedEvent(FormStateInterface $form_state): NodeInterface {
    $event = $form_state->get('event');
    if (!$event instanceof NodeInterface) {
      throw new \RuntimeException('Ticket workspace form is missing event context.');
    }
    return $event;
  }

  public function handleAction(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEventOrInjectedEvent($form_state);
    $this->ticketBuilder->handleAction($form, $form_state, $event);
  }

  public function ajaxRebuildTicketBuilder(array &$form, FormStateInterface $form_state): array {
    return $form['builder_shell'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
