<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Ticket Holder Paragraph checkout pane.
 *
 * Stores attendee data in paragraph entities (attendee_answer) referenced
 * via field_ticket_holder on order items. This is the canonical attendee
 * storage system for MyEventLane.
 *
 * @CommerceCheckoutPane(
 *   id = "ticket_holder_paragraph",
 *   label = @Translation("Ticket Holder Information"),
 *   default_step = "order_information",
 * )
 */
final class TicketHolderParagraphPane extends CheckoutPaneBase {

  /**
   * Logger channel for checkout events.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  /**
   * The email validator.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  private EmailValidatorInterface $emailValidator;

  /**
   * The ticket label resolver.
   *
   * @var \Drupal\myeventlane_core\Service\TicketLabelResolver
   */
  private TicketLabelResolver $ticketLabelResolver;

  /**
   * Paid tier resolver (variation → mel_ticket_type) for merged questions.
   *
   * @var \Drupal\myeventlane_commerce\Service\TicketAvailabilityService
   */
  private TicketAvailabilityService $ticketAvailability;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  private AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    // Varies per user because ticket-holder paragraphs are per-order owned by
    // user.
    return array_merge(parent::getCacheContexts(), ['user']);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->logger = $container->get('logger.factory')->get('myeventlane_checkout_paragraph');
    $instance->emailValidator = $container->get('email.validator');
    $instance->ticketLabelResolver = $container->get('myeventlane_core.ticket_label_resolver');
    $instance->ticketAvailability = $container->get('myeventlane_commerce.ticket_availability');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $pane_form['#tree'] = TRUE;
    $pane_form['#attached']['library'][] = 'myeventlane_checkout_paragraph/checkout_attendee_groups';

    if (!$this->isVisible()) {
      return $pane_form;
    }

    if (!$this->hasAnyQuestionTemplates()) {
      $pane_form['#attributes']['class'][] = 'mel-attendee-details-pane';
      $pane_form['#attributes']['class'][] = 'mel-attendee-details-pane--empty';
      $pane_form['no_attendee_questions'] = [
        '#type' => 'hidden',
        '#value' => '1',
      ];
      return $pane_form;
    }

