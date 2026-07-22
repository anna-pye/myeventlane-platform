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
use Drupal\paragraphs\ParagraphInterface;
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
    $form['#attributes']['class'][] = 'mel-event-studio-questions--table';
    $form['#attributes']['data-mel-event-studio-form'] = '1';
    $form['#attributes']['data-mel-event-studio-section'] = 'questions';
    if (!_myeventlane_event_studio_is_workspace_route()) {
      $form['#attached']['library'][] = 'myeventlane_event_studio/mel_event_studio';
    }

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
        '#markup' => '<h3 class="mel-es-card__title">' . $this->t('Ask only what helps you prepare') . '</h3>',
      ],
      'copy' => [
        '#markup' => '<p class="mel-es-card__hint">' . $this->t('Create short, useful questions that feel natural for attendees during checkout. Optional questions keep booking easier.') . '</p>',
      ],
      'per_order_notice' => [
        '#markup' => '<p class="mel-event-studio-questions__notice">' . $this->t('Recommended: keep questions short. Attendees can edit their answers before submitting checkout.') . '</p>',
      ],
      'per_order_planned' => [
        '#markup' => '<p class="mel-event-studio-questions__per-order-note">' . $this->t('Per order questions are planned but not active yet.') . '</p>',
      ],
      'targeting_helper' => [
        '#markup' => '<p class="mel-event-studio-questions__targeting-helper">' . $this->t('Use ticket type targeting when a question only applies to selected ticket types.') . '</p>',
      ],
    ];

    $legacy_summary = $this->studioQuestionTemplateManager->findLegacyTierQuestionSummary($event);
    if ($legacy_summary['total_count'] > 0) {
      $ticket_names = implode(', ', $legacy_summary['ticket_type_names']);
      $form['intro']['legacy_tier_notice'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t(
          'This event has @count ticket-type question templates saved on individual ticket types (@tickets). They still work at checkout, but new targeting should be managed here using “Specific ticket types”.',
          [
            '@count' => (int) $legacy_summary['total_count'],
            '@tickets' => $ticket_names,
          ]
        ),
        '#attributes' => [
          'class' => ['mel-event-studio-questions__legacy-notice'],
          'role' => 'note',
        ],
      ];
    }

    $ticket_options = $this->studioQuestionTemplateManager->ticketOptions($event);
    $question_rows = $this->studioQuestionTemplateManager->loadRows($event);

    $form['questions_card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-event-studio-questions__manager']],
    ];
    $form['questions_card']['questions'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['mel-event-studio-questions__table'],
        'role' => 'table',
      ],
    ];
    $form['questions_card']['questions']['header'] = $this->buildTableHeaderRow();

    foreach ($question_rows as $row) {
      $row_id = (int) $row['id'];
      $form['questions_card']['questions'][$row_id] = $this->buildQuestionRow($event, $row, $ticket_options);
    }

    if ($question_rows === []) {
      $form['questions_card']['questions']['empty'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__empty'],
          'role' => 'row',
        ],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('No checkout questions yet. Add a question below.'),
        ],
      ];
    }

    $form['questions_card']['new_question'] = [
      '#type' => 'details',
      '#title' => $this->t('Add an attendee question'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['mel-event-studio-questions__add']],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Ask for the information you actually need to welcome people well. You can archive questions later without losing past answers.'),
        '#attributes' => ['class' => ['mel-event-studio-questions__add-helper']],
      ],
      'row' => $this->buildNewQuestionRow($ticket_options, count($question_rows)),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save questions'),
        '#button_type' => 'primary',
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
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
    $this->messenger()->addStatus($this->t('Guest questions saved.'));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Column headings for the questions table.
   *
   * @return array<string, mixed>
   */
  private function buildTableHeaderRow(): array {
    $head_cell = function (string $label, string $modifier): array {
      return [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--' . $modifier],
          'role' => 'columnheader',
        ],
        'text' => [
          '#markup' => '<span class="mel-event-studio-questions__head-label">' . $label . '</span>',
        ],
      ];
    };

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-event-studio-questions__row', 'mel-event-studio-questions__row--head'],
        'role' => 'row',
      ],
      'label' => $head_cell((string) $this->t('Question'), 'label'),
      'type' => $head_cell((string) $this->t('Type'), 'type'),
      'required' => $head_cell((string) $this->t('Required'), 'required'),
      'applicability' => $head_cell((string) $this->t('Applies to'), 'applicability'),
      'tickets' => $head_cell((string) $this->t('Ticket types'), 'tickets'),
      'status' => $head_cell((string) $this->t('Status'), 'status'),
      'actions' => $head_cell((string) $this->t('More'), 'actions'),
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @param array<int, string> $ticketOptions
   *
   * @return array<string, mixed>
   */
  private function buildQuestionRow(NodeInterface $event, array $row, array $ticketOptions): array {
    $row_id = (int) $row['id'];
    $status = (string) ($row['status'] ?? EventStudioQuestionTemplateManager::STATUS_ACTIVE);
    $applicability = (string) ($row['applicability'] ?? EventStudioQuestionTemplateManager::APPLIES_PER_TICKET);
    $has_answers = $this->questionHasHistoricalAnswers($event, $row);
    $type_selector = ':input[name="questions_card[questions][' . $row_id . '][type][type]"]';
    $applicability_selector = ':input[name="questions_card[questions][' . $row_id . '][applicability][applicability]"]';

    $editor = $this->buildQuestionEditor(
      $row,
      $ticketOptions,
      FALSE,
      $has_answers,
      $type_selector,
      $applicability_selector,
      $applicability
    );

    $editor['label']['#parents'] = ['questions_card', 'questions', $row_id, 'label', 'label'];
    $editor['type']['#parents'] = ['questions_card', 'questions', $row_id, 'type', 'type'];
    $editor['options']['#parents'] = ['questions_card', 'questions', $row_id, 'type', 'options'];
    $editor['applicability']['#parents'] = ['questions_card', 'questions', $row_id, 'applicability', 'applicability'];
    $editor['ticket_type_ids']['#parents'] = ['questions_card', 'questions', $row_id, 'applicability', 'ticket_type_ids'];
    $editor['required']['#parents'] = ['questions_card', 'questions', $row_id, 'required', 'required'];
    $editor['status']['#parents'] = ['questions_card', 'questions', $row_id, 'status', 'status'];
    $editor['machine_name']['#parents'] = ['questions_card', 'questions', $row_id, 'actions', 'machine_name'];

    $type_label = (string) ($row['type_label'] ?? $this->studioQuestionTemplateManager->typeOptions()[(string) ($row['type'] ?? '')] ?? $row['type'] ?? $this->t('Question'));

    $row_classes = [
      'mel-event-studio-questions__row',
      $status === EventStudioQuestionTemplateManager::STATUS_ARCHIVED ? 'is-archived' : 'is-active',
    ];
    if ($has_answers) {
      $row_classes[] = 'mel-event-studio-questions__locked';
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => $row_classes,
        'role' => 'row',
      ],
      'label_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--label'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Question'),
        ],
        'id' => [
          '#type' => 'hidden',
          '#value' => (string) $row_id,
          '#parents' => ['questions_card', 'questions', $row_id, 'label', 'id'],
        ],
        'weight' => [
          '#type' => 'hidden',
          '#value' => (string) (int) $row['weight'],
          '#parents' => ['questions_card', 'questions', $row_id, 'label', 'weight'],
        ],
        'label' => $editor['label'],
        'locked_note' => $has_answers ? [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Answers on file — archive to retire. Type, targeting, and options cannot change.'),
          '#attributes' => ['class' => ['mel-event-studio-questions__locked-note']],
        ] : [],
      ],
      'type_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--type'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Type'),
        ],
        'type' => $editor['type'],
      ],
      'required_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--required'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Required'),
        ],
        'required' => $editor['required'],
      ],
      'applicability_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--applicability'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Applies to'),
        ],
        'applicability' => $editor['applicability'],
      ],
      'tickets_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--tickets'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Ticket types'),
        ],
        'ticket_type_ids' => $editor['ticket_type_ids'],
      ],
      'status_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--status'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Status'),
        ],
        'status' => $editor['status'],
      ],
      'actions_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--actions'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('More'),
        ],
        'details_toggle' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Expand row below'),
          '#attributes' => ['class' => ['visually-hidden']],
        ],
      ],
      'details' => [
        '#type' => 'details',
        '#title' => $this->t('More: options & preview'),
        '#open' => FALSE,
        '#attributes' => ['class' => ['mel-event-studio-questions__details']],
        'options' => $editor['options'],
        'machine_name' => $editor['machine_name'],
        'preview_heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('How attendees will see this'),
          '#attributes' => ['class' => ['mel-event-studio-questions__group-title']],
        ],
        'preview' => $this->buildAnswerPreview($row, $type_label),
        'preview_reassurance' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => !empty($row['required'])
            ? $this->t('Attendees will answer this before checkout can continue.')
            : $this->t('Attendees can skip this if it does not apply.'),
          '#attributes' => ['class' => ['mel-event-studio-questions__preview-reassurance']],
        ],
      ],
    ];
  }

  /**
   * @param array<int, string> $ticketOptions
   *
   * @return array<string, mixed>
   */
  private function buildNewQuestionRow(array $ticketOptions, int $weight): array {
    $type_selector = ':input[name="questions_card[new_question][row][type][type]"]';
    $applicability_selector = ':input[name="questions_card[new_question][row][applicability][applicability]"]';
    $row = [
      'id' => 0,
      'weight' => $weight,
      'label' => '',
      'type' => 'textfield',
      'required' => FALSE,
      'status' => EventStudioQuestionTemplateManager::STATUS_ACTIVE,
      'applicability' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET,
      'ticket_type_ids' => [],
      'options' => '',
      'machine_name' => '',
    ];
    $editor = $this->buildQuestionEditor(
      $row,
      $ticketOptions,
      TRUE,
      FALSE,
      $type_selector,
      $applicability_selector,
      EventStudioQuestionTemplateManager::APPLIES_PER_TICKET
    );

    $editor['label']['#parents'] = ['questions_card', 'new_question', 'row', 'label', 'label'];
    $editor['type']['#parents'] = ['questions_card', 'new_question', 'row', 'type', 'type'];
    $editor['options']['#parents'] = ['questions_card', 'new_question', 'row', 'type', 'options'];
    $editor['applicability']['#parents'] = ['questions_card', 'new_question', 'row', 'applicability', 'applicability'];
    $editor['ticket_type_ids']['#parents'] = ['questions_card', 'new_question', 'row', 'applicability', 'ticket_type_ids'];
    $editor['required']['#parents'] = ['questions_card', 'new_question', 'row', 'required', 'required'];
    $editor['status']['#parents'] = ['questions_card', 'new_question', 'row', 'status', 'status'];
    $editor['machine_name']['#parents'] = ['questions_card', 'new_question', 'row', 'actions', 'machine_name'];
    $editor['weight']['#parents'] = ['questions_card', 'new_question', 'row', 'label', 'weight'];

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-event-studio-questions__row', 'mel-event-studio-questions__row--add'],
        'role' => 'row',
      ],
      'label_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--label'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Question'),
        ],
        'label' => $editor['label'],
        'weight' => $editor['weight'],
      ],
      'type_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--type'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Type'),
        ],
        'type' => $editor['type'],
      ],
      'required_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--required'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Required'),
        ],
        'required' => $editor['required'],
      ],
      'applicability_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--applicability'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Applies to'),
        ],
        'applicability' => $editor['applicability'],
      ],
      'tickets_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--tickets'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Ticket types'),
        ],
        'ticket_type_ids' => $editor['ticket_type_ids'],
      ],
      'status_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--status'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('Status'),
        ],
        'status' => $editor['status'],
      ],
      'actions_cell' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-questions__cell', 'mel-event-studio-questions__cell--actions'],
          'role' => 'cell',
          'data-cell-label' => (string) $this->t('More'),
        ],
      ],
      'details' => [
        '#type' => 'details',
        '#title' => $this->t('Options and machine name'),
        '#open' => FALSE,
        '#attributes' => ['class' => ['mel-event-studio-questions__details']],
        'options' => $editor['options'],
        'machine_name' => $editor['machine_name'],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @param array<int, string> $ticketOptions
   * @param bool $isNew
   * @param bool $locked
   * @param string $typeSelector
   * @param string $applicabilitySelector
   * @param string $currentApplicability
   *
   * @return array<string, mixed>
   */
  private function buildQuestionEditor(
    array $row,
    array $ticketOptions,
    bool $isNew,
    bool $locked,
    string $typeSelector,
    string $applicabilitySelector,
    string $currentApplicability,
  ): array {
    $lock_message = $locked
      ? (string) $this->t('Locked because attendee answers exist. Archive this question and add a new one to change answer shape.')
      : NULL;

    $type = (string) ($row['type'] ?? 'textfield');
    $options_visible_states = [
      'visible' => [
        $typeSelector => [
          ['value' => 'select'],
          ['value' => 'checkboxes'],
          ['value' => 'radios'],
        ],
      ],
    ];

    return [
      'label' => [
        '#type' => 'textfield',
        '#title' => $this->t('Question'),
        '#title_display' => 'invisible',
        '#default_value' => (string) ($row['label'] ?? ''),
        '#maxlength' => 255,
        '#attributes' => ['class' => ['mel-event-studio-questions__label']],
        '#description' => $isNew ? $this->t('Write this exactly as attendees should see it.') : NULL,
      ],
      'type' => [
        '#type' => 'select',
        '#title' => $this->t('Type'),
        '#title_display' => 'invisible',
        '#options' => $this->studioQuestionTemplateManager->typeOptions(),
        '#default_value' => $type,
        '#disabled' => $locked,
        '#description' => $lock_message,
      ],
      'options' => [
        '#type' => 'textarea',
        '#title' => $this->t('Options'),
        '#title_display' => 'before',
        '#default_value' => (string) ($row['options'] ?? ''),
        '#rows' => 3,
        '#disabled' => $locked,
        '#description' => $this->t('One option per line.'),
        '#states' => $options_visible_states,
      ],
      'applicability' => [
        '#type' => 'select',
        '#title' => $this->t('Applies to'),
        '#title_display' => 'invisible',
        '#options' => $this->workspaceApplicabilityOptions($currentApplicability),
        '#default_value' => $currentApplicability,
        '#disabled' => $locked || $currentApplicability === EventStudioQuestionTemplateManager::APPLIES_PER_ORDER,
        '#description' => $lock_message,
      ],
      'ticket_type_ids' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Ticket types'),
        '#title_display' => 'invisible',
        '#options' => $ticketOptions,
        '#default_value' => $row['ticket_type_ids'] ?? [],
        '#disabled' => $locked,
        '#description' => $ticketOptions === []
          ? $this->t('Create ticket types before using specific ticket type targeting.')
          : ($isNew ? $this->t('Use ticket type targeting when a question only applies to selected ticket types.') : $lock_message),
        '#states' => [
          'visible' => [
            $applicabilitySelector => ['value' => EventStudioQuestionTemplateManager::APPLIES_PER_TICKET_TYPE],
          ],
        ],
      ],
      'required' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Required'),
        '#title_display' => 'invisible',
        '#default_value' => !empty($row['required']),
        '#disabled' => $locked,
      ],
      'status' => [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#title_display' => 'invisible',
        '#options' => $this->studioQuestionTemplateManager->statusOptions(),
        '#default_value' => (string) ($row['status'] ?? EventStudioQuestionTemplateManager::STATUS_ACTIVE),
        '#description' => $locked ? $this->t('Archive to hide from new checkouts while keeping past answers.') : NULL,
      ],
      'machine_name' => [
        '#type' => 'textfield',
        '#title' => $this->t('Machine name'),
        '#title_display' => 'before',
        '#default_value' => (string) ($row['machine_name'] ?? ''),
        '#maxlength' => 64,
        '#disabled' => $locked,
        '#description' => $this->t('Optional stable key. Leave blank to generate from the label.'),
      ],
      'weight' => [
        '#type' => 'hidden',
        '#value' => (string) (int) ($row['weight'] ?? 0),
      ],
    ];
  }

  /**
   * Applicability choices for workspace UI (per_order not selectable).
   *
   * @return array<string, string>
   */
  private function workspaceApplicabilityOptions(string $currentApplicability = EventStudioQuestionTemplateManager::APPLIES_PER_TICKET): array {
    $options = $this->studioQuestionTemplateManager->workspaceApplicabilityLabels();
    unset($options[EventStudioQuestionTemplateManager::APPLIES_PER_ORDER]);
    if ($currentApplicability === EventStudioQuestionTemplateManager::APPLIES_PER_ORDER) {
      $options[EventStudioQuestionTemplateManager::APPLIES_PER_ORDER] = (string) $this->t('Per order (not active in checkout yet)');
    }
    return $options;
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function buildAnswerPreview(array $row, string $typeLabel): array {
    $options = $this->studioQuestionTemplateManager->normalizeOptions((string) ($row['options'] ?? ''));
    if ($options !== []) {
      return [
        '#theme' => 'item_list',
        '#items' => array_slice($options, 0, 4),
        '#attributes' => ['class' => ['mel-event-studio-questions__answer-options']],
      ];
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('@type answer field shown at checkout.', ['@type' => $typeLabel]),
      '#attributes' => ['class' => ['mel-event-studio-questions__answer-placeholder']],
    ];
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
    $row = $form_state->getValue(['questions_card', 'new_question', 'row']) ?? NULL;
    if (!is_array($row)) {
      return NULL;
    }
    $label = trim((string) ($row['label']['label'] ?? ''));
    if ($label === '') {
      return NULL;
    }
    return [
      'id' => 0,
      'weight' => (int) ($row['label']['weight'] ?? 999),
      'label' => $label,
      'type' => (string) ($row['type']['type'] ?? 'textfield'),
      'options' => (string) ($row['type']['options'] ?? ''),
      'applicability' => (string) ($row['applicability']['applicability'] ?? EventStudioQuestionTemplateManager::APPLIES_PER_TICKET),
      'ticket_type_ids' => $row['applicability']['ticket_type_ids'] ?? [],
      'required' => !empty($row['required']['required']),
      'status' => (string) ($row['status']['status'] ?? EventStudioQuestionTemplateManager::STATUS_ACTIVE),
      'machine_name' => (string) ($row['actions']['machine_name'] ?? ''),
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
    // Workspace parity is sufficient (organiser owner / team), matching EventStudioAccess.
    if (!$this->studioEventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $this->studioCurrentUser)) {
      $this->studioLogger->warning('Event Studio questions access denied for event @nid uid @uid.', [
        '@nid' => (string) $event->id(),
        '@uid' => (string) $this->studioCurrentUser->id(),
      ]);
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * @param array<string, mixed> $row
   */
  private function questionHasHistoricalAnswers(NodeInterface $event, array $row): bool {
    $question_id = (int) ($row['id'] ?? 0);
    if ($question_id < 1 || !$event->hasField('field_attendee_questions')) {
      return FALSE;
    }
    foreach ($event->get('field_attendee_questions')->referencedEntities() as $paragraph) {
      if ($paragraph instanceof ParagraphInterface && (int) $paragraph->id() === $question_id) {
        return $this->studioQuestionTemplateManager->questionHasHistoricalAnswers($event, $paragraph);
      }
    }
    return FALSE;
  }

}
