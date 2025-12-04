<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * RSVP booking form for free events.
 */
final class RsvpBookingForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_commerce_rsvp_booking_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $product_id = NULL, $variation_id = NULL, $event_id = NULL): array {
    $form['#attributes']['class'][] = 'mel-rsvp-form';

    $form['intro'] = [
      '#markup' => '<p style="margin-bottom: 1.5rem; color: #666;">Please enter your information to RSVP for this event.</p>',
    ];

    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Enter your first name'),
      ],
    ];

    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Enter your last name'),
      ],
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Enter your email'),
      ],
    ];

    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone (optional)'),
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => $this->t('Enter your phone number'),
      ],
    ];

    // Store product/variation info.
    $form['product_id'] = [
      '#type' => 'value',
      '#value' => $product_id,
    ];

    $form['variation_id'] = [
      '#type' => 'value',
      '#value' => $variation_id,
    ];

    $form['event_id'] = [
      '#type' => 'value',
      '#value' => $event_id,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('RSVP Now'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-block'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $product_id = $values['product_id'];
    $variation_id = $values['variation_id'];
    $event_id = $values['event_id'];

    // Load product variation.
    $variation = \Drupal::entityTypeManager()
      ->getStorage('commerce_product_variation')
      ->load($variation_id);
    
    if (!$variation) {
      $form_state->setError($form, $this->t('Product not found.'));
      return;
    }

    // Create order item.
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $order_item_storage */
    $order_item_storage = \Drupal::entityTypeManager()->getStorage('commerce_order_item');
    $order_item = $order_item_storage->createFromPurchasableEntity($variation);
    $order_item->setQuantity(1);

    // Create attendee paragraph with basic info.
    if ($order_item->hasField('field_ticket_holder')) {
      $paragraph = Paragraph::create([
        'type' => 'attendee_answer',
        'field_first_name' => $values['first_name'],
        'field_last_name' => $values['last_name'],
        'field_email' => $values['email'],
        'field_phone' => $values['phone'] ?? '',
      ]);
      $paragraph->save();
      $order_item->set('field_ticket_holder', [$paragraph]);
    }

    // Link to event if field exists.
    if ($order_item->hasField('field_target_event')) {
      $order_item->set('field_target_event', $event_id);
    }

    $order_item->save();

    // Add to cart.
    $cart_manager = \Drupal::service('commerce_cart.cart_manager');
    $cart_provider = \Drupal::service('commerce_cart.cart_provider');
    $store = \Drupal::entityTypeManager()->getStorage('commerce_store')->loadDefault();
    $cart = $cart_provider->getCart('default', $store);
    
    if (!$cart) {
      $cart = $cart_provider->createCart('default', $store);
    }

    $cart_manager->addOrderItem($cart, $order_item);

    // Redirect to checkout.
    $form_state->setRedirect('commerce_checkout.form', ['commerce_order' => $cart->id()]);
  }

}

