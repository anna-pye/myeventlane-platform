<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\myeventlane_event\Service\MelPlatformSupportWizardFormHelper;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\myeventlane_vendor\Ticketing\EventTicketsBuilder;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard step: Tickets (wizard_step_4).
 *
 * Ticket tier UI is injected via EventTicketsBuilder (single Form API tree).
 */
final class EventWizardTicketsForm extends EventWizardBaseForm {

  protected LoggerInterface $logger;

  protected TicketTypeManager $ticketTypeManager;

  protected EventTicketsBuilder $ticketBuilder;

  /**
   * Null when the form was unserialized from cache; use {@see getMelPlatformSupportWizardForm()}.
   *
   * @var \Drupal\myeventlane_event\Service\MelPlatformSupportWizardFormHelper|null
   */
  protected ?MelPlatformSupportWizardFormHelper $melPlatformSupportWizardForm = NULL;

  public function __construct(
    $entity_type_manager,
    $domain_detector,
    $current_user,
    RendererInterface $renderer,
    LoggerInterface $logger,
    TicketTypeManager $ticket_type_manager,
    EventTicketsBuilder $ticket_builder,
    MelPlatformSupportWizardFormHelper $mel_platform_support_wizard_form,
  ) {
    parent::__construct($entity_type_manager, $domain_detector, $current_user, $renderer);
    $this->logger = $logger;
    $this->ticketTypeManager = $ticket_type_manager;
    $this->ticketBuilder = $ticket_builder;
    $this->melPlatformSupportWizardForm = $mel_platform_support_wizard_form;
  }

  /**
   * Mel platform helper (lazy for AJAX/cache unserialize safety).
   */
  protected function getMelPlatformSupportWizardForm(): MelPlatformSupportWizardFormHelper {
    if ($this->melPlatformSupportWizardForm === NULL) {
      $this->melPlatformSupportWizardForm = \Drupal::getContainer()->get('myeventlane_event.mel_platform_support_wizard_form');
    }
    return $this->melPlatformSupportWizardForm;
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
      $container->get('myeventlane_vendor.ticket_builder'),
      $container->get('myeventlane_event.mel_platform_support_wizard_form'),
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
    $form = parent::buildForm($form, $form_state);
    $event = $this->getEvent();

    $form_state->set('event', $event);

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_4');
    $form_display->buildForm($event, $form, $form_state);
    unset($form['field_ticket_types']);

    $form['mel_ticket_wizard'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-wizard']],
      '#weight' => 12,
    ];

    $form_state->set('mel_ticket_builder_value_prefix', ['mel_ticket_wizard']);
    $this->ticketBuilder->build($form['mel_ticket_wizard'], $form_state, $event);

    $this->applyTicketTypeStates($form);
    $this->addCapacityWarning($form, $event);
    $this->getMelPlatformSupportWizardForm()->buildSection($form, $form_state, $event);

    $form['#title'] = $this->t('Create event: Tickets');
    $form['#event'] = $event;
    $form['#step_id'] = 'tickets';

    $form['#attached']['library'][] = 'myeventlane_vendor/ticket_cards';
    $form['#attached']['library'][] = 'core/drupal.ajax';

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
   * Prefer event from form state (AJAX rebuilds); fall back to route entity.
   */
  private function getEventOrInjectedEvent(FormStateInterface $form_state): NodeInterface {
    $event = $form_state->get('event');
    return $event instanceof NodeInterface ? $event : $this->getEvent();
  }

  public function handleAction(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEventOrInjectedEvent($form_state);
    $this->ticketBuilder->handleAction($form, $form_state, $event);
    $nid = (int) $event->id();
    if ($nid > 0) {
      $fresh = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($fresh instanceof NodeInterface) {
        $form_state->set('event', $fresh);
      }
    }
  }

  public function ajaxRebuildTicketBuilder(array &$form, FormStateInterface $form_state): array {
    return $form['mel_ticket_wizard']['builder_shell'] ?? [];
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
            'Event @nid: tickets step blocked — no vendor store',
            ['@nid' => $event->id()]
          );
          $form_state->setErrorByName(
            'field_event_type',
            $this->t('This event does not have a valid vendor store. Please complete the organiser setup (Basics step) and ensure your vendor account has a store assigned before creating tickets.')
          );
        }
      }

      $this->getMelPlatformSupportWizardForm()->validate($form_state, $event, (string) $value);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Saves event fields only. Ticket add/remove is handled by handleAction.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent();

    $saved_ticket_types = $event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()
      ? $event->get('field_ticket_types')->getValue()
      : [];

    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_4');
    $field_names = array_diff(array_keys($form_display->getComponents()), ['field_ticket_types']);

    $this->copyFormValuesToEvent($event, $form, $form_state, 'wizard_step_4');

    $this->getMelPlatformSupportWizardForm()->apply($event, $form_state);

    $event->set('field_ticket_types', $saved_ticket_types);
    EventNodeRevisionSave::prepare($event, 'Event wizard: tickets step (field_ticket_types).');
    $event->save();

    $this->logger->notice('Event wizard tickets step saved: event_id=@id, fields=@fields', [
      '@id' => $event->id(),
      '@fields' => implode(', ', $field_names),
    ]);

    $reloaded = $this->entityTypeManager->getStorage('node')->load($event->id());
    if ($reloaded instanceof NodeInterface) {
      if (!$this->ticketTypeManager->syncTicketTypesToVariations($reloaded)) {
        $this->logger->notice('Ticket variation sync returned FALSE after tickets step for event @nid.', [
          '@nid' => $event->id(),
        ]);
      }
    }

    $event_type = $event->get('field_event_type')->value ?? '';
    if (in_array($event_type, ['paid', 'both'], TRUE)) {
      if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
        $this->messenger()->addWarning($this->t('Add at least one ticket tier (for example a paid tier or RSVP) before you publish. Use the buttons above to create tickets.'));
      }
    }

    $this->redirectToNextStep($form_state, 'tickets');
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
