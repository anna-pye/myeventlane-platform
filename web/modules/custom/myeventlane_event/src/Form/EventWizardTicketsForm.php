<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\mel_ticket\Entity\TicketType;
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
    unset($form['field_ticket_types']);

    if ($form_state->get('mel_attached_ticket_ids') === NULL) {
      $ids = [];
      if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
        foreach ($event->get('field_ticket_types')->getValue() as $row) {
          if (!empty($row['target_id'])) {
            $ids[] = (int) $row['target_id'];
          }
        }
      }
      $form_state->set('mel_attached_ticket_ids', array_values(array_unique($ids)));
    }

    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';

    $form['mel_ticket_wizard'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-wizard', 'mel-cards']],
      '#weight' => 12,
    ];

    $form['mel_ticket_wizard']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Tickets for this event'),
      '#attributes' => ['class' => ['mel-ticket-wizard__title']],
    ];

    $allowed_kinds = $this->allowedTicketKindsForEventType($event_type);
    $form['mel_ticket_wizard']['attached'] = $this->buildAttachedTicketsTable(
      $form_state,
      $allowed_kinds !== [],
      $event_type
    );

    if ($allowed_kinds !== []) {
      $form['mel_ticket_wizard']['reuse'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Reuse an existing ticket'),
        '#attributes' => ['class' => ['mel-fieldset', 'mel-ticket-wizard__reuse']],
        'help' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Attach a reusable ticket you have already created. Paid tickets cannot be reusable.'),
          '#attributes' => ['class' => ['description']],
        ],
        'ticket' => [
          '#type' => 'entity_autocomplete',
          '#title' => $this->t('Reusable ticket'),
          '#target_type' => 'mel_ticket_type',
          '#selection_handler' => 'mel_ticket:reusable',
          '#selection_settings' => [],
        ],
        'attach' => [
          '#type' => 'submit',
          '#value' => $this->t('Attach ticket'),
          '#submit' => ['::attachReusableTicketSubmit'],
          '#limit_validation_errors' => [['mel_ticket_wizard', 'reuse']],
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
        ],
      ];

      $currency = $this->ticketTypeManager->getDefaultCurrencyCodeForEvent($event);
      $form['mel_ticket_wizard']['create'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Create a new ticket'),
        '#attributes' => ['class' => ['mel-fieldset', 'mel-ticket-wizard__create']],
        'kind' => [
          '#type' => 'select',
          '#title' => $this->t('Ticket type'),
          '#options' => $allowed_kinds,
          '#required' => FALSE,
          '#empty_option' => $this->t('- Select -'),
        ],
        'title' => [
          '#type' => 'textfield',
          '#title' => $this->t('Title'),
          '#maxlength' => 255,
        ],
        'price_amount' => [
          '#type' => 'number',
          '#title' => $this->t('Price'),
          '#min' => 0,
          '#step' => 0.01,
          '#states' => [
            'visible' => [
              ':input[name="mel_ticket_wizard[create][kind]"]' => ['value' => 'paid'],
            ],
          ],
        ],
        'price_currency' => [
          '#type' => 'textfield',
          '#title' => $this->t('Currency'),
          '#size' => 4,
          '#maxlength' => 3,
          '#default_value' => $currency,
          '#states' => [
            'visible' => [
              ':input[name="mel_ticket_wizard[create][kind]"]' => ['value' => 'paid'],
            ],
          ],
        ],
        'capacity' => [
          '#type' => 'number',
          '#title' => $this->t('Capacity'),
          '#min' => 1,
          '#states' => [
            'visible' => [
              'or' => [
                [':input[name="mel_ticket_wizard[create][kind]"]' => ['value' => 'rsvp']],
                [':input[name="mel_ticket_wizard[create][kind]"]' => ['value' => 'paid']],
              ],
            ],
          ],
        ],
        'rsvp_limit' => [
          '#type' => 'number',
          '#title' => $this->t('RSVP limit per booking'),
          '#min' => 0,
          '#states' => [
            'visible' => [
              ':input[name="mel_ticket_wizard[create][kind]"]' => ['value' => 'rsvp'],
            ],
          ],
        ],
        'external_uri' => [
          '#type' => 'url',
          '#title' => $this->t('External ticket URL'),
          '#states' => [
            'visible' => [
              ':input[name="mel_ticket_wizard[create][kind]"]' => ['value' => 'external'],
            ],
          ],
        ],
        'sale_start' => [
          '#type' => 'datetime',
          '#title' => $this->t('Sale start'),
          '#date_increment' => 15,
        ],
        'sale_end' => [
          '#type' => 'datetime',
          '#title' => $this->t('Sale end'),
          '#date_increment' => 15,
        ],
        'is_reusable' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Make this ticket reusable on other events'),
          '#states' => [
            'visible' => [
              [':input[name="mel_ticket_wizard[create][kind]"]' => ['value' => 'rsvp']],
              'or',
              [':input[name="mel_ticket_wizard[create][kind]"]' => ['value' => 'external']],
            ],
          ],
        ],
        'add' => [
          '#type' => 'submit',
          '#value' => $this->t('Add ticket'),
          '#submit' => ['::addCreatedTicketSubmit'],
          '#limit_validation_errors' => [['mel_ticket_wizard', 'create']],
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'mel-ticket-wizard__add']],
        ],
      ];
    }

    $this->applyTicketTypeStates($form);
    $this->addCapacityWarning($form, $event);

    $form['#title'] = $this->t('Create event: Tickets');
    $form['#event'] = $event;
    $form['#step_id'] = 'tickets';
    $form['#attached']['library'][] = 'mel_ticket/wizard';

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
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

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

      if (in_array($value, ['paid', 'both'], TRUE)) {
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

    $ids = $form_state->get('mel_attached_ticket_ids') ?? [];
    $storage = $this->entityTypeManager->getStorage('mel_ticket_type');
    foreach ($ids as $ticket_id) {
      $ticket = $storage->load($ticket_id);
      if (!$ticket instanceof TicketType) {
        $form_state->setErrorByName('', $this->t('Invalid ticket reference.'));
        continue;
      }
      if (!$this->currentUser->hasPermission('assign mel_ticket_type entities')) {
        $form_state->setErrorByName('', $this->t('You cannot assign ticket types.'));
        return;
      }
      if ((int) $ticket->get('vendor_id')->target_id !== (int) $this->currentUser->id()
        && !$this->currentUser->hasPermission('administer mel_ticket_type entities')) {
        $form_state->setErrorByName('', $this->t('You can only assign ticket types you own.'));
        return;
      }
    }

    $create = $form_state->getValue(['mel_ticket_wizard', 'create']) ?? [];
    if (!empty($create) && !empty(array_filter($create))) {
      $has_title = !empty($create['title']);
      $has_kind = !empty($create['kind']);
      if ($has_title xor $has_kind) {
        $form_state->setErrorByName('mel_ticket_wizard][create', $this->t('Complete ticket type and title, or clear the create form, or use the “Add ticket” button.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent();
    $trigger = $form_state->getTriggeringElement();
    if (!empty($trigger['#submit']) && in_array('::attachReusableTicketSubmit', $trigger['#submit'], TRUE)) {
      return;
    }
    if (!empty($trigger['#submit']) && in_array('::addCreatedTicketSubmit', $trigger['#submit'], TRUE)) {
      return;
    }

    $ids = $form_state->get('mel_attached_ticket_ids') ?? [];
    $detach = $form_state->getValue('mel_ticket_detach') ?? [];
    if (is_array($detach)) {
      foreach (array_keys(array_filter($detach)) as $remove_id) {
        $ids = array_values(array_diff($ids, [(int) $remove_id]));
      }
    }
    $event->set('field_ticket_types', array_map(static fn (int $id) => ['target_id' => $id], array_unique($ids)));

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_4');
    $field_names = array_keys($form_display->getComponents());

    $this->copyFormValuesToEvent($event, $form, $form_state, 'wizard_step_4');

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

  /**
   * Appends a reusable ticket chosen from autocomplete.
   */
  public function attachReusableTicketSubmit(array &$form, FormStateInterface $form_state): void {
    $target = $form_state->getValue(['mel_ticket_wizard', 'reuse', 'ticket']);
    if (empty($target)) {
      $this->messenger()->addWarning($this->t('Choose a ticket to attach.'));
      $form_state->setRebuild();
      return;
    }
    if (!preg_match('/\((\d+)\)\s*$/', (string) $target, $m)) {
      $this->messenger()->addError($this->t('Invalid ticket selection.'));
      $form_state->setRebuild();
      return;
    }
    $id = (int) $m[1];
    $ticket = $this->entityTypeManager->getStorage('mel_ticket_type')->load($id);
    if (!$ticket instanceof TicketType || !$ticket->isReusable()) {
      $this->messenger()->addError($this->t('That ticket is not reusable.'));
      $form_state->setRebuild();
      return;
    }
    if ((int) $ticket->get('vendor_id')->target_id !== (int) $this->currentUser->id()) {
      $this->messenger()->addError($this->t('You can only reuse your own tickets.'));
      $form_state->setRebuild();
      return;
    }
    $ids = $form_state->get('mel_attached_ticket_ids') ?? [];
    if (!in_array($id, $ids, TRUE)) {
      $ids[] = $id;
      $form_state->set('mel_attached_ticket_ids', $ids);
    }
    $form_state->setValue(['mel_ticket_wizard', 'reuse', 'ticket'], NULL);
    $this->messenger()->addStatus($this->t('Ticket attached for this step. Continue to save the event.'));
    $form_state->setRebuild();
  }

  /**
   * Creates a new ticket entity from the create fieldset.
   */
  public function addCreatedTicketSubmit(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent();
    $create = $form_state->getValue(['mel_ticket_wizard', 'create']) ?? [];
    $kind = $create['kind'] ?? '';
    $title = trim((string) ($create['title'] ?? ''));
    if ($kind === '' || $title === '') {
      $this->messenger()->addWarning($this->t('Select a ticket type and enter a title.'));
      $form_state->setRebuild();
      return;
    }

    $event_type = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    $allowed = array_keys($this->allowedTicketKindsForEventType($event_type));
    if (!in_array($kind, $allowed, TRUE)) {
      $this->messenger()->addError($this->t('This ticket type is not allowed for the current event mode.'));
      $form_state->setRebuild();
      return;
    }

    if (!$this->currentUser->hasPermission('create mel_ticket_type entities')) {
      $this->messenger()->addError($this->t('You cannot create ticket types.'));
      $form_state->setRebuild();
      return;
    }

    $values = [
      'title' => $title,
      'ticket_kind' => $kind,
      'vendor_id' => ['target_id' => $this->currentUser->id()],
      'status' => 1,
    ];

    $is_reusable = !empty($create['is_reusable']);
    if ($kind === 'paid') {
      $is_reusable = FALSE;
    }
    $values['is_reusable'] = $is_reusable;
    if (!$is_reusable) {
      $values['event'] = ['target_id' => $event->id()];
    }

    if ($kind === 'paid') {
      $amount = (string) ($create['price_amount'] ?? '');
      $currency = strtoupper(trim((string) ($create['price_currency'] ?? '')));
      if ($amount === '' || $currency === '') {
        $this->messenger()->addError($this->t('Paid tickets require price and currency.'));
        $form_state->setRebuild();
        return;
      }
      $values['price'] = ['number' => $amount, 'currency_code' => $currency];
      $values['capacity'] = (int) ($create['capacity'] ?? 0);
    }
    elseif ($kind === 'rsvp') {
      $values['capacity'] = (int) ($create['capacity'] ?? 0);
      if (!empty($create['rsvp_limit'])) {
        $values['rsvp_limit'] = (int) $create['rsvp_limit'];
      }
    }
    elseif ($kind === 'external') {
      $uri = trim((string) ($create['external_uri'] ?? ''));
      if ($uri === '' || !UrlHelper::isValid($uri, TRUE)) {
        $this->messenger()->addError($this->t('Enter a valid external https URL.'));
        $form_state->setRebuild();
        return;
      }
      $values['external_url'] = ['uri' => $uri, 'title' => ''];
    }

    if (($kind === 'rsvp' || $kind === 'paid') && (int) ($values['capacity'] ?? 0) < 1) {
      $this->messenger()->addError($this->t('Capacity must be at least 1 for RSVP and paid tickets.'));
      $form_state->setRebuild();
      return;
    }

    foreach (['sale_start', 'sale_end'] as $key) {
      if (!empty($create[$key]) && $create[$key] instanceof DrupalDateTime) {
        $values[$key] = $create[$key]->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
      }
    }

    try {
      /** @var \Drupal\mel_ticket\Entity\TicketType $ticket */
      $ticket = $this->entityTypeManager->getStorage('mel_ticket_type')->create($values);
      $ticket->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Ticket create failed: @msg', ['@msg' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Could not create ticket: @msg', ['@msg' => $e->getMessage()]));
      $form_state->setRebuild();
      return;
    }

    $ids = $form_state->get('mel_attached_ticket_ids') ?? [];
    $ids[] = (int) $ticket->id();
    $form_state->set('mel_attached_ticket_ids', array_values(array_unique($ids)));

    $form_state->setValue(['mel_ticket_wizard', 'create'], NULL);
    $this->messenger()->addStatus($this->t('Ticket added. Continue to save, or add more tickets.'));
    $form_state->setRebuild();
  }

  /**
   * Builds the attachment table for tickets on this step.
   */
  private function buildAttachedTicketsTable(FormStateInterface $form_state, bool $show_ticket_ui, string $event_type): array {
    $ids = $form_state->get('mel_attached_ticket_ids') ?? [];
    $rows = [];
    $storage = $this->entityTypeManager->getStorage('mel_ticket_type');
    foreach ($ids as $tid) {
      $ticket = $storage->load($tid);
      if (!$ticket instanceof TicketType) {
        continue;
      }
      $kind = $ticket->getTicketKind();
      $price = $ticket->toPriceValue();
      $price_label = $price
        ? sprintf('%s %s', $price->getCurrencyCode(), $price->getNumber())
        : $this->t('Free / N/A');
      $rows[] = [
        'data' => [
          ['data' => ['#markup' => $ticket->label()]],
          ['data' => ['#markup' => strtoupper($kind)]],
          ['data' => ['#markup' => $price_label]],
          [
            'data' => [
              'detach' => [
                '#type' => 'checkbox',
                '#title' => $this->t('Remove'),
                '#parents' => ['mel_ticket_detach', (string) $ticket->id()],
              ],
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Tickets on this event'),
      '#attributes' => ['class' => ['mel-ticket-wizard__attached']],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Title'),
          $this->t('Type'),
          $this->t('Price'),
          $this->t('Remove'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No tickets yet. Create one or attach a reusable ticket.'),
      ],
      '#access' => $show_ticket_ui && !in_array($event_type, ['external'], TRUE),
    ];
  }

  /**
   * Maps event type to creatable ticket kinds.
   *
   * @return array<string, string>
   *   Options for a select list.
   */
  private function allowedTicketKindsForEventType(string $event_type): array {
    return match ($event_type) {
      'external' => ['external' => $this->t('External')],
      'rsvp' => ['rsvp' => $this->t('RSVP')],
      'paid' => ['paid' => $this->t('Paid')],
      'both' => [
        'rsvp' => $this->t('RSVP'),
        'paid' => $this->t('Paid'),
      ],
      default => [],
    };
  }

  /**
   * Applies Form API #states for RSVP vs Paid vs Both vs External.
   */
  private function applyTicketTypeStates(array &$form): void {
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
    $not_external = [
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
      'field_product_target' => $paid_or_both,
      'field_collect_per_ticket' => $paid_or_both,
      'field_external_url' => $external_only,
    ];

    foreach ($fields as $field_name => $visible) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#states'] = ['visible' => $visible];
      }
    }

    if (isset($form['mel_ticket_wizard'])) {
      $form['mel_ticket_wizard']['#states'] = ['visible' => $not_external];
    }
  }

  /**
   * Adds capacity summary and non-blocking warning when allocation exceeds capacity.
   */
  private function addCapacityWarning(array &$form, NodeInterface $event): void {
    $eventType = $event->get('field_event_type')->value ?? '';
    if (!in_array($eventType, ['paid', 'both', 'rsvp'], TRUE)) {
      return;
    }

    $capacity = 0;
    if ($event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()) {
      $capacity = (int) $event->get('field_capacity')->value;
    }

    $allocated = 0;
    foreach ($this->ticketTypeManager->loadEventTicketTypes($event) as $ticket) {
      if (!$ticket->get('capacity')->isEmpty()) {
        $allocated += (int) $ticket->get('capacity')->value;
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

}
