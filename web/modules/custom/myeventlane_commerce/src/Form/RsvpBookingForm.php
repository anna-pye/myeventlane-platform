<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * RSVP booking form for free events.
 */
final class RsvpBookingForm extends FormBase {

  /**
   * Constructs RsvpBookingForm.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CartManagerInterface $cartManager,
    private readonly CartProviderInterface $cartProvider,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get('entity_type.manager'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('module_handler'),
    );
    // Set services provided by FormBase traits.
    $form->setConfigFactory($container->get('config.factory'));
    $form->setLoggerFactory($container->get('logger.factory'));
    $form->setMessenger($container->get('messenger'));
    return $form;
  }

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

    // Accessibility needs field (optional).
    $accessibility_options = $this->getAccessibilityOptions();
    if (!empty($accessibility_options)) {
      $form['accessibility_needs'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Accessibility needs (optional)'),
        '#description' => $this->t('Let us know if you have any accessibility requirements. This helps us ensure the event is accessible for everyone.'),
        '#options' => $accessibility_options,
        '#required' => FALSE,
      ];
    }

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

    // Legacy donation UI is intentionally disabled here.
    // Donation UI is centralized in RsvpPublicForm::buildDonationSection().
    $form_state->set('donation_amount', '0.00');

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('🎉 Reserve my spot'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--primary', 'mel-btn--xl'],
      ],
    ];

    $form['#attached']['library'][] = 'myeventlane_theme/myeventlane-global';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $form_state->set('donation_amount', '0.00');
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
    $variation = $this->entityTypeManager
      ->getStorage('commerce_product_variation')
      ->load($variation_id);

    if (!$variation) {
      $form_state->setError($form, $this->t('Product not found.'));
      return;
    }

    // Create order item.
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $order_item_storage */
    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
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

    // Add to cart — use the product's store (event's vendor store) so orders
    // appear on the correct store. Fall back to default if product has no store.
    $product = $variation->getProduct();
    $stores = $product ? $product->getStores() : [];
    $store = !empty($stores) ? reset($stores) : $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
    if (!$store) {
      $this->messenger()->addError($this->t('No store available for checkout.'));
      return;
    }
    $account = $this->currentUser();
    $cart = $this->cartProvider->getCart('default', $store, $account)
      ?: $this->cartProvider->createCart('default', $store, $account);

    $this->cartManager->addOrderItem($cart, $order_item);

    // Save accessibility needs if provided.
    $accessibilityNeeds = $values['accessibility_needs'] ?? [];
    if (!empty($accessibilityNeeds)) {
      // Filter out unchecked values.
      $accessibilityNeeds = array_filter($accessibilityNeeds, function ($value) {
        return $value !== 0 && $value !== FALSE && $value !== '';
      });

      if (!empty($accessibilityNeeds)) {
        // Store accessibility needs in order item for later processing.
        if ($order_item->hasField('field_attendee_data')) {
          $attendeeData = $order_item->get('field_attendee_data')->getValue();
          if (empty($attendeeData)) {
            $attendeeData = [];
          }
          $attendeeData['accessibility_needs'] = array_values($accessibilityNeeds);
          $order_item->set('field_attendee_data', $attendeeData);
          $order_item->save();
        }
      }
    }

    // Redirect to checkout.
    $form_state->setRedirect('commerce_checkout.form', ['commerce_order' => $cart->id()]);
  }

  /**
   * Gets accessibility taxonomy term options.
   *
   * @return array
   *   Array of term ID => term name.
   */
  protected function getAccessibilityOptions(): array {
    try {
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $terms = $storage->loadByProperties(['vid' => 'accessibility']);
      $options = [];
      foreach ($terms as $term) {
        $options[$term->id()] = $term->label();
      }
      return $options;
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Checks if the vendor for an event has Stripe Connect enabled.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if vendor has Stripe Connect, FALSE otherwise.
   */
  protected function isVendorStripeConnected(NodeInterface $event): bool {
    try {
      $store = $this->resolveOrganiserCommerceStore($event);
      if (!$store) {
        return FALSE;
      }

      // Check if Stripe is connected and charges are enabled.
      if ($store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
        return (bool) $store->get('field_stripe_charges_enabled')->value;
      }

      // Fallback: check connected flag.
      if ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
        return (bool) $store->get('field_stripe_connected')->value;
      }

      return FALSE;
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * Commerce store for the organiser (field_event_vendor if set, else author).
   */
  private function resolveOrganiserCommerceStore(NodeInterface $event): ?StoreInterface {
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
        $candidate = $vendor->get('field_vendor_store')->entity;
        if ($candidate instanceof StoreInterface) {
          return $candidate;
        }
      }
    }

    $vendorUid = (int) $event->getOwnerId();
    if ($vendorUid === 0) {
      return NULL;
    }

    $store = NULL;

    if ($this->moduleHandler->moduleExists('myeventlane_vendor')) {
      $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $vendors = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_owner', $vendorUid)
        ->range(0, 1)
        ->execute();

      if (!empty($vendors)) {
        $vendorEntity = $vendorStorage->load(reset($vendors));
        if ($vendorEntity && $vendorEntity->hasField('field_vendor_store') && !$vendorEntity->get('field_vendor_store')->isEmpty()) {
          $store = $vendorEntity->get('field_vendor_store')->entity;
        }
      }
    }

    if (!$store) {
      $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
      $storeIds = $storeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $vendorUid)
        ->range(0, 1)
        ->execute();

      if (!empty($storeIds)) {
        $store = $storeStorage->load(reset($storeIds));
      }
    }

    return $store instanceof StoreInterface ? $store : NULL;
  }

}
