<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mel_ticket\Entity\TicketType;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing ticket types (mel_ticket_type) on an event.
 */
final class EventTicketsForm extends FormBase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected AccountProxyInterface $currentUserProxy;
  protected FormBuilderInterface $formBuilder;
  protected TicketTypeManager $ticketTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUserProxy = $container->get('current_user');
    $instance->formBuilder = $container->get('form_builder');
    $instance->ticketTypeManager = $container->get('myeventlane_event.ticket_type_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_vendor_event_tickets_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    $event = $event ?: $form_state->get('event');
    if (!$event || $event->bundle() !== 'event') {
      return $form;
    }

    if (!$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('You do not have access to manage this event.'));
      return $form;
    }

    $form_state->set('event', $event);
    $form['#event'] = $event;
    $form['#tree'] = TRUE;

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['description']],
      '#value' => $this->t('Manage ticket types for your event. Paid tickets stay in sync with Commerce.'),
    ];

    $ticket_types = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $ticket_types = $event->get('field_ticket_types')->referencedEntities();
    }

    $header = [
      $this->t('Name'),
      $this->t('Kind'),
      $this->t('Price'),
      $this->t('Capacity'),
      $this->t('Status'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($ticket_types as $ticket) {
      if (!$ticket instanceof TicketType) {
        continue;
      }

      $label = $ticket->label();
      $kind = strtoupper($ticket->getTicketKind());
      $price_obj = $ticket->toPriceValue();
      $price = $price_obj ? $price_obj->getCurrencyCode() . ' ' . $price_obj->getNumber() : '—';
      $capacity = $ticket->get('capacity')->isEmpty() ? 0 : (int) $ticket->get('capacity')->value;
      $status = $ticket->isPublished() ? $this->t('Active') : $this->t('Inactive');

      $rows[] = [
        'data' => [
          ['data' => ['#markup' => $label]],
          ['data' => ['#markup' => $kind]],
          ['data' => ['#markup' => $price]],
          ['data' => ['#markup' => (string) $capacity]],
          ['data' => ['#markup' => $status]],
          [
            'data' => [
              'delete' => [
                '#type' => 'submit',
                '#value' => $this->t('Detach'),
                '#submit' => ['::detachTicket'],
                '#name' => 'detach_ticket_' . $ticket->id(),
                '#ticket_id' => $ticket->id(),
                '#attributes' => ['class' => ['button', 'button--small', 'button--danger']],
              ],
            ],
          ],
        ],
      ];
    }

    $form['tickets_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No ticket types configured. Use the buttons below to add tickets.'),
    ];

    $form['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-actions']],
      'add_paid' => [
        '#type' => 'submit',
        '#value' => $this->t('Add paid ticket'),
        '#submit' => ['::addPaidTicket'],
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'add_free' => [
        '#type' => 'submit',
        '#value' => $this->t('Add free ticket'),
        '#submit' => ['::addFreeTicket'],
        '#attributes' => ['class' => ['button']],
      ],
      'save' => [
        '#type' => 'submit',
        '#value' => $this->t('Save tickets'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    $total_capacity = 0;
    foreach ($ticket_types as $ticket) {
      if ($ticket instanceof TicketType && !$ticket->get('capacity')->isEmpty()) {
        $total_capacity += (int) $ticket->get('capacity')->value;
      }
    }

    $form['total_capacity'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-total-capacity']],
      '#markup' => '<p><strong>' . $this->t('Total configured ticket capacity: @count', ['@count' => $total_capacity]) . '</strong></p>',
    ];

    return $form;
  }

  /**
   * Adds a paid starter ticket.
   */
  public function addPaidTicket(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event') ?? $form['#event'] ?? NULL;
    if (!$event || !$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('Event not found or access denied.'));
      return;
    }

    $currency = $this->ticketTypeManager->getDefaultCurrencyCodeForEvent($event);
    $storage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $ticket = $storage->create([
      'title' => 'General admission',
      'ticket_kind' => 'paid',
      'vendor_id' => ['target_id' => $this->currentUserProxy->id()],
      'event' => ['target_id' => $event->id()],
      'is_reusable' => FALSE,
      'capacity' => 100,
      'price' => ['number' => '50.00', 'currency_code' => $currency],
      'status' => 1,
    ]);
    $ticket->save();

    $refs = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $refs = $event->get('field_ticket_types')->getValue();
    }
    $refs[] = ['target_id' => $ticket->id()];
    $event->set('field_ticket_types', $refs);
    $event->save();

    $this->messenger()->addStatus($this->t('Paid ticket type added.'));
    $form_state->setRebuild();
  }

  /**
   * Adds a free (zero-price) paid-kind ticket.
   */
  public function addFreeTicket(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event') ?? $form['#event'] ?? NULL;
    if (!$event || !$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('Event not found or access denied.'));
      return;
    }

    $currency = $this->ticketTypeManager->getDefaultCurrencyCodeForEvent($event);
    $storage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $ticket = $storage->create([
      'title' => 'Complimentary',
      'ticket_kind' => 'paid',
      'vendor_id' => ['target_id' => $this->currentUserProxy->id()],
      'event' => ['target_id' => $event->id()],
      'is_reusable' => FALSE,
      'capacity' => 100,
      'price' => ['number' => '0.00', 'currency_code' => $currency],
      'status' => 1,
    ]);
    $ticket->save();

    $refs = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $refs = $event->get('field_ticket_types')->getValue();
    }
    $refs[] = ['target_id' => $ticket->id()];
    $event->set('field_ticket_types', $refs);
    $event->save();

    $this->messenger()->addStatus($this->t('Free ticket type added.'));
    $form_state->setRebuild();
  }

  /**
   * Detaches a ticket from the event without deleting the ticket entity.
   */
  public function detachTicket(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event') ?? $form['#event'] ?? NULL;
    if (!$event || !$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('Event not found or access denied.'));
      return;
    }

    $triggering_element = $form_state->getTriggeringElement();
    $ticket_id = $triggering_element['#ticket_id'] ?? NULL;

    if (!$ticket_id) {
      return;
    }

    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $ticket_types = $event->get('field_ticket_types')->getValue();
      $ticket_types = array_filter($ticket_types, static function ($item) use ($ticket_id) {
        return (int) ($item['target_id'] ?? 0) !== (int) $ticket_id;
      });
      $event->set('field_ticket_types', array_values($ticket_types));
      $event->save();
    }

    $this->messenger()->addStatus($this->t('Ticket detached from this event.'));
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event') ?? $form['#event'] ?? NULL;
    if (!$event || !$this->canManageEvent($event)) {
      return;
    }

    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    if (in_array($event_type, ['paid', 'both'], TRUE)) {
      $this->ticketTypeManager->syncTicketTypesToVariations($event);
    }

    $this->messenger()->addStatus($this->t('Ticket types saved.'));
  }

  /**
   * Whether the active user may change tickets on this event node.
   */
  private function canManageEvent(NodeInterface $event): bool {
    $current_user = $this->currentUserProxy;
    if ($current_user->hasPermission('administer nodes')) {
      return TRUE;
    }
    return (int) $event->getOwnerId() === (int) $current_user->id();
  }

}
