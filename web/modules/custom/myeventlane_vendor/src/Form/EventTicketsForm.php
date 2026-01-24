<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing ticket types in a Humanitix-style table.
 */
final class EventTicketsForm extends FormBase {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected AccountProxyInterface $currentUserProxy;
  protected FormBuilderInterface $formBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUserProxy = $container->get('current_user');
    $instance->formBuilder = $container->get('form_builder');
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

    // Store event in form state for rebuilds.
    $form_state->set('event', $event);
    $form['#event'] = $event;
    $form['#tree'] = TRUE;

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['description']],
      '#value' => $this->t('Manage ticket types for your event. Each ticket type creates a separate purchase option.'),
    ];

    // Load existing ticket types.
    $ticket_types = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $ticket_types = $event->get('field_ticket_types')->referencedEntities();
    }

    // Build table header. Use sequential keys for theme_table.
    $header = [
      $this->t('Name'),
      $this->t('Price'),
      $this->t('Capacity'),
      $this->t('Status'),
      $this->t('Actions'),
    ];

    // Build table rows. Use 'data' structure per theme_table preprocess so
    // row attributes are not confused with cell content (avoids array-to-string
    // in AttributeArray when column keys are passed as attribute names).
    $rows = [];
    foreach ($ticket_types as $paragraph) {
      if (!$paragraph instanceof ParagraphInterface) {
        continue;
      }

      $label = $this->getTicketLabel($paragraph);
      $price = $paragraph->get('field_ticket_price')->value ?? '0.00';
      $capacity = $paragraph->get('field_ticket_capacity')->value ?? 0;
      $status = $capacity > 0 ? $this->t('Active') : $this->t('Inactive');

      $rows[] = [
        'data' => [
          ['data' => ['#markup' => $label]],
          ['data' => ['#markup' => '$' . number_format((float) $price, 2)]],
          ['data' => ['#markup' => (string) $capacity]],
          ['data' => ['#markup' => $status]],
          [
            'data' => [
              'delete' => [
                '#type' => 'submit',
                '#value' => $this->t('Delete'),
                '#submit' => ['::deleteTicket'],
                '#name' => 'delete_ticket_' . $paragraph->id(),
                '#paragraph_id' => $paragraph->id(),
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

    // Add ticket buttons.
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

    // Calculate total capacity.
    $total_capacity = 0;
    foreach ($ticket_types as $paragraph) {
      if ($paragraph instanceof ParagraphInterface) {
        $capacity = (int) ($paragraph->get('field_ticket_capacity')->value ?? 0);
        $total_capacity += $capacity;
      }
    }

    $form['total_capacity'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-total-capacity']],
      '#markup' => '<p><strong>' . $this->t('Total event capacity: @count', ['@count' => $total_capacity]) . '</strong></p>',
    ];

    return $form;
  }

  /**
   * Gets the display label for a ticket type paragraph.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The ticket type paragraph.
   *
   * @return string
   *   The label.
   */
  private function getTicketLabel(ParagraphInterface $paragraph): string {
    $mode = $paragraph->get('field_ticket_label_mode')->value ?? 'preset';

    if ($mode === 'custom') {
      return $paragraph->get('field_ticket_label_custom')->value ?? 'Unnamed Ticket';
    }

    return $paragraph->get('field_ticket_label_preset')->value ?? 'General Admission';
  }

  /**
   * Submit handler to add a paid ticket.
   */
  public function addPaidTicket(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event') ?? $form['#event'] ?? NULL;
    if (!$event || !$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('Event not found or access denied.'));
      return;
    }

    $paragraph_storage = $this->getEntityTypeManager()->getStorage('paragraph');
    $paragraph = $paragraph_storage->create([
      'type' => 'ticket_type_config',
      'field_ticket_label_mode' => 'preset',
      'field_ticket_label_preset' => 'general_admission',
      'field_ticket_price' => '50.00',
      'field_ticket_capacity' => 100,
    ]);
    $paragraph->save();

    // Add to event.
    $ticket_types = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $ticket_types = $event->get('field_ticket_types')->getValue();
    }
    $ticket_types[] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
    $event->set('field_ticket_types', $ticket_types);
    $event->save();

    $this->messenger()->addStatus($this->t('Paid ticket type added.'));
    $form_state->setRebuild();
  }

  /**
   * Submit handler to add a free ticket.
   */
  public function addFreeTicket(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event') ?? $form['#event'] ?? NULL;
    if (!$event || !$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('Event not found or access denied.'));
      return;
    }

    $paragraph_storage = $this->getEntityTypeManager()->getStorage('paragraph');
    $paragraph = $paragraph_storage->create([
      'type' => 'ticket_type_config',
      'field_ticket_label_mode' => 'preset',
      'field_ticket_label_preset' => 'general_admission',
      'field_ticket_price' => '0.00',
      'field_ticket_capacity' => 100,
    ]);
    $paragraph->save();

    // Add to event.
    $ticket_types = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $ticket_types = $event->get('field_ticket_types')->getValue();
    }
    $ticket_types[] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
    $event->set('field_ticket_types', $ticket_types);
    $event->save();

    $this->messenger()->addStatus($this->t('Free ticket type added.'));
    $form_state->setRebuild();
  }

  /**
   * Submit handler to delete a ticket type.
   */
  public function deleteTicket(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event') ?? $form['#event'] ?? NULL;
    if (!$event || !$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('Event not found or access denied.'));
      return;
    }

    // Find which button was clicked.
    $triggering_element = $form_state->getTriggeringElement();
    $paragraph_id = $triggering_element['#paragraph_id'] ?? NULL;

    if (!$paragraph_id) {
      return;
    }

    // Load the paragraph.
    $paragraph = $this->getEntityTypeManager()->getStorage('paragraph')->load($paragraph_id);
    if (!$paragraph) {
      return;
    }

    // Remove from event field.
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      $ticket_types = $event->get('field_ticket_types')->getValue();
      $ticket_types = array_filter($ticket_types, function ($item) use ($paragraph_id) {
        return ($item['target_id'] ?? NULL) != $paragraph_id;
      });
      $event->set('field_ticket_types', array_values($ticket_types));
      $event->save();
    }

    // Delete the paragraph entity.
    $paragraph->delete();

    $this->messenger()->addStatus($this->t('Ticket type deleted.'));
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

    // Sync ticket types to Commerce variations.
    if ($this->getEntityTypeManager()->hasDefinition('myeventlane_event.ticket_type_manager') || \Drupal::hasService('myeventlane_event.ticket_type_manager')) {
      $ticket_manager = \Drupal::service('myeventlane_event.ticket_type_manager');
      $ticket_manager->syncTicketTypesToVariations($event);
    }

    $this->messenger()->addStatus($this->t('Ticket types saved.'));
  }

  /**
   * Gets the entity type manager, initializing if needed.
   */
  private function getEntityTypeManager(): EntityTypeManagerInterface {
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
    return $this->entityTypeManager;
  }

  /**
   * Gets the current user proxy, initializing if needed.
   */
  private function getCurrentUserProxy(): AccountProxyInterface {
    if (!isset($this->currentUserProxy)) {
      $this->currentUserProxy = \Drupal::currentUser();
    }
    return $this->currentUserProxy;
  }

  /**
   * Checks access for vendor ownership/admin.
   */
  private function canManageEvent(NodeInterface $event): bool {
    $current_user = $this->getCurrentUserProxy();
    if ($current_user->hasPermission('administer nodes')) {
      return TRUE;
    }
    return (int) $event->getOwnerId() === (int) $current_user->id();
  }

}
