<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Form;

use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for selecting tickets from multiple variations.
 *
 * This form displays all ticket variations for an event and allows
 * customers to select quantities for each ticket type.
 */
final class TicketSelectionForm extends FormBase {

  /**
   * Constructs TicketSelectionForm.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CartProviderInterface $cartProvider,
    private readonly CartManagerInterface $cartManager,
    private readonly CurrencyFormatter $currencyFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('commerce_price.currency_formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_ticket_selection_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, ?ProductInterface $product = NULL): array {
    if (!$node || !$product) {
      return $form;
    }

    // Store node and product for submit handler.
    $form['#node'] = $node;
    $form['#product'] = $product;

    // Get all published variations.
    $variations = $product->getVariations();
    $published_variations = [];
    foreach ($variations as $variation) {
      if ($variation->isPublished()) {
        $published_variations[] = $variation;
      }
    }

    if (empty($published_variations)) {
      $form['empty'] = [
        '#markup' => $this->t('No tickets available for this event.'),
      ];
      return $form;
    }

    // Build ticket selection matrix.
    $form['tickets'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-selection']],
    // Enable tree structure for nested form values.
      '#tree' => TRUE,
    ];

    // Event title header.
    $form['tickets']['event_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $node->label(),
      '#attributes' => ['class' => ['mel-event-title']],
    ];

    // Get ticket type labels from paragraphs.
    $ticket_type_labels = [];
    if ($node->hasField('field_ticket_types') && !$node->get('field_ticket_types')->isEmpty()) {
      $ticket_type_paragraphs = $node->get('field_ticket_types')->referencedEntities();
      $preset_labels = [
        'full_price' => 'Full Price',
        'concession' => 'Concession',
        'child' => 'Child',
        'member' => 'Member',
        'free' => 'Free',
        'student' => 'Student',
        'senior' => 'Senior',
        'early_bird' => 'Early Bird',
        'vip' => 'VIP',
      ];

      foreach ($ticket_type_paragraphs as $paragraph) {
        $uuid = $paragraph->get('field_ticket_variation_uuid')->value ?? '';
        if (!empty($uuid)) {
          $label_mode = $paragraph->get('field_ticket_label_mode')->value ?? 'preset';
          if ($label_mode === 'custom') {
            $label = $paragraph->get('field_ticket_label_custom')->value ?? '';
          }
          else {
            $preset = $paragraph->get('field_ticket_label_preset')->value ?? '';
            $label = $preset_labels[$preset] ?? ucfirst(str_replace('_', ' ', $preset));
          }
          $ticket_type_labels[$uuid] = $label;
        }
      }
    }

    foreach ($published_variations as $variation) {
      $variation_id = $variation->id();
      $variation_uuid = $variation->uuid();
      $price = $variation->getPrice();
      $price_formatted = $price ? $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode()) : '';

      // Get ticket type label from paragraph mapping, or fall back to variation title.
      $ticket_label = $ticket_type_labels[$variation_uuid] ?? $variation->label();
      // If variation title is just the event title, extract just the ticket type part.
      if (strpos($ticket_label, ' – ') !== FALSE) {
        $parts = explode(' – ', $ticket_label, 2);
        $ticket_label = $parts[1] ?? $ticket_label;
      }

      $form['tickets'][$variation_id] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-ticket-row'],
          'data-variation-id' => $variation_id,
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $ticket_label,
          '#attributes' => ['class' => ['mel-ticket-label']],
        ],
        'price' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $price_formatted,
          '#attributes' => ['class' => ['mel-ticket-price']],
        ],
        'quantity' => [
          '#type' => 'number',
          '#title' => $this->t('Quantity'),
          '#title_display' => 'invisible',
          '#min' => 0,
          '#step' => 1,
          '#default_value' => 0,
          '#attributes' => [
            'class' => ['mel-ticket-quantity'],
            'data-variation-id' => $variation_id,
            'aria-label' => $this->t('Quantity for @label', ['@label' => $ticket_label]),
          ],
        ],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Add to cart'),
        '#attributes' => ['class' => ['button--primary', 'mel-add-to-cart-button']],
      ],
    ];

    // Attach JS/CSS for ticket matrix.
    $form['#attached']['library'][] = 'myeventlane_theme/ticket_matrix';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $tickets = $form_state->getValue('tickets', []);
    $has_quantity = FALSE;
    $total_quantity = 0;

    // Check each variation's quantity field.
    foreach ($tickets as $key => $value) {
      // Skip non-numeric keys (like 'event_title').
      if (!is_numeric($key)) {
        continue;
      }

      // The quantity should be in a nested array structure: tickets[$variation_id]['quantity'].
      if (is_array($value) && isset($value['quantity'])) {
        $quantity = (int) $value['quantity'];
        if ($quantity > 0) {
          $has_quantity = TRUE;
          $total_quantity += $quantity;
        }
      }
      // Fallback: if quantity is directly in the value (shouldn't happen with proper structure).
      elseif (is_numeric($value) && (int) $value > 0) {
        $has_quantity = TRUE;
        $total_quantity += (int) $value;
      }
    }

    if (!$has_quantity) {
      $form_state->setError($form['actions']['submit'], $this->t('Please select at least one ticket.'));
      return;
    }

    // Check capacity.
    $node = $form['#node'];
    if ($node && \Drupal::hasService('myeventlane_capacity.service')) {
      try {
        $capacityService = \Drupal::service('myeventlane_capacity.service');
        $capacityService->assertCanBook($node, $total_quantity);
      }
      catch (CapacityExceededException $e) {
        $form_state->setError($form['actions']['submit'], $e->getMessage());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form['#node'];
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form['#product'];

    $tickets = $form_state->getValue('tickets', []);
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

    // Get the store from the product.
    $stores = $product->getStores();
    if (empty($stores)) {
      $this->messenger()->addError($this->t('No store available for this product.'));
      return;
    }
    $store = reset($stores);

    // Get or create cart.
    $cart = $this->cartProvider->getCart('default', $store)
      ?: $this->cartProvider->createCart('default', $store);

    // Add each selected ticket to cart.
    $added = FALSE;
    foreach ($tickets as $key => $value) {
      // Skip non-numeric keys (like 'event_title').
      if (!is_numeric($key)) {
        continue;
      }

      $variation_id = (int) $key;
      // The quantity should be in a nested array: tickets[variation_id][quantity].
      $quantity = 0;
      if (is_array($value) && isset($value['quantity'])) {
        $quantity = (int) $value['quantity'];
      }

      if ($quantity > 0) {
        /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $variation */
        $variation = $variation_storage->load($variation_id);
        if ($variation && $variation->isPublished()) {
          $order_item = $this->cartManager->addEntity($cart, $variation, $quantity, TRUE);

          // Set the target event on the order item if the field exists.
          if ($order_item && $order_item->hasField('field_target_event')) {
            $order_item->set('field_target_event', ['target_id' => $node->id()]);
            $order_item->save();
          }

          $added = TRUE;
        }
      }
    }

    if ($added) {
      $this->messenger()->addStatus($this->t('Tickets added to cart.'));
      $form_state->setRedirect('commerce_cart.page');
    }
  }

}
