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
      '#title' => $this->t('Attendee @num', ['@num' => $delta + 1]),
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
      $label = $question->hasField('field_question_label')
        ? (string) ($question->get('field_question_label')->value ?? '')
        : '';
      if ($label === '') {
        $label = 'Extra Question';
      }
      $type = $question->hasField('field_question_type')
        ? (string) ($question->get('field_question_type')->value ?? 'text')
        : 'text';
      $required = $question->hasField('field_question_required')
        && (bool) ($question->get('field_question_required')->value ?? FALSE);
      $field_name = "extra_{$itemIndex}_{$delta}_{$q_index}";

      // Normalize: always read from field_attendee_extra_field.
      $default = '';
      if ($question->hasField('field_attendee_extra_field') && !$question->get('field_attendee_extra_field')->isEmpty()) {
        $default = $question->get('field_attendee_extra_field')->value ?? '';
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
            '#default_value' => is_scalar($default) || $default === NULL ? (string) ($default ?? '') : '',
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
    $pane_values = $form_state->getValue($this->getPluginId()) ?? [];
    $order_items = $pane_values['order_items'] ?? [];
    if (!is_array($order_items)) {
      return;
    }

    foreach ($order_items as $index => $tickets) {
      if (!is_array($tickets)) {
        continue;
      }
      foreach ($tickets as $delta => $entry) {
        if (!$this->isHolderFormDeltaKey($delta) || !is_array($entry)) {
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
