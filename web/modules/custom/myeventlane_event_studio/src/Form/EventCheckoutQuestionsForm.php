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
        '#markup' => '<h3 class="mel-es-card__title">' . $this->t('Ask only what helps you prepare') . '</h3>',
      ],
      'copy' => [
        '#markup' => '<p class="mel-es-card__hint">' . $this->t('Create short, useful questions that feel natural for attendees during checkout. Optional questions keep booking easier.') . '</p>',
      ],
      'per_order_notice' => [
        '#markup' => '<p class="mel-event-studio-questions__notice">' . $this->t('Recommended: keep questions short. Attendees can edit their answers before submitting checkout.') . '</p>',
      ],
    ];

    $ticket_options = $this->studioQuestionTemplateManager->ticketOptions($event);
    $form['questions_card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-event-studio-questions__manager']],
    ];
    $form['questions_card']['questions'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['mel-event-studio-questions__table', 'mel-event-studio-questions__cards'],
        'role' => 'list',
      ],
    ];

    $question_rows = $this->studioQuestionTemplateManager->loadRows($event);
    foreach ($question_rows as $row) {
      $row_id = (int) $row['id'];
      $form['questions_card']['questions'][$row_id] = $this->buildQuestionRow($event, $row, $ticket_options);
    }
    if ($question_rows === []) {
      $form['questions_card']['questions']['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-questions__empty']],
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
        '#value' => $this->t('Save questions'),
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
  private function buildQuestionRow(NodeInterface $event, array $row, array $ticketOptions): array {
    $editor = $this->buildQuestionEditor($row, $ticketOptions, FALSE);
    $row_id = (int) $row['id'];
    $editor['label']['#parents'] = ['questions_card', 'questions', $row_id, 'label', 'label'];
    $editor['type']['#parents'] = ['questions_card', 'questions', $row_id, 'type', 'type'];
    $editor['options']['#parents'] = ['questions_card', 'questions', $row_id, 'type', 'options'];
    $editor['applicability']['#parents'] = ['questions_card', 'questions', $row_id, 'applicability', 'applicability'];
    $editor['ticket_type_ids']['#parents'] = ['questions_card', 'questions', $row_id, 'applicability', 'ticket_type_ids'];
    $editor['required']['#parents'] = ['questions_card', 'questions', $row_id, 'required', 'required'];
    $editor['status']['#parents'] = ['questions_card', 'questions', $row_id, 'status', 'status'];
    $editor['machine_name']['#parents'] = ['questions_card', 'questions', $row_id, 'actions', 'machine_name'];
    $preview = $this->buildPreviewText($row);
    $status = (string) ($row['status'] ?? EventStudioQuestionTemplateManager::STATUS_ACTIVE);
    $required = !empty($row['required']);
    $type_label = (string) ($row['type_label'] ?? $this->studioQuestionTemplateManager->typeOptions()[(string) ($row['type'] ?? '')] ?? $row['type'] ?? $this->t('Question'));
    $applicability_label = $this->applicabilityLabel((string) ($row['applicability'] ?? EventStudioQuestionTemplateManager::APPLIES_PER_TICKET));
    $has_answers = $this->questionHasHistoricalAnswers($event, $row);

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mel-event-studio-questions__card',
          $status === EventStudioQuestionTemplateManager::STATUS_ARCHIVED ? 'is-archived' : 'is-active',
        ],
        'role' => 'listitem',
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-questions__header']],
        'id' => [
          '#type' => 'hidden',
          '#value' => (string) (int) $row['id'],
          '#parents' => ['questions_card', 'questions', $row_id, 'label', 'id'],
        ],
        'weight' => [
          '#type' => 'hidden',
          '#value' => (string) (int) $row['weight'],
          '#parents' => ['questions_card', 'questions', $row_id, 'label', 'weight'],
        ],
        'identity' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-questions__identity']],
          'label' => $editor['label'],
          'preview' => [
            '#markup' => '<p class="mel-event-studio-questions__preview">' . $preview . '</p>',
          ],
        ],
        'badges' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-questions__badges']],
          'type' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $type_label,
            '#attributes' => ['class' => ['mel-event-studio-card-badge', 'mel-event-studio-card-badge--type']],
          ],
          'required' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $required ? $this->t('Required') : $this->t('Optional'),
            '#attributes' => ['class' => ['mel-event-studio-card-badge', $required ? 'mel-event-studio-card-badge--required' : 'mel-event-studio-card-badge--muted']],
          ],
          'status' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $status === EventStudioQuestionTemplateManager::STATUS_ARCHIVED ? $this->t('Archived') : $this->t('Active'),
            '#attributes' => ['class' => ['mel-event-studio-card-badge', $status === EventStudioQuestionTemplateManager::STATUS_ARCHIVED ? 'mel-event-studio-card-badge--muted' : 'mel-event-studio-card-badge--active']],
          ],
        ],
      ],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-questions__body']],
        'answer_preview' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-questions__preview-panel']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('How attendees will see this'),
            '#attributes' => ['class' => ['mel-event-studio-questions__group-title']],
          ],
          'content' => $this->buildAnswerPreview($row, $type_label),
          'reassurance' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $required
              ? $this->t('Attendees will answer this before checkout can continue.')
              : $this->t('Attendees can skip this if it does not apply.'),
            '#attributes' => ['class' => ['mel-event-studio-questions__preview-reassurance']],
          ],
        ],
        'summary' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-questions__summary-row']],
          'applies' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('Shown for: @scope', ['@scope' => $applicability_label]),
            '#attributes' => ['class' => ['mel-event-studio-questions__summary-pill']],
          ],
          'status' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $status === EventStudioQuestionTemplateManager::STATUS_ARCHIVED
              ? $this->t('Archived for new checkouts')
              : $this->t('Active in checkout'),
            '#attributes' => ['class' => ['mel-event-studio-questions__summary-pill']],
          ],
        ],
      ],
      'advanced' => [
        '#type' => 'details',
        '#title' => $this->t('Optional question settings'),
        '#open' => FALSE,
        '#attributes' => ['class' => ['mel-event-studio-questions__advanced']],
        'governance_note' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $has_answers
            ? $this->t('This question already has attendee answers. To protect past responses, archive it and add a new question when you need a different answer shape.')
            : $this->t('Most events can leave these settings as-is. Use them when a question should appear only for certain tickets.'),
          '#attributes' => ['class' => array_filter(['mel-event-studio-questions__governance-note', $has_answers ? 'has-warning' : NULL])],
        ],
        'type_group' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-questions__field-group', 'mel-event-studio-questions__type']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Answer setup'),
            '#attributes' => ['class' => ['mel-event-studio-questions__group-title']],
          ],
          'type' => $editor['type'],
          'options' => $editor['options'],
          'required' => $editor['required'],
        ],
        'audience_group' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-questions__field-group', 'mel-event-studio-questions__applicability']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Where it appears'),
            '#attributes' => ['class' => ['mel-event-studio-questions__group-title']],
          ],
          'applicability' => $editor['applicability'],
          'ticket_type_ids' => $editor['ticket_type_ids'],
        ],
        'governance_group' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-questions__field-group', 'mel-event-studio-questions__governance']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Governance'),
            '#attributes' => ['class' => ['mel-event-studio-questions__group-title']],
          ],
          'status' => $editor['status'],
          'machine_name' => $editor['machine_name'],
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
        '#title' => $this->t('Question'),
        '#title_display' => 'before',
        '#default_value' => (string) ($row['label'] ?? ''),
        '#maxlength' => 255,
        '#attributes' => ['class' => ['mel-event-studio-questions__label']],
        '#description' => $this->t('Write this exactly as attendees should see it.'),
      ],
      'type' => [
        '#type' => 'select',
        '#title' => $this->t('Type'),
        '#title_display' => 'before',
        '#options' => $this->studioQuestionTemplateManager->typeOptions(),
        '#default_value' => (string) ($row['type'] ?? 'textfield'),
      ],
      'options' => [
        '#type' => 'textarea',
        '#title' => $this->t('Options'),
        '#default_value' => (string) ($row['options'] ?? ''),
        '#rows' => 3,
        '#description' => $this->t('One option per line. Keep choices clear and easy to answer.'),
      ],
      'applicability' => [
        '#type' => 'select',
        '#title' => $this->t('Applies to'),
        '#title_display' => 'before',
        '#options' => $this->studioQuestionTemplateManager->applicabilityOptions(),
        '#default_value' => (string) ($row['applicability'] ?? EventStudioQuestionTemplateManager::APPLIES_PER_TICKET),
        '#description' => $isNew ? $this->t('Most events can use Per ticket. Choose Per ticket type only when a question applies to specific offers.') : NULL,
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
        '#title_display' => 'before',
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
    $type_label = (string) ($row['type_label'] ?? $this->studioQuestionTemplateManager->typeOptions()[(string) ($row['type'] ?? '')] ?? $row['type'] ?? $this->t('Question'));
    if ($status === EventStudioQuestionTemplateManager::STATUS_ARCHIVED) {
      return (string) $this->t('Archived: hidden from new checkout sessions.');
    }
    return $required . ' • ' . $type_label;
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

  private function applicabilityLabel(string $applicability): string {
    $options = $this->studioQuestionTemplateManager->applicabilityOptions();
    return $options[$applicability] ?? $options[EventStudioQuestionTemplateManager::APPLIES_PER_TICKET];
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
