<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_checkout_paragraph\CheckoutAttendeeSchemaInspectorInterface;
use Drupal\myeventlane_checkout_paragraph\Support\AttendeeHolderMissingEvaluator;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\myeventlane_core\MelReadinessHelper;
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
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  private AccountProxyInterface $currentUser;

  /**
   * Canonical attendee question schema for this order surface.
   */
  private CheckoutAttendeeSchemaInspectorInterface $checkoutAttendeeSchema;

  private MelReadinessHelper $readinessHelper;

  private DateFormatterInterface $dateFormatter;

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
    $instance->checkoutAttendeeSchema = $container->get('myeventlane_checkout_paragraph.checkout_attendee_schema');
    $instance->readinessHelper = $container->get('myeventlane_surface.state_readiness_helper');
    $instance->currentUser = $container->get('current_user');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $pane_form['#tree'] = TRUE;

    if (!$this->isVisible()) {
      return $pane_form;
    }

    if (!$this->orderRequiresTicketHolderAttendeeCapture()) {
      $pane_form['#attributes']['class'][] = 'mel-attendee-details-pane';
      $pane_form['#attributes']['class'][] = 'mel-attendee-details-pane--empty';
      $slots = $this->readinessHelper->customerCheckoutTicketHolderMinimalAutoAssignedSlots();
      $pane_form['no_schema_status'] = [
        '#theme' => 'mel_empty_state',
        '#heading' => $slots['heading'],
        '#what_happened' => $slots['what_happened'],
        '#why_empty' => $slots['why_empty'],
        '#next_action' => $slots['next_action'],
        '#heading_element' => 'h3',
        '#cta' => NULL,
        '#illustration' => NULL,
        '#attributes' => [
          'class' => [
            'mel-empty-state',
            'mel-empty-state--checkout',
            'mel-checkout-empty-state--attendee-auto',
          ],
        ],
        '#weight' => -2,
      ];
      $pane_form['no_attendee_questions'] = [
        '#type' => 'hidden',
        '#value' => '1',
      ];
      return $pane_form;
    }

    $has_vendor_questions = $this->checkoutAttendeeSchema->orderHasMergedQuestionTemplates($this->order);
    $intro_title = $has_vendor_questions
      ? (string) $this->t('Questions from the organiser')
      : (string) $this->t('Ticket holder details');
    $intro_body = $has_vendor_questions
      ? (string) $this->t('Answer these for each ticket before you pay.')
      : (string) $this->t('Enter the name and email for each ticket. Phone is optional unless the organiser requires it for check-in.');

    $pane_form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-intro', 'mel-attendees-pane-intro']],
      'title' => [
        '#markup' => '<p class="mel-attendees-pane-intro__title">' . Html::escape($intro_title) . '</p>',
      ],
      'desc' => [
        '#markup' => '<p class="mel-attendees-pane-intro__body">' . Html::escape($intro_body) . '</p>',
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
    $opened_incomplete = FALSE;
    $prev_event_key = NULL;

    foreach ($this->order->getItems() as $index => $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }

      $quantity = (int) $order_item->getQuantity();
      $holders = $order_item->get('field_ticket_holder')->referencedEntities();
      $templates = $this->checkoutAttendeeSchema->getMergedQuestionTemplatesForOrderItem($order_item);

      $ticket_label = $this->ticketLabelResolver->getTicketLabel($order_item);
      $event_id = $this->resolveEventIdFromOrderItem($order_item);
      $event_key = $event_id !== NULL ? (string) $event_id : 'other';

      $group = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-checkout-ticket-group']],
        '#tree' => TRUE,
      ];

      if ($event_key !== $prev_event_key) {
        $group['event_context'] = $this->buildEventContext($event_id);
        $prev_event_key = $event_key;
      }

      $group['ticket_type_header'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-checkout-ticket-group__type-row']],
        'title' => [
          '#markup' => '<h4 class="mel-checkout-ticket-type-title">' . Html::escape($ticket_label) . '</h4>',
        ],
      ];
      if ($quantity > 1) {
        $group['ticket_type_header']['qty'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['mel-checkout-ticket-group__qty'],
            'aria-label' => (string) $this->t('@count tickets of this type', ['@count' => $quantity]),
          ],
          '#markup' => '×' . (string) $quantity,
        ];
      }

      for ($delta = 0; $delta < $quantity; $delta++) {
        $holder = $holders[$delta] ?? NULL;
        $holder_position++;
        $group[$delta] = $this->buildTicketHolderForm(
          $order_item,
          $holder,
          $templates,
          (int) $index,
          $delta,
          $logged_in_defaults,
          $holder_position,
          $holder_total,
          $ticket_label,
          $opened_incomplete,
        );
      }

      $pane_form['order_items'][$index] = $group;
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
  private function buildTicketHolderForm(
    OrderItemInterface $order_item,
    ?ParagraphInterface $holder,
    array $templates,
    int $itemIndex,
    int $delta,
    array $loggedInDefaults,
    int $holderPosition,
    int $holderTotal,
    string $ticketTypeLabel,
    bool &$openedIncomplete,
  ): array {
    $missing = AttendeeHolderMissingEvaluator::missingForHolder(
      $order_item,
      $delta,
      $this->checkoutAttendeeSchema,
      $itemIndex
    );
    $is_complete = $missing === [];
    $open_by_default = !$is_complete && !$openedIncomplete;
    if ($open_by_default) {
      $openedIncomplete = TRUE;
    }
    $status_id = Html::getId('mel-holder-status-' . $itemIndex . '-' . $delta);

    $summary_title = (string) $this->t('Ticket @current of @total — @type', [
      '@current' => $holderPosition,
      '@total' => max(1, $holderTotal),
      '@type' => $ticketTypeLabel,
    ]);

    $fieldset = [
      '#type' => 'details',
      '#title' => $summary_title,
      '#open' => $open_by_default,
      '#attributes' => [
        'class' => array_values(array_unique(array_filter([
          'mel-attendee-card',
          'mel-attendee-details',
          $is_complete ? 'mel-attendee-card--complete' : 'mel-attendee-card--incomplete',
        ]))),
        'data-ticket-position' => (string) $holderPosition,
        'data-ticket-total' => (string) max(1, $holderTotal),
        'data-mel-holder-status' => $is_complete ? 'complete' : 'incomplete',
        'aria-describedby' => $status_id,
      ],
    ];

    $fieldset['holder_status'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $status_id,
        'class' => ['visually-hidden'],
      ],
      'text' => [
        '#markup' => $is_complete
          ? (string) $this->t('This ticket holder entry is complete.')
          : (string) $this->t('Incomplete — required fields or organiser questions are missing.'),
      ],
      '#weight' => -12,
    ];

    $fieldset['status_chip'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => array_values(array_filter([
          'mel-attendee-card__status-chip',
          $is_complete ? 'mel-attendee-card__status-chip--complete' : 'mel-attendee-card__status-chip--incomplete',
        ])),
        'aria-hidden' => 'true',
      ],
      '#markup' => '<span class="mel-attendee-card__status-chip-text">' . ($is_complete
        ? Html::escape((string) $this->t('Complete'))
        : Html::escape((string) $this->t('Incomplete'))) . '</span>',
      '#weight' => -11,
    ];

    $fieldset['identity_heading'] = [
      '#markup' => '<p class="mel-attendee-card__section-label">' . Html::escape((string) $this->t('Ticket holder')) . '</p>',
      '#weight' => -5,
    ];

    $fieldset['copy_buyer_details'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-attendee-copy']],
      'button' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => Html::escape((string) $this->t('Use buyer details')),
        '#attributes' => [
          'type' => 'button',
          'class' => ['mel-attendee-copy__button'],
        ],
      ],
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '',
        '#attributes' => [
          'class' => ['mel-attendee-copy__status', 'visually-hidden'],
          'aria-live' => 'polite',
        ],
      ],
      '#weight' => -4,
    ];

    // Required fields: first_name, last_name, email.
    $fieldset['field_first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#default_value' => $this->defaultIfEmpty($holder?->get('field_first_name')->value ?? '', $loggedInDefaults['first_name'] ?? ''),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['mel-attendee-identity-field'],
        'autocomplete' => 'section-checkout given-name',
        'data-mel-attendee-field' => 'first-name',
      ],
    ];
    $fieldset['field_last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#default_value' => $this->defaultIfEmpty($holder?->get('field_last_name')->value ?? '', $loggedInDefaults['last_name'] ?? ''),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['mel-attendee-identity-field'],
        'autocomplete' => 'section-checkout family-name',
        'data-mel-attendee-field' => 'last-name',
      ],
    ];
    $fieldset['field_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $this->defaultIfEmpty($holder?->get('field_email')->value ?? '', $loggedInDefaults['email'] ?? ''),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['mel-attendee-identity-field'],
        'autocomplete' => 'section-checkout email',
        'data-mel-attendee-field' => 'email',
      ],
    ];
    $fieldset['field_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone number (optional)'),
      '#default_value' => $holder && $holder->hasField('field_phone') ? ($holder->get('field_phone')->value ?? '') : '',
      '#required' => FALSE,
      '#attributes' => [
        'class' => ['mel-attendee-identity-field'],
        'autocomplete' => 'section-checkout tel',
        'inputmode' => 'tel',
        'data-mel-attendee-field' => 'phone',
      ],
      '#wrapper_attributes' => [
        'class' => ['mel-attendee-field--phone-secondary', 'mel-attendee-field--optional-phone'],
        'data-mel-optional-phone' => 'true',
      ],
    ];

    // Dynamic extra questions derived from templates (or existing children).
    $question_sources = ($holder && $holder->hasField('field_attendee_questions') && !$holder->get('field_attendee_questions')->isEmpty())
      ? $holder->get('field_attendee_questions')->referencedEntities()
      : $templates;
    if ($question_sources !== []) {
      $fieldset['questions_heading'] = [
        '#markup' => '<p class="mel-attendee-card__section-label mel-attendee-card__section-label--questions">' . Html::escape((string) $this->t('Organiser questions')) . '</p>',
      ];
    }
    foreach ($question_sources as $q_index => $question) {
      $label = $question->hasField('field_question_label')
        ? (string) ($question->get('field_question_label')->value ?? '')
        : '';
      if ($label === '') {
        $label = (string) $this->t('Additional question');
      }
      $type = $this->questionType($question);
      $required = $this->questionIsRequired($question);
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
          $raw_options = preg_split('/\R/', (string) ($item['value'] ?? '')) ?: [];
          foreach ($raw_options as $raw_option) {
            $opt = trim($raw_option);
            if ($opt !== '') {
              $options[$opt] = $opt;
            }
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
            '#required' => $required,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
            '#description' => $this->questionRequirementDescription($required),
          ];
          break;

        case 'checkbox':
          $fieldset[$field_name] = [
            '#type' => 'checkbox',
            '#title' => $label,
            '#default_value' => (bool) $default,
            '#required' => $required,
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
            '#required' => $required,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
            '#description' => $required
              ? (string) $this->t('Select all that apply. Required.')
              : (string) $this->t('Select all that apply. Optional.'),
          ];
          break;

        case 'radio':
        case 'radios':
          $fieldset[$field_name] = [
            '#type' => 'radios',
            '#title' => $label,
            '#options' => $options ?: ['_' => $this->t('Option')],
            '#default_value' => $default,
            '#required' => $required,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
            '#description' => $this->questionRequirementDescription($required),
          ];
          break;

        case 'textarea':
          $fieldset[$field_name] = [
            '#type' => 'textarea',
            '#title' => $label,
            '#rows' => 3,
            '#default_value' => $default,
            '#required' => $required,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
            '#description' => $this->questionRequirementDescription($required),
          ];
          break;

        case 'number':
          $fieldset[$field_name] = [
            '#type' => 'number',
            '#title' => $label,
            '#default_value' => is_numeric($default) ? $default : '',
            '#required' => $required,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
              'inputmode' => 'decimal',
            ],
            '#description' => $this->questionRequirementDescription($required),
          ];
          break;

        default:
          $fieldset[$field_name] = [
            '#type' => 'textfield',
            '#title' => $label,
            '#default_value' => is_scalar($default) || $default === NULL ? (string) ($default ?? '') : '',
            '#required' => $required,
            '#attributes' => [
              'class' => ['mel-attendee-question-field'],
            ],
            '#description' => $this->questionRequirementDescription($required),
          ];
          break;
      }
    }

    return $fieldset;
  }

  /**
   * Event grouping header (presentation) aligned with grouped order summary.
   */
  private function buildEventContext(?int $event_id): array {
    $labels = $this->readinessHelper->customerCheckoutOrderSummarySurfaceLabels();
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-checkout-attendee-event']],
    ];

    if ($event_id === NULL) {
      $title = (string) ($labels['additional_items_title'] ?? '');
      $build['title'] = [
        '#markup' => '<h3 class="mel-checkout-attendee-event__title">' . Html::escape($title) . '</h3>',
      ];
      return $build;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($event_id);
    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      $meta = $this->formatEventDateLine($node);
      $build['title'] = [
        '#markup' => '<h3 class="mel-checkout-attendee-event__title">' . Html::escape($node->label()) . '</h3>',
      ];
      if ($meta !== '') {
        $build['meta'] = [
          '#markup' => '<p class="mel-checkout-attendee-event__meta">' . Html::escape($meta) . '</p>',
        ];
      }
      $build['#cache']['tags'] = Cache::mergeTags($this->order->getCacheTags(), $node->getCacheTags());
    }
    else {
      $fallback = (string) ($labels['event_fallback_title'] ?? '');
      $build['title'] = [
        '#markup' => '<h3 class="mel-checkout-attendee-event__title">' . Html::escape($fallback) . '</h3>',
      ];
    }

    return $build;
  }

  /**
   * Resolves event node ID from an order item (same rules as grouped summary).
   */
  private function resolveEventIdFromOrderItem(OrderItemInterface $item): ?int {
    if ($item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
      return (int) $item->get('field_target_event')->target_id;
    }
    if (!method_exists($item, 'getPurchasedEntity')) {
      return NULL;
    }
    $purchased = $item->getPurchasedEntity();
    if ($purchased && $purchased->hasField('field_event') && !$purchased->get('field_event')->isEmpty()) {
      return (int) $purchased->get('field_event')->target_id;
    }
    return NULL;
  }

  private function formatEventDateLine(NodeInterface $node): string {
    if (!$node->hasField('field_event_start') || $node->get('field_event_start')->isEmpty()) {
      return '';
    }
    $date = $node->get('field_event_start')->date;
    if (!$date) {
      return '';
    }
    return $this->dateFormatter->format($date->getTimestamp(), 'medium');
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

  private function shouldCollectTicketHolders(OrderItemInterface $order_item): bool {
    return $this->checkoutAttendeeSchema->shouldCollectTicketHolders($order_item);
  }

  private function orderRequiresTicketHolderAttendeeCapture(): bool {
    return $this->checkoutAttendeeSchema->orderRequiresTicketHolderAttendeeCapture($this->order);
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
    if (!$this->orderRequiresTicketHolderAttendeeCapture()) {
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
      $templates = $this->checkoutAttendeeSchema->getMergedQuestionTemplatesForOrderItem($order_item);
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
      $submitted_value = $entry[$field_key] ?? NULL;
      if ($this->questionType($question) === 'number'
        && !$this->isEmptySubmittedAnswer($submitted_value)
        && !is_numeric($submitted_value)) {
        $form_state->setErrorByName(
          "{$this->getPluginId()}][order_items][$itemIndex][$delta][$field_key",
          $this->t('Enter a valid number.')
        );
        continue;
      }
      if (!$this->questionIsRequired($question)) {
        continue;
      }

      if (array_key_exists($field_key, $entry) && !$this->isEmptySubmittedAnswer($submitted_value)) {
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

  private function questionIsRequired(ParagraphInterface $question): bool {
    return $question->hasField('field_question_required')
      && !empty($question->get('field_question_required')->value);
  }

  private function questionType(ParagraphInterface $question): string {
    return $question->hasField('field_question_type')
      ? (string) ($question->get('field_question_type')->value ?? 'textfield')
      : 'textfield';
  }

  private function questionRequirementDescription(bool $required): string {
    return $required ? (string) $this->t('Required.') : (string) $this->t('Optional.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    if (!$this->isVisible()) {
      return;
    }

    if (!$this->orderRequiresTicketHolderAttendeeCapture()) {
      return;
    }

    $pane_values = $form_state->getValue($this->getPluginId()) ?? [];
    $order_items_values = $pane_values['order_items'] ?? NULL;

    if (!$this->ticketHolderPaneSubmittedPayloadIsPresent($order_items_values)) {
      if (!$this->checkoutAttendeeSchema->orderHasMergedQuestionTemplates($this->order)) {
        $this->logger->warning(
          'Ticket holder checkout pane submitted without order_items payload for order @order; applying buyer-derived minimal holders (identity-only schema).',
          ['@order' => (string) $this->order->id()]
        );
        $this->saveMinimalTicketHoldersFromBuyerDetails($form_state);
        return;
      }
      $this->logger->error(
        'Ticket holder checkout pane submitted without order_items payload for order @order while organiser attendee questions exist; cannot auto-fill.',
        ['@order' => (string) $this->order->id()]
      );
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
    $templates = $this->checkoutAttendeeSchema->getMergedQuestionTemplatesForOrderItem($order_item);

    // Ensure enough holder paragraphs exist.
    if (count($holders) < $quantity) {
      for ($i = count($holders); $i < $quantity; $i++) {
        $holders[] = $this->createHolderWithQuestions($templates);
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

      $this->ensureHolderHasQuestionParagraphs($paragraph, $templates);

      // Save extra questions - normalize to field_attendee_extra_field.
      if ($paragraph->hasField('field_attendee_questions')) {
        $children = $paragraph->get('field_attendee_questions')->referencedEntities();
        foreach ($children as $q_index => $child) {
          $field_key = "extra_{$itemIndex}_{$delta}_{$q_index}";
          $value = $entry[$field_key] ?? NULL;
          if ($value !== NULL && $child->hasField('field_attendee_extra_field')) {
            // Normalize: always write to field_attendee_extra_field.
            // Convert arrays (e.g., checkboxes) to JSON string.
            $normalized_value = is_array($value) ? json_encode($value) : (string) $value;
            $child->set('field_attendee_extra_field', $normalized_value);
            $child->save();
          }
        }
      }

      // Integrity check: ensure paragraph has a parent order item reference.
      // This is implicit via field_ticket_holder, but we log if something seems wrong.
      $paragraph->save();

      // Verify the paragraph is still referenced by this order item.
      $order_item->save();
      $this->verifyParagraphAttachment($paragraph, $order_item);

    }

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
   * TRUE when form state includes a nested value per collectible ticket delta.
   *
   * @param mixed $order_items_values
   *   Value at ticket_holder_paragraph][order_items from form state.
   */
  private function ticketHolderPaneSubmittedPayloadIsPresent(mixed $order_items_values): bool {
    if (!is_array($order_items_values)) {
      return FALSE;
    }
    foreach ($this->order->getItems() as $index => $order_item) {
      if (!$this->shouldCollectTicketHolders($order_item)) {
        continue;
      }
      $quantity = (int) $order_item->getQuantity();
      if ($quantity < 1) {
        continue;
      }
      $tickets = $order_items_values[$index] ?? NULL;
      if (!is_array($tickets)) {
        return FALSE;
      }
      for ($delta = 0; $delta < $quantity; $delta++) {
        if (!array_key_exists($delta, $tickets) || !is_array($tickets[$delta])) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Creates ticket holder paragraphs from checkout buyer/contact fields.
   *
   * Used when the order has no vendor attendee question templates but the pane
   * did not return a normal order_items payload (AJAX rebuild edge, regression).
   * Does not replace full Form API submission when order_items is present.
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

}
