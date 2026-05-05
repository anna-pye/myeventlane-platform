<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\myeventlane_event\Service\MelPlatformSupportWizardFormHelper;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard step: Tickets (wizard_step_4).
 *
 * Ticket settings step. Ticket rows are managed on the canonical ticket screen.
 */
final class EventWizardTicketsForm extends EventWizardBaseForm {

  protected LoggerInterface $logger;

  protected TicketTypeManager $ticketTypeManager;

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
    MelPlatformSupportWizardFormHelper $mel_platform_support_wizard_form,
  ) {
    parent::__construct($entity_type_manager, $domain_detector, $current_user, $renderer);
    $this->logger = $logger;
    $this->ticketTypeManager = $ticket_type_manager;
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

    $this->applyTicketTypeStates($form);
    $this->getMelPlatformSupportWizardForm()->buildSection($form, $form_state, $event);

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
   * Saves event fields. Ticket rows are edited on the canonical ticket route.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent();

    $submitted_event_type = $form_state->getValue('field_event_type');
    $event_type = is_array($submitted_event_type)
      ? (string) ($submitted_event_type[0]['value'] ?? $submitted_event_type['value'] ?? reset($submitted_event_type))
      : (string) ($submitted_event_type ?: ($event->get('field_event_type')->value ?? ''));
    $form_display = EntityFormDisplay::collectRenderDisplay($event, 'wizard_step_4');
    $field_names = array_diff(array_keys($form_display->getComponents()), ['field_ticket_types']);

    $this->copyFormValuesToEvent($event, $form, $form_state, 'wizard_step_4');

    $this->getMelPlatformSupportWizardForm()->apply($event, $form_state);

    EventNodeRevisionSave::prepare($event, 'Event wizard: tickets step.');
    $event->save();

    $this->logger->notice('Event wizard tickets step saved: event_id=@id, fields=@fields', [
      '@id' => $event->id(),
      '@fields' => implode(', ', $field_names),
    ]);

    if (in_array($event_type, ['paid', 'both'], TRUE)) {
      if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
        $this->messenger()->addWarning($this->t('Link a ticket product, then use the Tickets screen to add ticket types before publishing.'));
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

  }

}
