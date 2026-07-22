<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing checkout questions.
 */
final class EventCheckoutQuestionsForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUserProxy,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_vendor_event_checkout_questions_form';
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
      '#value' => $this->t('Add custom questions to collect additional information from attendees during checkout.'),
    ];

    $form['default_questions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Default Questions'),
      '#description' => $this->t('By default, we ask: First name, Last name, Email'),
    ];

    $questions = [];
    if ($event->hasField('field_attendee_questions') && !$event->get('field_attendee_questions')->isEmpty()) {
      $questions = $event->get('field_attendee_questions')->referencedEntities();
    }

    $header = [
      'type' => $this->t('Type'),
      'question' => $this->t('Question'),
      'required' => $this->t('Required'),
      'apply_to' => $this->t('Apply to'),
      'operations' => $this->t('Actions'),
    ];

    $rows = [];
    foreach ($questions as $delta => $paragraph) {
      if (!$paragraph instanceof ParagraphInterface) {
        continue;
      }

      $type = $paragraph->get('field_question_type')->value ?? 'text';
      $label = $paragraph->get('field_question_label')->value ?? 'Unnamed Question';
      $required = (bool) ($paragraph->get('field_question_required')->value ?? FALSE);
      $status = $paragraph->hasField('field_question_status') && !$paragraph->get('field_question_status')->isEmpty()
        ? (string) $paragraph->get('field_question_status')->value
        : 'active';
      $apply_to = $this->t('All guests');

      $rows[$delta] = [
        'type' => ['#markup' => ucfirst($type)],
        'question' => ['#markup' => $label],
        'required' => ['#markup' => $required ? $this->t('Yes') : $this->t('No')],
        'apply_to' => ['#markup' => $apply_to],
        'operations' => [
          'archive' => [
            '#type' => 'submit',
            '#value' => $status === 'archived' ? $this->t('Archived') : $this->t('Archive'),
            '#submit' => ['::deleteQuestion'],
            '#name' => 'archive_question_' . $paragraph->id(),
            '#paragraph_id' => $paragraph->id(),
            '#disabled' => $status === 'archived',
            '#attributes' => ['class' => ['button', 'button--small', 'button--danger']],
          ],
        ],
      ];
    }

    $form['questions_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No custom questions configured. Use the button below to add a question.'),
    ];

    $form['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-question-actions']],
      'add_question' => [
        '#type' => 'submit',
        '#value' => $this->t('Add Question'),
        '#submit' => ['::addQuestion'],
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'save' => [
        '#type' => 'submit',
        '#value' => $this->t('Save questions'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    return $form;
  }

  /**
   * Submit handler to add a new question.
   */
  public function addQuestion(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event') ?? $form['#event'] ?? NULL;

    if (!$event || !$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('Event not found or access denied.'));
      return;
    }

    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $paragraph = $paragraph_storage->create([
      'type' => 'attendee_extra_field',
      'field_question_type' => 'textfield',
      'field_question_label' => 'New Question',
      'field_question_required' => FALSE,
    ]);
    if ($paragraph->hasField('field_question_status')) {
      $paragraph->set('field_question_status', 'active');
    }
    if ($paragraph->hasField('field_question_applicability')) {
      $paragraph->set('field_question_applicability', 'per_ticket');
    }
    $paragraph->save();

    $questions = $event->hasField('field_attendee_questions') ? $event->get('field_attendee_questions')->getValue() : [];
    $questions[] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
    $event->set('field_attendee_questions', $questions);
    $event->save();

    $this->messenger()->addStatus($this->t('Question added. Edit it to configure the question details.'));
    $form_state->setRebuild();
  }

  /**
   * Submit handler to archive a question without deleting historical answers.
   */
  public function deleteQuestion(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event') ?? $form['#event'] ?? NULL;

    if (!$event || !$this->canManageEvent($event)) {
      $this->messenger()->addError($this->t('Event not found or access denied.'));
      return;
    }

    $triggering_element = $form_state->getTriggeringElement();
    $paragraph_id = $triggering_element['#paragraph_id'] ?? NULL;
    if (!$paragraph_id) {
      return;
    }

    $paragraph = $this->entityTypeManager->getStorage('paragraph')->load($paragraph_id);
    if (!$paragraph) {
      return;
    }

    if ($paragraph instanceof ParagraphInterface && $paragraph->hasField('field_question_status')) {
      $paragraph->set('field_question_status', 'archived');
      $paragraph->save();
      $event->save();
    }

    $this->messenger()->addStatus($this->t('Question archived.'));
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Guest questions saved.'));
  }

  /**
   * Lightweight access check for vendor ownership/admin.
   */
  private function canManageEvent(NodeInterface $event): bool {
    if ($this->currentUserProxy->hasPermission('administer nodes')) {
      return TRUE;
    }
    return $this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $this->currentUserProxy)
      && $event->access('update', $this->currentUserProxy);
  }

}
