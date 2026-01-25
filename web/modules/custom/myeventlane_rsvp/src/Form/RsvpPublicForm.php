<?php

namespace Drupal\myeventlane_rsvp\Form;

use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Egulias\EmailValidator\EmailValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public RSVP form for MyEventLane.
 *
 * This form allows public users to RSVP for free events. It creates an
 * RsvpSubmission entity and optionally sends confirmation emails.
 */
class RsvpPublicForm extends FormBase {

  use StringTranslationTrait;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Must NOT have type because FormBase defines this untyped.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Messenger service.
   */
  protected MessengerInterface $messengerService;

  /**
   * Email validator.
   */
  protected EmailValidator $emailValidator;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
    LoggerInterface $logger,
    MessengerInterface $messenger,
    EmailValidator $email_validator,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->logger = $logger;
    $this->messengerService = $messenger;
    $this->emailValidator = $email_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('logger.factory')->get('myeventlane_rsvp'),
      $container->get('messenger'),
      $container->get('email.validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_rsvp_public_form';
  }

  /**
   * Tries to detect the event from the route.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The event node, or NULL if not found.
   */
  protected function getEventFromRoute(): ?NodeInterface {
    $candidate = $this->routeMatch->getParameter('node');
    if ($candidate instanceof NodeInterface && $candidate->bundle() === 'event') {
      return $candidate;
    }

    $candidate = $this->routeMatch->getParameter('event');
    if ($candidate instanceof NodeInterface && $candidate->bundle() === 'event') {
      return $candidate;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    $event = $event ?: $this->getEventFromRoute();
    $event_id = $event instanceof NodeInterface ? $event->id() : NULL;

    $form['#attributes']['class'][] = 'mel-rsvp-form';
    $form['#attributes']['class'][] = 'mel-rsvp-public-form';

    // Store event ID.
    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => $event_id,
    ];

    if (!$event_id) {
      $this->logger->warning('RSVP form built without event.', [
        'event_id' => NULL,
      ]);
      $form['error'] = [
        '#markup' => '<div class="mel-alert mel-alert--warning">' .
        $this->t('We could not determine the event. Please try again.') .
        '</div>',
      ];
      return $form;
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => [
        'placeholder' => $this->t('Enter your full name'),
        'autocomplete' => 'name',
      ],
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Enter your email address'),
        'autocomplete' => 'email',
      ],
    ];

    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone (optional)'),
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => $this->t('Enter your phone number'),
        'autocomplete' => 'tel',
      ],
    ];

    $form['guests'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of guests'),
      '#description' => $this->t('Including yourself'),
      '#required' => TRUE,
      '#min' => 1,
      '#max' => 10,
      '#default_value' => 1,
    ];

    // Accessibility needs field (optional).
    $accessibility_options = $this->getAccessibilityOptions();
    if (!empty($accessibility_options)) {
      $form['accessibility_needs'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Accessibility needs (optional)'),
        '#description' => $this->t('Let us know if you have any accessibility requirements.'),
        '#options' => $accessibility_options,
        '#required' => FALSE,
      ];
    }

    // Optional donation panel.
    $donationConfig = \Drupal::config('myeventlane_donations.settings');
    $donationEnabled = $donationConfig->get('enable_rsvp_donations') ?? FALSE;
    $requireStripeConnected = $donationConfig->get('require_stripe_connected_for_attendee_donations') ?? TRUE;

    if ($donationEnabled) {
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
        $form['donation'] = [
          '#type' => 'hidden',
          '#default_value' => 0,
        ];

        $form['#attached']['library'][] = 'myeventlane_donations/donation-form';
        $form['#attached']['library'][] = 'myeventlane_donations/donation-rsvp';
      }
      elseif ($requireStripeConnected) {
        // Show disabled state with message.
        $form['donation_section'] = [
          '#type' => 'details',
          '#title' => $this->t('Support this event (optional)'),
          '#open' => FALSE,
          '#attributes' => ['class' => ['mel-rsvp-donation-section', 'mel-rsvp-donation-section--disabled']],
        ];

        $form['donation_section']['donation_disabled'] = [
          '#markup' => '<p class="mel-donation-disabled-message">' .
          $this->t('Donations are not available for this event at this time.') .
          '</p>',
        ];

        $form['donation'] = [
          '#type' => 'hidden',
          '#default_value' => 0,
        ];
      }
    }
    else {
      // Donations disabled globally.
      $form['donation'] = [
        '#type' => 'hidden',
        '#default_value' => 0,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reserve'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg', 'mel-btn-block'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $guests = (int) $form_state->getValue('guests');
    if ($guests < 1) {
      $form_state->setErrorByName('guests', $this->t('Please reserve for at least one guest.'));
    }

    $email = $form_state->getValue('email');
    if (!empty($email) && !$this->emailValidator->isValid($email)) {
      $form_state->setErrorByName('email', $this->t('Please enter a valid email address.'));
    }

    // Check capacity.
    $event = $this->getEventFromRoute();
    if ($event && \Drupal::hasService('myeventlane_capacity.service')) {
      try {
        $capacityService = \Drupal::service('myeventlane_capacity.service');
        $capacityService->assertCanBook($event, $guests);
      }
      catch (CapacityExceededException $e) {
        $form_state->setErrorByName('', $e->getMessage());
      }
    }

    // Validate donation amount if donation toggle is enabled.
    $donationToggle = $form_state->getValue('donation_toggle');
    if ($donationToggle) {
      $donationConfig = \Drupal::config('myeventlane_donations.settings');
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
    $event_id = $values['event_id'] ?? NULL;

    if (!$event_id) {
      $this->logger->error('RSVP submission missing event_id.', [
        'event_id' => NULL,
        'submission_id' => NULL,
      ]);
      $this->messengerService->addError($this->t('Event not found. Please try again.'));
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($event_id);

    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $this->logger->error('Invalid event ID @id.', [
        '@id' => $event_id,
        'event_id' => is_numeric($event_id) ? (int) $event_id : NULL,
        'submission_id' => NULL,
      ]);
      $this->messengerService->addError($this->t('Event not found. Please try again.'));
      return;
    }

    $eventId = (int) $event->id();
    $submissionId = NULL;

    try {
      $storage = $this->entityTypeManager->getStorage('rsvp_submission');

      // Get donation amount from form state.
      $donationAmount = $form_state->get('donation_amount') ?? 0;

      // Create the RSVP submission entity.
      $submission = $storage->create([
        'event_id' => ['target_id' => $event->id()],
        'attendee_name' => $values['name'] ?? '',
        'name' => $values['name'] ?? '',
        'email' => $values['email'] ?? '',
        'phone' => $values['phone'] ?? '',
        'guests' => (int) ($values['guests'] ?? 1),
        'donation' => (float) $donationAmount,
        'status' => 'confirmed',
      // Set to 0 for anonymous users.
        'user_id' => $this->currentUser()->id() ?: 0,
      ]);

      $submission->save();
      $submissionId = (int) $submission->id();

      // Process donation payment if amount > 0.
      if ($donationAmount > 0) {
        try {
          if (\Drupal::hasService('myeventlane_donations.rsvp')) {
            $rsvpDonationService = \Drupal::service('myeventlane_donations.rsvp');
            $order = $rsvpDonationService->createDonationOrder($submission, $event, $donationAmount);
            if ($order) {
              // Store order ID in submission metadata if field exists.
              // Redirect to checkout for payment.
              $form_state->setRedirect('commerce_checkout.form', [
                'commerce_order' => $order->id(),
              ]);
              return;
            }
            else {
              // Order creation returned NULL - log the reason.
              $this->logger->warning('RSVP donation order creation returned NULL for event @event_id, submission @submission_id, amount @amount', [
                '@event_id' => $eventId,
                '@submission_id' => $submissionId,
                '@amount' => $donationAmount,
                'event_id' => $eventId,
                'submission_id' => $submissionId,
              ]);
              $this->messengerService->addWarning($this->t('Reserved, but we could not process your donation. Please contact support.'));
            }
          }
          else {
            $this->logger->error('RSVP donation service not available', [
              'event_id' => $eventId,
              'submission_id' => $submissionId,
            ]);
            $this->messengerService->addWarning($this->t('Reserved, but we could not process your donation. Please contact support.'));
          }
        }
        catch (\Exception $e) {
          // Log error but don't fail RSVP submission.
          $this->logger->error('Failed to process RSVP donation: @message', [
            '@message' => $e->getMessage(),
            '@trace' => $e->getTraceAsString(),
            'event_id' => $eventId,
            'submission_id' => $submissionId,
          ]);
          $this->messengerService->addWarning($this->t('Reserved, but we could not process your donation. Please contact support.'));
        }
      }

      $this->logger->info('RSVP created for event @event_id by @name (@email)', [
        '@event_id' => $eventId,
        '@name' => $values['name'],
        '@email' => $values['email'],
        'event_id' => $eventId,
        'submission_id' => $submissionId,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('RSVP save failed for event @id: @m', [
        '@id' => $eventId,
        '@m' => $e->getMessage(),
        'event_id' => $eventId,
        'submission_id' => $submissionId,
      ]);
      $this->messengerService->addError($this->t('We could not save your RSVP. Please try again.'));
      return;
    }

    // Save accessibility needs if provided and module is available.
    $accessibilityNeeds = $values['accessibility_needs'] ?? [];
    if (!empty($accessibilityNeeds)) {
      // Filter out unchecked values.
      $accessibilityNeeds = array_filter($accessibilityNeeds, function ($value) {
        return $value !== 0 && $value !== FALSE && $value !== '';
      });

      if (!empty($accessibilityNeeds)) {
        try {
          if (\Drupal::hasService('myeventlane_event_attendees.manager')) {
            $attendanceManager = \Drupal::service('myeventlane_event_attendees.manager');
            $attendeeData = [
              'name' => $values['name'] ?? '',
              'email' => $values['email'] ?? '',
              'status' => 'confirmed',
              'accessibility_needs' => array_values($accessibilityNeeds),
            ];
            $attendanceManager->createAttendance($event, $attendeeData, 'rsvp');
          }
        }
        catch (\Exception $e) {
          // Log but don't fail RSVP if attendance manager is unavailable.
          $this->logger->warning('Could not save accessibility needs for RSVP: @message', [
            '@message' => $e->getMessage(),
            'event_id' => $eventId,
            'submission_id' => $submissionId,
          ]);
        }
      }
    }

    // Send confirmation email if mailer service is available.
    try {
      if (\Drupal::hasService('myeventlane_rsvp.mailer')) {
        $mailer = \Drupal::service('myeventlane_rsvp.mailer');
        $mailer->sendConfirmation($submission, $event);
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not send RSVP confirmation email: @message', [
        '@message' => $e->getMessage(),
        'event_id' => $eventId,
        'submission_id' => $submissionId,
      ]);
    }

    $this->messengerService->addStatus(
      $this->t('Reserved for @event. You will receive an email shortly.', [
        '@event' => $event->label(),
      ])
    );

    // Redirect to thank you page or event page.
    $thankYouRoute = 'myeventlane_rsvp.thankyou';
    try {
      $url = Url::fromRoute($thankYouRoute, [
        'event' => $event->id(),
      ]);
      $form_state->setRedirectUrl($url);
    }
    catch (\Exception $e) {
      // Fallback to event page.
      $form_state->setRedirect('entity.node.canonical', ['node' => $event->id()]);
    }
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
      // Get vendor from event owner.
      $vendorUid = (int) $event->getOwnerId();
      if ($vendorUid === 0) {
        return FALSE;
      }

      $store = NULL;

      // First, try to find store via vendor entity (if vendor module is available).
      if (\Drupal::moduleHandler()->moduleExists('myeventlane_vendor')) {
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
        $connected = (bool) $store->get('field_stripe_charges_enabled')->value;
        return $connected;
      }

      // Fallback: check connected flag.
      if ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
        $connected = (bool) $store->get('field_stripe_connected')->value;
        return $connected;
      }

      return FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking Stripe Connect for event @event_id: @message', [
        '@event_id' => $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
