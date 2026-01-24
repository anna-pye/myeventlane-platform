<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
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
    $instance->logger = $container->get('logger.factory')->get('myeventlane_checkout');
    $instance->emailValidator = $container->get('email.validator');
    $instance->ticketLabelResolver = $container->get('myeventlane_core.ticket_label_resolver');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $pane_form['#tree'] = TRUE;

    $pane_form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-intro']],
      'title' => [
        '#markup' => '<h2>' . $this->t('Enter Your Details') . '</h2>',
      ],
      'desc' => [
        '#markup' => '<p>' . $this->t('Please fill in your information below for each ticket.') . '</p>',
      ],
    ];

    foreach ($this->order->getItems() as $index => $order_item) {
      if (!$order_item->hasField('field_ticket_holder')) {
        $this->logger->warning('Order item @id missing field_ticket_holder.', ['@id' => $order_item->id()]);
        continue;
      }

      $quantity = (int) $order_item->getQuantity();
      $holders = $order_item->get('field_ticket_holder')->referencedEntities();
      $templates = $this->getExtraQuestionTemplates($order_item);

      $ticket_label = $this->ticketLabelResolver->getTicketLabel($order_item);
      $pane_form['order_items'][$index] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Ticket Holder Information for: @title', ['@title' => $ticket_label]),
        '#tree' => TRUE,
      ];

      for ($delta = 0; $delta < $quantity; $delta++) {
        $holder = $holders[$delta] ?? NULL;
        $pane_form['order_items'][$index][$delta] = $this->buildTicketHolderForm($holder, $templates, $index, $delta);
      }
    }

    return $pane_form;
  }

  /**
   * Builds the form elements for a single ticket holder.
   */
  private function buildTicketHolderForm(?ParagraphInterface $holder, array $templates, int $itemIndex, int $delta): array {
    $fieldset = [
      '#type' => 'details',
      '#title' => $this->t('Ticket @num', ['@num' => $delta + 1]),
      '#open' => TRUE,
    ];

    // Required fields: first_name, last_name, email.
    $fieldset['field_first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#default_value' => $holder?->get('field_first_name')->value ?? '',
      '#required' => TRUE,
    ];
    $fieldset['field_last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#default_value' => $holder?->get('field_last_name')->value ?? '',
      '#required' => TRUE,
    ];
    $fieldset['field_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $holder?->get('field_email')->value ?? '',
      '#required' => TRUE,
    ];
    $fieldset['field_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone number'),
      '#default_value' => $holder && $holder->hasField('field_phone') ? ($holder->get('field_phone')->value ?? '') : '',
      '#required' => TRUE,
    ];

    // Dynamic extra questions derived from templates (or existing children).
    $question_sources = ($holder && $holder->hasField('field_attendee_questions') && !$holder->get('field_attendee_questions')->isEmpty())
      ? $holder->get('field_attendee_questions')->referencedEntities()
      : $templates;
    foreach ($question_sources as $q_index => $question) {
      $label = $question->get('field_question_label')->value ?? 'Extra Question';
      $type = $question->get('field_question_type')->value ?? 'text';
      $required = (bool) ($question->get('field_question_required')->value ?? FALSE);
      $field_name = "extra_{$itemIndex}_{$delta}_{$q_index}";

      // Normalize: always read from field_attendee_extra_field.
      $default = '';
      if ($question->hasField('field_attendee_extra_field') && !$question->get('field_attendee_extra_field')->isEmpty()) {
        $default = $question->get('field_attendee_extra_field')->value ?? '';
      }

      $options = [];
      foreach ($question->get('field_question_options')->getValue() ?? [] as $item) {
        $opt = trim($item['value'] ?? '');
        if ($opt !== '') {
          $options[$opt] = $opt;
        }
      }

      switch ($type) {
        case 'select':
          $fieldset[$field_name] = [
            '#type' => 'select',
            '#title' => $label,
            '#options' => $options ?: ['_' => $this->t('No options')],
            '#default_value' => $default,
            '#required' => $required,
          ];
          break;

        case 'checkbox':
          $fieldset[$field_name] = [
            '#type' => 'checkbox',
            '#title' => $label,
            '#default_value' => (bool) $default,
            '#required' => $required,
          ];
          break;

        case 'textarea':
          $fieldset[$field_name] = [
            '#type' => 'textarea',
            '#title' => $label,
            '#rows' => 3,
            '#default_value' => $default,
            '#required' => $required,
          ];
          break;

        default:
          $fieldset[$field_name] = [
            '#type' => 'textfield',
            '#title' => $label,
            '#default_value' => $default,
            '#required' => $required,
          ];
          break;
      }
    }

    return $fieldset;
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $pane_values = $form_state->getValue($this->getPluginId()) ?? [];
    $order_items = $pane_values['order_items'] ?? [];
    if (!is_array($order_items)) {
      return;
    }

    foreach ($order_items as $index => $tickets) {
      foreach ($tickets as $delta => $entry) {
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
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $pane_values = $form_state->getValue($this->getPluginId()) ?? [];
    $order_items_values = $pane_values['order_items'] ?? NULL;

    if (!is_array($order_items_values)) {
      return;
    }

    foreach ($this->order->getItems() as $index => $order_item) {
      if (!$order_item->hasField('field_ticket_holder')) {
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

    // Ensure enough holder paragraphs exist.
    if (count($holders) < $quantity) {
      $templates = $this->getExtraQuestionTemplates($order_item);
      for ($i = count($holders); $i < $quantity; $i++) {
        $holders[] = $this->createHolderWithQuestions($templates);
      }
      $order_item->set('field_ticket_holder', $holders);
    }

    foreach ($holders as $delta => $paragraph) {
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
   * Pulls extra attendee question templates from the event, if present.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   *   List of template paragraphs.
   */
  private function getExtraQuestionTemplates(OrderItemInterface $order_item): array {
    $event = NULL;

    // Preferred: order item points directly to the event.
    // (MyEventLane uses field_target_event in many flows.)
    if ($order_item->hasField('field_target_event') && !$order_item->get('field_target_event')->isEmpty()) {
      $event = $order_item->get('field_target_event')->entity;
    }

    $purchased_entity = $order_item->getPurchasedEntity();

    // Back-compat: some setups store field_event on the purchased entity itself.
    if (
      !$event
      && $purchased_entity instanceof FieldableEntityInterface
      && $purchased_entity->hasField('field_event')
      && !$purchased_entity->get('field_event')->isEmpty()
    ) {
      $event = $purchased_entity->get('field_event')->entity;
    }

    // Common: field_event is stored on the parent product, not on the variation.
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

    if (!$event) {
      $this->logger->warning(
        'Unable to resolve event for order item @id when building attendee questions. Purchased entity type: @type.',
        [
          '@id' => $order_item->id(),
          '@type' => $purchased_entity ? $purchased_entity->getEntityTypeId() : 'none',
        ]
      );
      return [];
    }

    if (!$event instanceof FieldableEntityInterface || !$event->hasField('field_attendee_questions')) {
      $this->logger->warning(
        'Event entity for order item @id does not have field_attendee_questions; skipping extra questions.',
        ['@id' => $order_item->id()]
      );
      return [];
    }

    return $event->get('field_attendee_questions')->referencedEntities();
  }

}
