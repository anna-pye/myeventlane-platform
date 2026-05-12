<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioQuestionTemplateManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Operational Event Studio form for checkout question templates.
 */
final class EventCheckoutQuestionsForm extends FormBase {

  protected EntityTypeManagerInterface $studioEntityTypeManager;

  protected AccountProxyInterface $studioCurrentUser;

  protected EventVendorAccessChecker $studioEventVendorAccessChecker;

  protected EventStudioAutosaveService $studioAutosaveService;

  protected EventStudioQuestionTemplateManager $studioQuestionTemplateManager;

  protected LoggerInterface $studioLogger;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUserProxy,
    EventVendorAccessChecker $eventVendorAccessChecker,
    EventStudioAutosaveService $autosaveService,
    EventStudioQuestionTemplateManager $questionTemplateManager,
    LoggerInterface $logger,
  ) {
    $this->studioEntityTypeManager = $entityTypeManager;
    $this->studioCurrentUser = $currentUserProxy;
    $this->studioEventVendorAccessChecker = $eventVendorAccessChecker;
    $this->studioAutosaveService = $autosaveService;
    $this->studioQuestionTemplateManager = $questionTemplateManager;
    $this->studioLogger = $logger;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event_studio.autosave'),
      $container->get('myeventlane_event_studio.question_template_manager'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
    );
  }

  public function getFormId(): string {
    return 'myeventlane_event_studio_checkout_questions_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $event = $this->getRouteEvent($node);
    $this->assertCanManageEvent($event);

    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'mel-event-studio-questions';
    $form['#attributes']['data-mel-event-studio-form'] = '1';
    $form['#attributes']['data-mel-event-studio-section'] = 'questions';

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->id(),
    ];
    $form['nid'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->id(),
    ];
    $form['mel_studio_section'] = [
      '#type' => 'hidden',
      '#value' => 'questions',
    ];
    $form['mel_studio_changed'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->getChangedTime(),
    ];
    $form['mel_studio_revision'] = [
      '#type' => 'hidden',
      '#value' => (string) (int) $event->getRevisionId(),
    ];

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-event-studio-questions__intro']],
      'title' => [
        '#markup' => '<h3 class="mel-es-card__title">' . $this->t('Checkout questions') . '</h3>',
      ],
      'copy' => [
        '#markup' => '<p class="mel-es-card__hint">' . $this->t('Manage governed attendee question templates. Checkout still stores answers on attendee records; this screen never exposes attendee answers.') . '</p>',
      ],
      'per_order_notice' => [
        '#markup' => '<p class="mel-event-studio-questions__notice">' . $this->t('Per-order questions are planned but not checkout-rendered in this slice. Use per-ticket or per-ticket-type questions for active checkout capture.') . '</p>',
      ],
    ];

    $ticket_options = $this->studioQuestionTemplateManager->ticketOptions($event);
    $form['questions_card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-event-studio-questions__manager']],
    ];
    $form['questions_card']['questions'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Label'),
        $this->t('Type'),
        $this->t('Applies To'),
        $this->t('Required'),
        $this->t('Status'),
        $this->t('Actions'),
      ],
      '#empty' => $this->t('No checkout questions yet. Add a question below.'),
      '#attributes' => ['class' => ['mel-event-studio-questions__table']],
    ];

    foreach ($this->studioQuestionTemplateManager->loadRows($event) as $row) {
      $row_id = (int) $row['id'];
      $form['questions_card']['questions'][$row_id] = $this->buildQuestionRow($row, $ticket_options);
    }

    $form['questions_card']['new_question'] = [
      '#type' => 'details',
      '#title' => $this->t('Add question'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['mel-event-studio-questions__add']],
    ] + $this->buildQuestionEditor([
      'id' => 0,
      'weight' => count($this->studioQuestionTemplateManager->loadRows($event)),
      'label' => '',
      'type' => 'textfield',
      'required' => FALSE,
      'status' => EventStudioQuestionTemplateManager::STATUS_ACTIVE,
      'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET,
      'ticket_type_ids' => [],
      'options' => '',
      'machine_name' => '',
    ], $ticket_options, TRUE);

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save checkout questions'),
        '#button_type' => 'primary',
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $form_state->setErrorByName('', $this->t('The event could not be loaded.'));
      return;
    }
    $this->assertCanManageEvent($event);

    $base_changed = (int) ($form_state->getValue('mel_studio_changed') ?? 0);
    $base_revision_id = (int) ($form_state->getValue('mel_studio_revision') ?? 0);
    if ($this->studioAutosaveService->isStaleSubmission($event, $base_changed, $base_revision_id)) {
      $form_state->setErrorByName('', $this->t('This section was updated elsewhere. Refresh to continue editing safely.'));
      return;
    }

    $existing_rows = $this->submittedExistingRows($form_state);
    $new_row = $this->submittedNewRow($form_state);
    foreach ($this->studioQuestionTemplateManager->validateRows($event, $existing_rows, $new_row) as $error) {
      $form_state->setErrorByName('questions', $error);
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $this->messenger()->addError($this->t('The event could not be loaded.'));
      return;
    }
    $this->assertCanManageEvent($event);

    $errors = $this->studioQuestionTemplateManager->saveRows(
      $event,
      $this->submittedExistingRows($form_state),
      $this->submittedNewRow($form_state)
    );
    if ($errors !== []) {
      foreach ($errors as $error) {
        $this->messenger()->addError($error);
      }
      return;
    }

    $this->studioAutosaveService->clearDraft($event, 'questions');
    $this->messenger()->addStatus($this->t('Checkout questions saved.'));
    $form_state->setRebuild(TRUE);
  }

  /**
   * @param array<string, mixed> $row
   * @param array<int, string> $ticketOptions
   */
  private function buildQuestionRow(array $row, array $ticketOptions): array {
    $editor = $this->buildQuestionEditor($row, $ticketOptions, FALSE);
    $preview = $this->buildPreviewText($row);

    return [
      'label' => [
        'id' => [
          '#type' => 'hidden',
          '#value' => (string) (int) $row['id'],
        ],
        'weight' => [
          '#type' => 'number',
          '#title' => $this->t('Order'),
          '#title_display' => 'invisible',
          '#default_value' => (int) $row['weight'],
          '#min' => 0,
          '#step' => 1,
          '#attributes' => ['class' => ['mel-event-studio-questions__weight']],
        ],
        'label' => $editor['label'],
      ],
      'type' => [
        'type' => $editor['type'],
        'options' => $editor['options'],
      ],
      'applicability' => [
        'applicability' => $editor['applicability'],
        'ticket_type_ids' => $editor['ticket_type_ids'],
      ],
      'required' => [
        'required' => $editor['required'],
      ],
      'status' => [
        'status' => $editor['status'],
      ],
      'actions' => [
        'machine_name' => $editor['machine_name'],
        'preview' => [
          '#markup' => '<p class="mel-event-studio-questions__preview">' . $preview . '</p>',
        ],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @param array<int, string> $ticketOptions
   *
   * @return array<string, mixed>
   */
  private function buildQuestionEditor(array $row, array $ticketOptions, bool $isNew): array {
    $applicabilitySelector = $isNew
      ? ':input[name="questions_card[new_question][applicability]"]'
      : ':input[name="questions_card[questions][' . (int) $row['id'] . '][applicability][applicability]"]';
    return [
      'label' => [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#title_display' => $isNew ? 'before' : 'invisible',
        '#default_value' => (string) ($row['label'] ?? ''),
        '#maxlength' => 255,
        '#attributes' => ['class' => ['mel-event-studio-questions__label']],
      ],
      'type' => [
        '#type' => 'select',
        '#title' => $this->t('Type'),
        '#title_display' => $isNew ? 'before' : 'invisible',
        '#options' => $this->studioQuestionTemplateManager->typeOptions(),
        '#default_value' => (string) ($row['type'] ?? 'textfield'),
      ],
      'options' => [
        '#type' => 'textarea',
        '#title' => $this->t('Options'),
        '#default_value' => (string) ($row['options'] ?? ''),
        '#rows' => 3,
        '#description' => $this->t('One option per line. Used only for select, checkbox, and radio questions.'),
      ],
      'applicability' => [
        '#type' => 'select',
        '#title' => $this->t('Applies to'),
        '#title_display' => $isNew ? 'before' : 'invisible',
        '#options' => $this->studioQuestionTemplateManager->applicabilityOptions(),
        '#default_value' => (string) ($row['applicability'] ?? EventStudioQuestionTemplateManager::APPLIES_PER_TICKET),
        '#description' => $isNew ? $this->t('Per-order questions are planned and are not available for active checkout capture yet.') : NULL,
      ],
      'ticket_type_ids' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Ticket types'),
        '#options' => $ticketOptions,
        '#default_value' => $row['ticket_type_ids'] ?? [],
        '#description' => $ticketOptions === []
          ? $this->t('Create ticket types before using per-ticket-type questions.')
          : $this->t('Required when Applies to is Per ticket type.'),
        '#states' => [
          'visible' => [
            $applicabilitySelector => ['value' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE],
          ],
        ],
      ],
      'required' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Required'),
        '#default_value' => !empty($row['required']),
      ],
      'status' => [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#title_display' => $isNew ? 'before' : 'invisible',
        '#options' => $this->studioQuestionTemplateManager->statusOptions(),
        '#default_value' => (string) ($row['status'] ?? EventStudioQuestionTemplateManager::STATUS_ACTIVE),
      ],
      'machine_name' => [
        '#type' => 'textfield',
        '#title' => $this->t('Machine name'),
        '#default_value' => (string) ($row['machine_name'] ?? ''),
        '#maxlength' => 64,
        '#description' => $this->t('Optional stable key. Leave blank to generate from the label.'),
      ],
      'weight' => [
        '#type' => 'hidden',
        '#value' => (string) (int) ($row['weight'] ?? 0),
      ],
    ];
  }

  /**
   * @param array<string, mixed> $row
   */
  private function buildPreviewText(array $row): string {
    $status = (string) ($row['status'] ?? EventStudioQuestionTemplateManager::STATUS_ACTIVE);
    $required = !empty($row['required']) ? (string) $this->t('Required') : (string) $this->t('Optional');
    if ($status === EventStudioQuestionTemplateManager::STATUS_ARCHIVED) {
      return (string) $this->t('Archived: hidden from new checkout sessions.');
    }
    return $required . '. ' . (string) $this->t('Answers are collected in checkout, not shown here.');
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function submittedExistingRows(FormStateInterface $form_state): array {
    $rows = $form_state->getValue(['questions_card', 'questions']) ?? [];
    if (!is_array($rows)) {
      return [];
    }
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $flat = [
        'id' => (int) ($row['label']['id'] ?? 0),
        'weight' => (int) ($row['label']['weight'] ?? 0),
        'label' => (string) ($row['label']['label'] ?? ''),
        'type' => (string) ($row['type']['type'] ?? 'textfield'),
        'options' => (string) ($row['type']['options'] ?? ''),
        'applicability' => (string) ($row['applicability']['applicability'] ?? EventStudioQuestionTemplateManager::APPLIES_PER_TICKET),
        'ticket_type_ids' => $row['applicability']['ticket_type_ids'] ?? [],
        'required' => !empty($row['required']['required']),
        'status' => (string) ($row['status']['status'] ?? EventStudioQuestionTemplateManager::STATUS_ACTIVE),
        'machine_name' => (string) ($row['actions']['machine_name'] ?? ''),
      ];
      if ($flat['id'] > 0) {
        $out[] = $flat;
      }
    }
    return $out;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function submittedNewRow(FormStateInterface $form_state): ?array {
    $row = $form_state->getValue(['questions_card', 'new_question']) ?? NULL;
    if (!is_array($row) || trim((string) ($row['label'] ?? '')) === '') {
      return NULL;
    }
    return [
      'id' => 0,
      'weight' => (int) ($row['weight'] ?? 999),
      'label' => (string) ($row['label'] ?? ''),
      'type' => (string) ($row['type'] ?? 'textfield'),
      'options' => (string) ($row['options'] ?? ''),
      'applicability' => (string) ($row['applicability'] ?? EventStudioQuestionTemplateManager::APPLIES_PER_TICKET),
      'ticket_type_ids' => $row['ticket_type_ids'] ?? [],
      'required' => !empty($row['required']),
      'status' => (string) ($row['status'] ?? EventStudioQuestionTemplateManager::STATUS_ACTIVE),
      'machine_name' => (string) ($row['machine_name'] ?? ''),
    ];
  }

  private function getRouteEvent(?NodeInterface $parameterNode = NULL): NodeInterface {
    if ($parameterNode instanceof NodeInterface && $parameterNode->bundle() === 'event') {
      return $parameterNode;
    }
    $node = $this->getRouteMatch()->getParameter('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      return $node;
    }
    throw new NotFoundHttpException();
  }

  private function loadSubmittedEvent(FormStateInterface $form_state): ?NodeInterface {
    $event_id = (int) ($form_state->getValue('event_id') ?? 0);
    if ($event_id < 1) {
      return NULL;
    }
    $event = $this->studioEntityTypeManager->getStorage('node')->load($event_id);
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

  private function assertCanManageEvent(NodeInterface $event): void {
    if ($this->studioCurrentUser->hasPermission('administer nodes')) {
      return;
    }
    if (!$this->studioEventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $this->studioCurrentUser)) {
      $this->studioLogger->warning('Event Studio questions access denied for event @nid uid @uid.', [
        '@nid' => (string) $event->id(),
        '@uid' => (string) $this->studioCurrentUser->id(),
      ]);
      throw new AccessDeniedHttpException();
    }
    if (!$event->access('update', $this->studioCurrentUser)) {
      throw new AccessDeniedHttpException();
    }
  }

}
