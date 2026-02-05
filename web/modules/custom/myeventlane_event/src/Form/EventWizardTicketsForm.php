<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard step: Tickets (wizard_step_4).
 */
final class EventWizardTicketsForm extends EventWizardBaseForm {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Ticket type manager (creates/links ticket product and variations).
   */
  protected TicketTypeManager $ticketTypeManager;

  /**
   * Constructs the form.
   */
  public function __construct(
    $entity_type_manager,
    $domain_detector,
    $current_user,
    RendererInterface $renderer,
    LoggerInterface $logger,
    TicketTypeManager $ticket_type_manager,
  ) {
    parent::__construct($entity_type_manager, $domain_detector, $current_user, $renderer);
    $this->logger = $logger;
    $this->ticketTypeManager = $ticket_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('renderer'),
      $container->get('logger.factory')->get('myeventlane_event'),
      $container->get('myeventlane_event.ticket_type_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'event_wizard_tickets_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $event = $this->getEvent();

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_4');
    $form_display->buildForm($event, $form, $form_state);

    $this->applyTicketTypeStates($form);

    $this->addCapacityWarning($form, $event);

    $form['#title'] = $this->t('Create event: Tickets');
    $form['#event'] = $event;
    $form['#step_id'] = 'tickets';

    $steps = $this->buildStepper($event, 'tickets');
    $form['#steps'] = $steps;

    $next_step = $this->getNextStep('tickets');
    $submit_label = $next_step
      ? $this->t('Continue to @step →', ['@step' => $next_step['label']])
      : $this->t('Continue →');

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $submit_label,
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
      '#prefix' => '<div class="mel-wizard-step-card__actions">',
      '#suffix' => '</div>',
    ];

    $form['#prefix'] = $this->buildWizardPrefix($steps, 'tickets', (string) $form['#title']);
    $form['#suffix'] = $this->buildWizardSuffix();

    return $form;
  }

  /**
   * Applies Form API #states for RSVP vs Paid vs Both vs External.
   *
   * RSVP: capacity, waitlist, attendee questions. Hide paid/external.
   * Paid: capacity, ticket types, product, collect_per_ticket. Hide RSVP/external.
   * Both: RSVP + paid fields. Hide external.
   * External: external_url only. Hide all others.
   */
  private function applyTicketTypeStates(array &$form): void {
    // Event type widget name can vary depending on widget wrapping and context
    // (e.g. field_event_type[0][value] vs field_event_type vs nested wrappers).
    // Use a resilient selector so #states works consistently.
    $sel = ':input[name="field_event_type[0][value]"], :input[name="field_event_type"], :input[name*="field_event_type"][name*="[value]"]';

    $external_only = [$sel => ['value' => 'external']];
    $rsvp_or_both = [
      'or' => [
        [$sel => ['value' => 'rsvp']],
        [$sel => ['value' => 'both']],
      ],
    ];
    $paid_or_both = [
      'or' => [
        [$sel => ['value' => 'paid']],
        [$sel => ['value' => 'both']],
      ],
    ];
    $rsvp_or_paid_or_both = [
      'or' => [
        [$sel => ['value' => 'rsvp']],
        [$sel => ['value' => 'paid']],
        [$sel => ['value' => 'both']],
      ],
    ];

    $fields = [
      'field_capacity' => $rsvp_or_paid_or_both,
      'field_waitlist_capacity' => $rsvp_or_both,
      'field_attendee_questions' => $rsvp_or_both,
      'field_ticket_types' => $paid_or_both,
      'field_product_target' => $paid_or_both,
      'field_collect_per_ticket' => $paid_or_both,
      'field_external_url' => $external_only,
    ];

    foreach ($fields as $field_name => $visible) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#states'] = ['visible' => $visible];
      }
    }
  }

  /**
   * Adds capacity summary and non-blocking warning when allocation exceeds capacity.
   */
  private function addCapacityWarning(array &$form, NodeInterface $event): void {
    $eventType = $event->get('field_event_type')->value ?? '';
    if (!in_array($eventType, ['paid', 'both'], TRUE)) {
      return;
    }

    $capacity = 0;
    if ($event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      $capacity = (int) $event->get('field_capacity')->value;
    }

    $allocated = 0;
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      foreach ($event->get('field_ticket_types')->referencedEntities() as $paragraph) {
        if ($paragraph->hasField('field_ticket_capacity') && !$paragraph->get('field_ticket_capacity')->isEmpty()) {
          $allocated += (int) $paragraph->get('field_ticket_capacity')->value;
        }
      }
    }

    if ($capacity > 0 || $allocated > 0) {
      $form['capacity_summary'] = [
        '#type' => 'container',
        '#weight' => -10,
        '#attributes' => ['class' => ['mel-wizard-capacity-summary']],
      ];
      $form['capacity_summary']['text'] = [
        '#markup' => $this->t('Event capacity: @capacity. Allocated ticket quantities: @allocated.', [
          '@capacity' => $capacity > 0 ? $capacity : $this->t('unlimited'),
          '@allocated' => $allocated,
        ]),
      ];
    }

    if ($capacity > 0 && $allocated > $capacity) {
      $form['capacity_warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-alert', 'mel-alert--warning']],
        '#weight' => -5,
        'message' => [
          '#markup' => '<p>' . $this->t('Total ticket quantities exceed event capacity.') . '</p>',
        ],
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // So validation sees values: widgets may submit under *_wrapper.
    $form_display = EntityFormDisplay::collectRenderDisplay($this->getEvent(), 'wizard_step_4');
    $this->normalizeFormStateForExtraction($form_display, $form_state);

    $event = $this->getEvent();

    if ($event->hasField('field_event_type')) {
      $event_type = $form_state->getValue('field_event_type');
      $value = is_array($event_type)
        ? ($event_type[0]['value'] ?? $event_type['value'] ?? reset($event_type))
        : $event_type;
      if (empty($value)) {
        $form_state->setErrorByName('field_event_type', $this->t('Event type is required.'));
        return;
      }

      // Require vendor store for ticket/RSVP creation. Block if event has no
      // field_event_store and no vendor store via field_event_vendor.
      if (in_array($value, ['paid', 'both', 'rsvp'], TRUE)) {
        if (!$this->ticketTypeManager->hasVendorStore($event)) {
          $this->logger->warning(
            'Event @nid: tickets step blocked — no vendor store (field_event_store empty, field_event_vendor/store not set)',
            ['@nid' => $event->id()]
          );
          $form_state->setErrorByName(
            'field_event_type',
            $this->t('This event does not have a valid vendor store. Please complete the organiser setup (Basics step) and ensure your vendor account has a store assigned before creating tickets.')
          );
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent();

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_4');
    $field_names = array_keys($form_display->getComponents());

    $this->copyFormValuesToEvent($event, $form, $form_state, 'wizard_step_4');

    // Ensure paid/both events always have a linked ticket product.
    // This fixes vendor console tabs (Tickets/Orders) and ensures the
    // Ticket Product field is prefilled on subsequent visits.
    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    if (in_array($event_type, ['paid', 'both'], TRUE)) {
      $this->ticketTypeManager->syncTicketTypesToVariations($event);
    }

    $this->logger->notice('Event wizard tickets saved: event_id=@id, fields=@fields', [
      '@id' => $event->id(),
      '@fields' => implode(', ', $field_names),
    ]);

    $this->redirectToNextStep($form_state, 'tickets');
  }

}