    $pane_form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-intro']],
      'title' => [
        '#markup' => '<h3>' . $this->t('Attendee questions') . '</h3>',
      ],
      'desc' => [
        '#markup' => '<p>' . $this->t('Add per-ticket details and answer organiser questions before continuing.') . '</p>',
      ],
    ];

    $pane_form['order_items'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-attendee-details-groups']],
      '#tree' => TRUE,
    ];
    $logged_in_defaults = $this->getLoggedInAttendeeDefaults();

    $holder_total = $this->getCollectableTicketHolderCount();
    $holder_position = 0;

    foreach ($this->order->getItems() as $index => $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }

      $quantity = (int) $order_item->getQuantity();
      $holders = $order_item->get('field_ticket_holder')->referencedEntities();
      $templates = $this->getExtraQuestionTemplates($order_item);

      $ticket_label = $this->ticketLabelResolver->getTicketLabel($order_item);
      $pane_form['order_items'][$index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-checkout-ticket-group']],
        '#tree' => TRUE,
        'ticket_title' => [
          '#markup' => '<h4 class="mel-checkout-ticket-type-title">' . Html::escape($ticket_label) . '</h4>',
        ],
      ];

      for ($delta = 0; $delta < $quantity; $delta++) {
        $holder = $holders[$delta] ?? NULL;
        $holder_position++;
        $pane_form['order_items'][$index][$delta] = $this->buildTicketHolderForm($holder, $templates, $index, $delta, $logged_in_defaults, $holder_position, $holder_total);
      }
    }

    return $pane_form;
  }

  /**
   * Counts ticket-holder forms rendered in this pane.
   */
  private function getCollectableTicketHolderCount(): int {
    $total = 0;
    foreach ($this->order->getItems() as $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }
      $total += max(0, (int) $order_item->getQuantity());
    }

    return $total;
  }

  /**
   * Builds the form elements for a single ticket holder.
   */
  private function buildTicketHolderForm(?ParagraphInterface $holder, array $templates, int $itemIndex, int $delta, array $loggedInDefaults, int $holderPosition, int $holderTotal): array {
    $fieldset = [
      '#type' => 'details',
      '#title' => $this->t('Ticket @current of @total', [
        '@current' => $holderPosition,
        '@total' => max(1, $holderTotal),
      ]),
      '#open' => TRUE,
      '#attributes' => [
        'class' => ['mel-attendee-card'],
        'data-ticket-position' => (string) $holderPosition,
        'data-ticket-total' => (string) max(1, $holderTotal),
      ],
    ];

    $fieldset['ticket_count_label'] = [
      '#markup' => '<p class="mel-attendee-card__count">' . $this->t('Ticket @current of @total', [
        '@current' => $holderPosition,
        '@total' => max(1, $holderTotal),
      ]) . '</p>',
    ];

    $fieldset['identity_heading'] = [
      '#markup' => '<h5 class="mel-attendee-card__heading">' . $this->t('Ticket holder') . '</h5>',
    ];

    // Required fields: first_name, last_name, email.
    $fieldset['field_first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#default_value' => $this->defaultIfEmpty($holder?->get('field_first_name')->value ?? '', $loggedInDefaults['first_name'] ?? ''),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['mel-attendee-identity-field'],
      ],
    ];
    $fieldset['field_last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#default_value' => $this->defaultIfEmpty($holder?->get('field_last_name')->value ?? '', $loggedInDefaults['last_name'] ?? ''),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['mel-attendee-identity-field'],
      ],
    ];
    $fieldset['field_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $this->defaultIfEmpty($holder?->get('field_email')->value ?? '', $loggedInDefaults['email'] ?? ''),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['mel-attendee-identity-field'],
      ],
    ];
    $fieldset['field_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone number'),
      '#default_value' => $holder && $holder->hasField('field_phone') ? ($holder->get('field_phone')->value ?? '') : '',
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['mel-attendee-identity-field'],
      ],
    ];

    // Dynamic extra questions derived from templates (or existing children).
    $question_sources = ($holder && $holder->hasField('field_attendee_questions') && !$holder->get('field_attendee_questions')->isEmpty())
      ? $holder->get('field_attendee_questions')->referencedEntities()
      : $templates;
    if ($question_sources !== []) {
      $fieldset['questions_heading'] = [
        '#markup' => '<h5 class="mel-attendee-card__heading mel-attendee-card__heading--questions">' . $this->t('Attendee questions') . '</h5>',
      ];
    }
    foreach ($question_sources as $q_index => $question) {
      $label = $question->hasField('field_question_label')
        ? (string) ($question->get('field_question_label')->value ?? '')
        : '';
      if ($label === '') {
        $label = 'Extra Question';
      }
      $type = $question->hasField('field_question_type')
        ? (string) ($question->get('field_question_type')->value ?? 'text')
        : 'text';
      $field_name = "extra_{$itemIndex}_{$delta}_{$q_index}";

      // Normalize: always read from field_attendee_extra_field.
      $default = '';
      if ($question->hasField('field_attendee_extra_field') && !$question->get('field_attendee_extra_field')->isEmpty()) {
        $default = $question->get('field_attendee_extra_field')->value ?? '';
      }
      if (trim((string) $default) === '') {
        $basic_question = $this->classifyBasicAttendeeQuestion($question);
        if ($basic_question !== NULL) {
          $default = $loggedInDefaults[$basic_question] ?? '';
        }
      }

      $options = [];
      if ($question->hasField('field_question_options')) {
        foreach ($question->get('field_question_options')->getValue() ?? [] as $item) {
          $opt = trim($item['value'] ?? '');
          if ($opt !== '') {
            $options[$opt] = $opt;
          }
        }
      }

      switch ($type) {
        case 'select':
          $fieldset[$field_name] = [
            '#type' => 'select',
            '#title' => $label,
            '#options' => ['' => $this->t('- Select -')] + ($options ?: ['_' => $this->t('No options')]),
            '#default_value' => $default,
            '#required' => TRUE,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
          ];
          break;

        case 'checkbox':
          $fieldset[$field_name] = [
            '#type' => 'checkbox',
            '#title' => $label,
            '#default_value' => (bool) $default,
            '#required' => TRUE,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
          ];
          break;

        case 'checkboxes':
          $decoded = [];
          if ($default !== '' && $default !== NULL) {
            $try = json_decode((string) $default, TRUE);
            if (is_array($try)) {
              $decoded = $try;
            }
          }
          $fieldset[$field_name] = [
            '#type' => 'checkboxes',
            '#title' => $label,
            '#options' => $options ?: ['_' => $this->t('Option')],
            '#default_value' => $decoded,
            '#required' => TRUE,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
          ];
          break;

        case 'radio':
        case 'radios':
          $fieldset[$field_name] = [
            '#type' => 'radios',
            '#title' => $label,
            '#options' => $options ?: ['_' => $this->t('Option')],
            '#default_value' => $default,
            '#required' => TRUE,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
          ];
          break;

        case 'textarea':
          $fieldset[$field_name] = [
            '#type' => 'textarea',
            '#title' => $label,
            '#rows' => 3,
            '#default_value' => $default,
            '#required' => TRUE,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
          ];
          break;

        default:
          $fieldset[$field_name] = [
            '#type' => 'textfield',
            '#title' => $label,
            '#default_value' => is_scalar($default) || $default === NULL ? (string) ($default ?? '') : '',
            '#required' => TRUE,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
          ];
          break;
      }
    }

    return $fieldset;
  }

  /**
   * Gets account defaults for signed-in users.
   *
   * @return array{name?: string, email?: string, first_name?: string, last_name?: string}
   *   Attendee defaults derived from the current account.
   */
  private function getLoggedInAttendeeDefaults(): array {
    if (!$this->currentUser->isAuthenticated()) {
      return [];
    }

    $name = trim($this->currentUser->getDisplayName());
    $email = trim((string) $this->currentUser->getEmail());
    [$first_name, $last_name] = $this->splitDisplayName($name);

    return [
      'name' => $name,
      'email' => $email,
      'first_name' => $first_name,
      'last_name' => $last_name,
    ];
  }

  /**
   * Keeps existing attendee values ahead of account-derived defaults.
   */
  private function defaultIfEmpty(mixed $value, string $fallback): string {
    $value = is_scalar($value) || $value === NULL ? trim((string) ($value ?? '')) : '';
    return $value !== '' ? $value : $fallback;
  }

  /**
   * Classifies the simple name/email attendee questions that can be prefilled.
   */
  private function classifyBasicAttendeeQuestion(ParagraphInterface $question): ?string {
    $machine_name = $question->hasField('field_question_machine_name')
      ? mb_strtolower(trim((string) ($question->get('field_question_machine_name')->value ?? '')))
      : '';
    $label = $question->hasField('field_question_label')
      ? mb_strtolower(trim((string) ($question->get('field_question_label')->value ?? '')))
      : '';
    $type = $question->hasField('field_question_type')
      ? mb_strtolower(trim((string) ($question->get('field_question_type')->value ?? 'textfield')))
      : 'textfield';

    $key = $machine_name !== '' ? $machine_name : $label;
    $key = str_replace(['-', ' '], '_', $key);

    if ($key === 'name' && $type === 'textfield') {
      return 'name';
    }

    if ($key === 'email' && in_array($type, ['email', 'textfield'], TRUE)) {
      return 'email';
    }

    return NULL;
  }

  /**
   * Splits a display name for the required first/last name fields.
   *
   * @return array{0: string, 1: string}
   *   First name and last name.
   */
  private function splitDisplayName(string $name): array {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first_name = array_shift($parts) ?: '';
    $last_name = trim(implode(' ', $parts));

    return [$first_name, $last_name];
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible(): bool {
    foreach ($this->order->getItems() as $order_item) {
      if ($this->shouldCollectTicketHolders($order_item)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    if (!$this->hasAnyQuestionTemplates()) {
      return;
    }

    $pane_values = $form_state->getValue($this->getPluginId()) ?? [];
    $order_items = $pane_values['order_items'] ?? [];
    if (!is_array($order_items)) {
      $form_state->setErrorByName("{$this->getPluginId()}][order_items", $this->t('Attendee details are required.'));
      return;
    }

    foreach ($this->order->getItems() as $index => $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }

      $quantity = (int) $order_item->getQuantity();
      $holders = $order_item->get('field_ticket_holder')->referencedEntities();
      $templates = $this->getExtraQuestionTemplates($order_item);
      $tickets = $order_items[$index] ?? [];
      if (!is_array($tickets)) {
        $form_state->setErrorByName("{$this->getPluginId()}][order_items][$index", $this->t('Attendee details are required.'));
        continue;
      }

      for ($delta = 0; $delta < $quantity; $delta++) {
        $entry = $tickets[$delta] ?? [];
        if (!is_array($entry)) {
          $form_state->setErrorByName("{$this->getPluginId()}][order_items][$index][$delta", $this->t('Attendee @num details are required.', ['@num' => $delta + 1]));
          continue;
        }
        // Validate required fields.
        if (empty($entry['field_first_name'])) {
          $form_state->setErrorByName("{$this->getPluginId()}][order_items][$index][$delta][field_first_name", $this->t('First name is required.'));
        }
        if (empty($entry['field_last_name'])) {
          $form_state->setErrorByName("{$this->getPluginId()}][order_items][$index][$delta][field_last_name", $this->t('Last name is required.'));
        }
        if (empty($entry['field_email'])) {
          $form_state->setErrorByName("{$this->getPluginId()}][order_items][$index][$delta][field_email", $this->t('Email is required.'));
        }
        elseif (!$this->emailValidator->isValid($entry['field_email'])) {
          $form_state->setErrorByName("{$this->getPluginId()}][order_items][$index][$delta][field_email", $this->t('Please enter a valid email address.'));
        }
        if (empty($entry['field_phone'])) {
          $form_state->setErrorByName("{$this->getPluginId()}][order_items][$index][$delta][field_phone", $this->t('Phone number is required.'));
        }

        $holder = $holders[$delta] ?? NULL;
        $question_sources = ($holder instanceof ParagraphInterface
          && $holder->hasField('field_attendee_questions')
          && !$holder->get('field_attendee_questions')->isEmpty())
          ? $holder->get('field_attendee_questions')->referencedEntities()
          : $templates;
        $this->validateQuestionAnswers($form_state, (int) $index, $delta, $entry, $question_sources);
      }
    }
  }

  /**
   * Validates all attendee question answers for a ticket holder.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param int $itemIndex
   *   The order item form index.
   * @param int $delta
   *   The ticket holder form delta.
   * @param array $entry
   *   Submitted ticket holder values.
   * @param \Drupal\paragraphs\ParagraphInterface[] $questionSources
   *   Question paragraphs rendered for this ticket holder.
   */
  private function validateQuestionAnswers(FormStateInterface $form_state, int $itemIndex, int $delta, array $entry, array $questionSources): void {
    foreach ($questionSources as $q_index => $question) {
      if (!$question instanceof ParagraphInterface) {
        continue;
      }

      $field_key = "extra_{$itemIndex}_{$delta}_{$q_index}";
      if (array_key_exists($field_key, $entry) && !$this->isEmptySubmittedAnswer($entry[$field_key])) {
        continue;
      }

      $label = $question->hasField('field_question_label')
        ? trim((string) ($question->get('field_question_label')->value ?? ''))
        : '';
      if ($label === '') {
        $label = (string) $this->t('Attendee question');
      }

      $form_state->setErrorByName(
        "{$this->getPluginId()}][order_items][$itemIndex][$delta][$field_key",
        $this->t('@question is required.', ['@question' => $label])
      );
    }
  }

  /**
   * Determines whether a submitted attendee answer is empty.
   */
  private function isEmptySubmittedAnswer(mixed $value): bool {
    if ($value === NULL || $value === FALSE) {
      return TRUE;
    }

    if (is_array($value)) {
      foreach ($value as $item) {
        if ($item !== NULL && $item !== FALSE && $item !== 0 && $item !== '0' && $item !== '') {
          return FALSE;
        }
      }
      return TRUE;
    }

    return is_string($value) ? trim($value) === '' : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    if (!$this->hasAnyQuestionTemplates()) {
      $this->saveMinimalTicketHoldersFromBuyerDetails($form_state);
      return;
    }

    $pane_values = $form_state->getValue($this->getPluginId()) ?? [];
    $order_items_values = $pane_values['order_items'] ?? NULL;

    if (!is_array($order_items_values)) {
      return;
    }

    foreach ($this->order->getItems() as $index => $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }

      $ticket_values = $order_items_values[$index] ?? [];
      if (!is_array($ticket_values)) {
        continue;
      }

      $this->saveTicketHolders($order_item, $ticket_values, $index);
    }
  }

  /**
   * Saves ticket holder data for a single order item.
   */
  private function saveTicketHolders(OrderItemInterface $order_item, array $ticket_values, int $itemIndex): void {
    $quantity = (int) $order_item->getQuantity();
    $holders = $order_item->get('field_ticket_holder')->referencedEntities();
    $createdParagraphIds = [];
    $templates = $this->getExtraQuestionTemplates($order_item);

    // Ensure enough holder paragraphs exist.
    if (count($holders) < $quantity) {
      for ($i = count($holders); $i < $quantity; $i++) {
        $newHolder = $this->createHolderWithQuestions($templates);
        $holders[] = $newHolder;
        if ($newHolder instanceof ParagraphInterface && !$newHolder->isNew()) {
          $createdParagraphIds[] = (int) $newHolder->id();
        }
      }
      $order_item->set('field_ticket_holder', $holders);
    }

    foreach ($holders as $delta => $paragraph) {
      if (!$this->isHolderFormDeltaKey($delta)) {
        continue;
      }
      if (!$paragraph instanceof ParagraphInterface) {
        continue;
      }
      $entry = $ticket_values[$delta] ?? [];
      if (!is_array($entry)) {
        continue;
      }

      // Save required fields.
      $paragraph->set('field_first_name', $entry['field_first_name'] ?? '');
      $paragraph->set('field_last_name', $entry['field_last_name'] ?? '');
      $paragraph->set('field_email', $entry['field_email'] ?? '');
      if ($paragraph->hasField('field_phone')) {
        $paragraph->set('field_phone', $entry['field_phone'] ?? '');
      }

      $clonedTemplateCount = $this->ensureHolderHasQuestionParagraphs($paragraph, $templates);
      $answersSaved = 0;
      $questionChildCount = 0;

      // Save extra questions - normalize to field_attendee_extra_field.
      if ($paragraph->hasField('field_attendee_questions')) {
        $children = $paragraph->get('field_attendee_questions')->referencedEntities();
        $questionChildCount = count($children);
        foreach ($children as $q_index => $child) {
          $field_key = "extra_{$itemIndex}_{$delta}_{$q_index}";
          $value = $entry[$field_key] ?? NULL;
          if ($value !== NULL && $child->hasField('field_attendee_extra_field')) {
            // Normalize: always write to field_attendee_extra_field.
            // Convert arrays (e.g., checkboxes) to JSON string.
            $normalized_value = is_array($value) ? json_encode($value) : (string) $value;
            $child->set('field_attendee_extra_field', $normalized_value);
            $child->save();
            $answersSaved++;
          }
        }
      }

      // Integrity check: ensure paragraph has a parent order item reference.
      // This is implicit via field_ticket_holder, but we log if something seems wrong.
      $paragraph->save();

      // Verify the paragraph is still referenced by this order item.
      $order_item->save();
      $this->verifyParagraphAttachment($paragraph, $order_item);

      $holderPid = $paragraph->id() !== NULL ? (string) $paragraph->id() : 'new';
      $this->logger->notice(
        'TEMP_DEBUG attendee_questions: order_item=@oi holder_delta=@d holder_pid=@hp templates_cloned_this_save=@tc answers_saved=@as question_children=@qc',
        [
          '@oi' => $order_item->id() !== NULL ? (string) $order_item->id() : 'new',
          '@d' => (string) $delta,
          '@hp' => $holderPid,
          '@tc' => (string) $clonedTemplateCount,
          '@as' => (string) $answersSaved,
          '@qc' => (string) $questionChildCount,
        ]
      );
    }

    $paragraphIds = [];
    foreach ($holders as $holderParagraph) {
      if ($holderParagraph instanceof ParagraphInterface && !$holderParagraph->isNew()) {
        $paragraphIds[] = (int) $holderParagraph->id();
      }
    }

    // TEMP_DEBUG: remove after multi-holder verification on staging/production.
    $this->logger->notice(
      'TEMP_DEBUG saveTicketHolders: order_item=@item qty_expected=@qty paragraph_count=@count paragraph_ids=@ids created_paragraph_ids=@created',
      [
        '@item' => $order_item->id() !== NULL ? (string) $order_item->id() : 'new',
        '@qty' => (string) $quantity,
        '@count' => (string) count($paragraphIds),
        '@ids' => $paragraphIds !== [] ? implode(',', $paragraphIds) : 'none',
        '@created' => $createdParagraphIds !== [] ? implode(',', $createdParagraphIds) : 'none',
      ]
    );

    $this->logger->info('Saved @count ticket holder(s) for order item @id.', [
      '@count' => count($holders),
      '@id' => $order_item->id(),
    ]);
  }

  /**
   * Verifies that a paragraph is properly attached to an order item.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The attendee paragraph.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item that should reference it.
   */
  private function verifyParagraphAttachment(ParagraphInterface $paragraph, OrderItemInterface $order_item): void {
    // Check if paragraph is referenced by the order item.
    $referenced_paragraphs = $order_item->get('field_ticket_holder')->referencedEntities();
    $is_referenced = FALSE;
    foreach ($referenced_paragraphs as $ref_para) {
      if ($ref_para->id() === $paragraph->id()) {
        $is_referenced = TRUE;
        break;
      }
    }

    if (!$is_referenced) {
      $this->logger->error(
        'Integrity check failed: attendee paragraph @pid is not referenced by order item @item_id.',
        [
          '@pid' => $paragraph->id(),
          '@item_id' => $order_item->id(),
        ]
      );
    }
  }

  /**
   * Creates a holder paragraph with cloned question templates.
   */
  private function createHolderWithQuestions(array $templates): ParagraphInterface {
    $holder = Paragraph::create(['type' => 'attendee_answer']);

    $clones = [];
    foreach ($templates as $template) {
      $clone = $template->createDuplicate();
      // Normalize: ensure field_attendee_extra_field exists and is empty.
      if ($clone->hasField('field_attendee_extra_field')) {
        $clone->set('field_attendee_extra_field', NULL);
      }
      $clone->save();
      $clones[] = $clone;
    }

    if ($holder->hasField('field_attendee_questions') && !empty($clones)) {
      $holder->set('field_attendee_questions', $clones);
    }

    $holder->save();
    $this->logger->notice(
      'TEMP_DEBUG attendee_questions: new_holder created cloned_template_count=@n holder_pid=@hp',
      [
        '@n' => (string) count($clones),
        '@hp' => $holder->id() !== NULL ? (string) $holder->id() : 'new',
      ]
    );
    return $holder;
  }

  /**
   * Clones event question templates onto a holder when the field was empty.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $holder
   *   Holder paragraph (attendee_answer).
   * @param \Drupal\paragraphs\ParagraphInterface[] $templates
   *   Template paragraphs from the event.
   *
   * @return int
   *   Number of template paragraphs cloned in this call.
   */
  private function ensureHolderHasQuestionParagraphs(ParagraphInterface $holder, array $templates): int {
    if (!$holder->hasField('field_attendee_questions') || $templates === []) {
      return 0;
    }
    if (!$holder->get('field_attendee_questions')->isEmpty()) {
      return 0;
    }
    $clones = [];
    foreach ($templates as $template) {
      if (!$template instanceof ParagraphInterface) {
        continue;
      }
      $clone = $template->createDuplicate();
      if ($clone->hasField('field_attendee_extra_field')) {
        $clone->set('field_attendee_extra_field', NULL);
      }
      $clone->save();
      $clones[] = $clone;
    }
    if ($clones === []) {
      return 0;
    }
    $holder->set('field_attendee_questions', $clones);
    return count($clones);
  }

  /**
   * Whether a form value key under order_items[*] is a numeric attendee delta.
   */
  private function isHolderFormDeltaKey(mixed $key): bool {
    return is_int($key) || (is_string($key) && $key !== '' && ctype_digit($key));
  }

  /**
   * Detects whether any ticket item has organiser/ticket-level questions.
   */
  private function hasAnyQuestionTemplates(): bool {
    foreach ($this->order->getItems() as $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }
      if ($this->getExtraQuestionTemplates($order_item) !== []) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Creates hidden minimal holder paragraphs from the checkout contact pane.
   */
  private function saveMinimalTicketHoldersFromBuyerDetails(FormStateInterface $form_state): void {
    $buyer_values = $form_state->getValue('mel_buyer_details') ?? [];
    if (!is_array($buyer_values)) {
      $buyer_values = [];
    }

    $email = trim((string) ($buyer_values['email'] ?? $this->order->getEmail()));
    $first_name = trim((string) ($buyer_values['first_name'] ?? ''));
    $last_name = trim((string) ($buyer_values['last_name'] ?? ''));
    $phone = trim((string) ($buyer_values['mobile'] ?? ''));

    if (($first_name === '' || $last_name === '') && $this->order->getBillingProfile()) {
      $address = $this->order->getBillingProfile()->get('address')->first();
      if ($address) {
        $first_name = $first_name !== '' ? $first_name : trim((string) $address->getGivenName());
        $last_name = $last_name !== '' ? $last_name : trim((string) $address->getFamilyName());
      }
    }

    if ($email === '' || $first_name === '' || $last_name === '') {
      $this->logger->error(
        'Unable to create minimal ticket holder data for order @order because buyer details were incomplete.',
        ['@order' => (string) $this->order->id()]
      );
      return;
    }

    foreach ($this->order->getItems() as $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }

      $quantity = (int) $order_item->getQuantity();
      if ($quantity < 1) {
        continue;
      }

      $holders = $order_item->get('field_ticket_holder')->referencedEntities();
      $updated_holders = [];
      for ($delta = 0; $delta < $quantity; $delta++) {
        $holder = $holders[$delta] ?? NULL;
        if (!$holder instanceof ParagraphInterface) {
          $holder = Paragraph::create(['type' => 'attendee_answer']);
        }

        $holder->set('field_first_name', $first_name);
        $holder->set('field_last_name', $last_name);
        $holder->set('field_email', $email);
        if ($holder->hasField('field_phone')) {
          $holder->set('field_phone', $phone);
        }
        $holder->save();
        $updated_holders[] = $holder;
      }

      $order_item->set('field_ticket_holder', $updated_holders);
      $order_item->save();
      $this->logger->info('Saved @count minimal ticket holder(s) for order item @id.', [
        '@count' => count($updated_holders),
        '@id' => $order_item->id(),
      ]);
    }
  }

  /**
   * Resolves the event node for an order item (event questions + tier lookup).
   */
  private function resolveEventForOrderItem(OrderItemInterface $order_item): ?FieldableEntityInterface {
    $event = NULL;
    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $event = $order_item->get('field_target_event')->entity;
    }

    $purchased_entity = $order_item->getPurchasedEntity();

    if (
      !$event
      && $purchased_entity instanceof FieldableEntityInterface
      && $purchased_entity->hasField('field_event')
      && !$purchased_entity->get('field_event')->isEmpty()
    ) {
      $event = $purchased_entity->get('field_event')->entity;
    }

    if (!$event && $purchased_entity && method_exists($purchased_entity, 'getProduct')) {
      $product = $purchased_entity->getProduct();
      if (
        $product instanceof FieldableEntityInterface
        && $product->hasField('field_event')
        && !$product->get('field_event')->isEmpty()
      ) {
        $event = $product->get('field_event')->entity;
      }
    }

    return $event instanceof FieldableEntityInterface ? $event : NULL;
  }

  /**
   * Event-level questions followed by ticket-tier questions, deduped.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   */
  private function mergeQuestionTemplateParagraphs(array $event_templates, array $tier_templates): array {
    $seen = [];
    $out = [];
    foreach ([$event_templates, $tier_templates] as $batch) {
      foreach ($batch as $paragraph) {
        if (!$paragraph instanceof ParagraphInterface) {
          continue;
        }
        $key = $this->questionTemplateDedupeKey($paragraph);
        if (isset($seen[$key])) {
          continue;
        }
        $seen[$key] = TRUE;
        $out[] = $paragraph;
      }
    }
    return $out;
  }

  private function questionTemplateDedupeKey(ParagraphInterface $paragraph): string {
    if ($paragraph->hasField('field_question_machine_name') && !$paragraph->get('field_question_machine_name')->isEmpty()) {
      $machine = trim((string) ($paragraph->get('field_question_machine_name')->value ?? ''));
      if ($machine !== '') {
        return 'machine:' . mb_strtolower($machine);
      }
    }
    if ($paragraph->hasField('field_question_label') && !$paragraph->get('field_question_label')->isEmpty()) {
      $label = trim((string) ($paragraph->get('field_question_label')->value ?? ''));
      if ($label !== '') {
        return 'label:' . mb_strtolower($label);
      }
    }
    if ($paragraph->id() !== NULL) {
      return 'id:' . (string) $paragraph->id();
    }
    return 'tmp:' . spl_object_id($paragraph);
  }

  /**
   * Loads merged attendee question templates (event + per-ticket type).
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   *   Template paragraphs in order: event first, then ticket-specific.
   */
  private function getExtraQuestionTemplates(OrderItemInterface $order_item): array {
    $event = $this->resolveEventForOrderItem($order_item);
    if (!$event instanceof FieldableEntityInterface) {
      $purchased_entity = $order_item->getPurchasedEntity();
      $this->logger->warning(
        'Unable to resolve event for order item @id when building attendee questions. Purchased entity type: @type.',
        [
          '@id' => (string) $order_item->id(),
          '@type' => $purchased_entity ? $purchased_entity->getEntityTypeId() : 'none',
        ]
      );
      return [];
    }

    $event_templates = [];
    if ($event->hasField('field_attendee_questions') && !$event->get('field_attendee_questions')->isEmpty()) {
      $event_templates = $event->get('field_attendee_questions')->referencedEntities();
    }

    $tier_templates = [];
    $variation = $order_item->getPurchasedEntity();
    if ($variation instanceof ProductVariationInterface && $event instanceof NodeInterface) {
      $tier = $this->ticketAvailability->resolveTierForVariation($event, $variation);
      if ($tier !== NULL
        && $tier->hasField('field_use_ticket_attendee_questions')
        && $tier->get('field_use_ticket_attendee_questions')->value
        && $tier->hasField('field_attendee_questions')
        && !$tier->get('field_attendee_questions')->isEmpty()) {
        $tier_templates = $tier->get('field_attendee_questions')->referencedEntities();
      }
    }

    return $this->mergeQuestionTemplateParagraphs($event_templates, $tier_templates);
  }

  /**
   * Determines whether an order item should collect ticket holder fields.
   */
  private function shouldCollectTicketHolders(OrderItemInterface $order_item): bool {
    if (!$order_item->hasField('field_ticket_holder')) {
      return FALSE;
    }

    if ($this->isProSubscriptionOrderItem($order_item)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Detects Pro subscription line items that must skip attendee collection.
   */
  private function isProSubscriptionOrderItem(OrderItemInterface $order_item): bool {
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity === NULL) {
      return FALSE;
    }

    if (method_exists($purchased_entity, 'bundle') && $purchased_entity->bundle() === 'mel_pro_subscription_variation') {
      return TRUE;
    }

    if (method_exists($purchased_entity, 'getSku')) {
      $sku = strtolower(trim((string) $purchased_entity->getSku()));
      if ($sku !== '' && str_starts_with($sku, 'mel-pro')) {
        return TRUE;
      }
    }

    if (method_exists($purchased_entity, 'getProduct')) {
      $product = $purchased_entity->getProduct();
      if ($product !== NULL && method_exists($product, 'bundle') && $product->bundle() === 'mel_pro_subscription_product') {
        return TRUE;
      }
    }

    return FALSE;
  }

}
