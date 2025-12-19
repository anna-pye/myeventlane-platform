<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Module\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
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
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MessengerInterface $messenger,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('logger.factory'),
      $container->get('messenger'),
      $container->get('module_handler')
    );
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

    // Optional donation panel (same as RsvpPublicForm).
    $donationConfig = $this->configFactory->get('myeventlane_donations.settings');
    $donationEnabled = $donationConfig->get('enable_rsvp_donations') ?? FALSE;
    $requireStripeConnected = $donationConfig->get('require_stripe_connected_for_attendee_donations') ?? TRUE;

    if ($donationEnabled && $event_id) {
      // Load event to check Stripe Connect status.
      $event = $this->entityTypeManager->getStorage('node')->load($event_id);
      if ($event) {
        // Check if vendor has Stripe Connect if required.
        $showDonation = TRUE;
        if ($requireStripeConnected) {
          $showDonation = $this->isVendorStripeConnected($event);
        }

        if ($showDonation) {
          $form['donation_section'] = [
            '#type' => 'details',
            '#title' => $this->t('Support this event (optional)'),
            '#open' => FALSE,
            '#attributes' => ['class' => ['mel-rsvp-donation-section']],
          ];

          $form['donation_section']['donation_intro'] = [
            '#markup' => '<p class="mel-donation-intro-text">' .
            $this->t($donationConfig->get('attendee_copy') ?? 'Support this event organiser with an optional donation. Your contribution helps make this event possible.') .
            '</p>',
          ];

          $presets = $donationConfig->get('attendee_presets') ?? [5.00, 10.00, 25.00, 50.00];
          $minAmount = (float) ($donationConfig->get('min_amount') ?? 1.00);

          $form['donation_section']['donation_toggle'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Add a donation'),
            '#default_value' => FALSE,
            '#attributes' => ['class' => ['mel-donation-toggle']],
          ];

          $form['donation_section']['donation_amounts'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['mel-donation-amounts']],
            '#states' => [
              'visible' => [
                ':input[name="donation_toggle"]' => ['checked' => TRUE],
              ],
            ],
          ];

          // Preset radio buttons.
          $presetOptions = [];
          foreach ($presets as $preset) {
            $presetOptions[(string) $preset] = '$' . number_format($preset, 2);
          }
          $presetOptions['custom'] = $this->t('Custom amount');

          $form['donation_section']['donation_amounts']['donation_preset'] = [
            '#type' => 'radios',
            '#title' => $this->t('Donation amount'),
            '#options' => $presetOptions,
            '#default_value' => '',
            '#required' => FALSE,
            '#attributes' => ['class' => ['mel-donation-presets']],
          ];

          // Custom amount input.
          $form['donation_section']['donation_amounts']['donation_custom'] = [
            '#type' => 'number',
            '#title' => $this->t('Custom amount (AUD)'),
            '#description' => $this->t('Minimum $@min', ['@min' => number_format($minAmount, 2)]),
            '#required' => FALSE,
            '#min' => $minAmount,
            '#step' => 0.01,
            '#default_value' => '',
            '#field_prefix' => '$',
            '#attributes' => ['class' => ['mel-donation-custom-input']],
            '#states' => [
              'visible' => [
                ':input[name="donation_preset"]' => ['value' => 'custom'],
              ],
              'required' => [
                ':input[name="donation_preset"]' => ['value' => 'custom'],
              ],
            ],
          ];

          // Hidden field to store final donation amount.
          $form['donation_amount'] = [
            '#type' => 'hidden',
            '#default_value' => 0,
          ];

          $form['#attached']['library'][] = 'myeventlane_donations/donation-form';
          $form['#attached']['library'][] = 'myeventlane_donations/donation-rsvp';
        }
      }
    }

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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Validate donation amount if donation toggle is enabled.
    $donationToggle = $form_state->getValue('donation_toggle');
    if ($donationToggle) {
      $donationConfig = $this->configFactory->get('myeventlane_donations.settings');
      $minAmount = (float) ($donationConfig->get('min_amount') ?? 1.00);
      $preset = $form_state->getValue('donation_preset');
      $customAmount = $form_state->getValue('donation_custom');

      $donationAmount = 0;
      if ($preset === 'custom') {
        if (empty($customAmount) || (float) $customAmount < $minAmount) {
          $form_state->setErrorByName('donation_custom', $this->t('Please enter a donation amount of at least $@min.', [
            '@min' => number_format($minAmount, 2),
          ]));
        }
        else {
          $donationAmount = (float) $customAmount;
        }
      }
      elseif (!empty($preset) && $preset !== 'custom') {
        $donationAmount = (float) $preset;
      }
      else {
        $form_state->setErrorByName('donation_preset', $this->t('Please select a donation amount.'));
      }

      $form_state->set('donation_amount', $donationAmount);
    }
    else {
      $form_state->set('donation_amount', 0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $product_id = $values['product_id'];
    $variation_id = $values['variation_id'];
    $event_id = $values['event_id'];
    $donationAmount = $form_state->get('donation_amount') ?? 0;

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

    // Add to cart.
    $store = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
    $cart = $this->cartProvider->getCart('default', $store);

    if (!$cart) {
      $cart = $this->cartProvider->createCart('default', $store);
    }

    $this->cartManager->addOrderItem($cart, $order_item);

    // Process donation payment if amount > 0.
    if ($donationAmount > 0) {
      try {
        if (\Drupal::hasService('myeventlane_donations.rsvp')) {
          $event = $this->entityTypeManager->getStorage('node')->load($event_id);
          if ($event) {
            // Create RSVP submission first (for tracking).
            $rsvpStorage = $this->entityTypeManager->getStorage('rsvp_submission');

            // Anonymous users should store user_id as 0.
            $user_id = $this->currentUser->id() ?: 0;

            $submission = $rsvpStorage->create([
              'event_id' => ['target_id' => $event_id],
              'attendee_name' => ($values['first_name'] ?? '') . ' ' . ($values['last_name'] ?? ''),
              'name' => ($values['first_name'] ?? '') . ' ' . ($values['last_name'] ?? ''),
              'email' => $values['email'] ?? '',
              'phone' => $values['phone'] ?? '',
              'guests' => 1,
              'donation' => (float) $donationAmount,
              'status' => 'confirmed',
              'user_id' => $user_id,
            ]);
            $submission->save();

            // Create donation order.
            $rsvpDonationService = \Drupal::service('myeventlane_donations.rsvp');
            $donationOrder = $rsvpDonationService->createDonationOrder($submission, $event, $donationAmount);
            if ($donationOrder) {
              // Redirect to checkout for donation payment.
              $form_state->setRedirect('commerce_checkout.form', [
                'commerce_order' => $donationOrder->id(),
              ]);
              return;
            }
          }
        }
      }
      catch (\Exception $e) {
        // Log error but don't fail RSVP submission.
        $this->loggerFactory->get('myeventlane_commerce')->error('Failed to process RSVP donation: @message', [
          '@message' => $e->getMessage(),
        ]);
        $this->messenger->addWarning($this->t('Your RSVP was saved, but we could not process your donation. Please contact support.'));
      }
    }

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
  protected function isVendorStripeConnected($event): bool {
    try {
      // Get vendor from event owner.
      $vendorUid = (int) $event->getOwnerId();
      if ($vendorUid === 0) {
        return FALSE;
      }

      $store = NULL;

      // First, try to find store via vendor entity (if vendor module is available).
      if ($this->moduleHandler->moduleExists('myeventlane_vendor')) {
        $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
        $vendors = $vendorStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('field_owner', $vendorUid)
          ->range(0, 1)
          ->execute();

        if (!empty($vendors)) {
          $vendor = $vendorStorage->load(reset($vendors));
          if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
            $store = $vendor->get('field_vendor_store')->entity;
          }
        }
      }

      // Fallback: Find store by owner UID.
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

}
