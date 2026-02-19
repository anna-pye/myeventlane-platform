<?php

namespace Drupal\myeventlane_rsvp\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_donations\Service\RsvpDonationService;
use Drupal\myeventlane_event_attendees\Service\AttendanceManager;
use Drupal\myeventlane_rsvp\Service\RsvpMailer;
use Drupal\myeventlane_rsvp\Service\RsvpSubmissionManager;
use Drupal\node\NodeInterface;
use Egulias\EmailValidator\EmailValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public RSVP form for MyEventLane.
 */
final class RsvpPublicForm extends FormBase {

  use StringTranslationTrait;

  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Must NOT have type because FormBase defines this untyped.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  protected LoggerInterface $logger;

  protected MessengerInterface $messengerService;

  protected EmailValidator $emailValidator;

  protected RsvpSubmissionManager $submissionManager;

  protected RsvpMailer $mailer;

  /**
   * Optional donation service (module may be disabled).
   */
  protected ?RsvpDonationService $rsvpDonationService = NULL;

  /**
   * Optional attendance manager service (module may be disabled).
   */
  protected ?AttendanceManager $attendanceManager = NULL;

  /**
   * Module handler for vendor/store lookups.
   */
  protected ModuleHandlerInterface $moduleHandler;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RouteMatchInterface $route_match,
    LoggerInterface $logger,
    MessengerInterface $messenger,
    EmailValidator $email_validator,
    RsvpSubmissionManager $submission_manager,
    RsvpMailer $mailer,
    ModuleHandlerInterface $module_handler,
    ?RsvpDonationService $rsvp_donation_service = NULL,
    ?AttendanceManager $attendance_manager = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->logger = $logger;
    $this->messengerService = $messenger;
    $this->emailValidator = $email_validator;
    $this->submissionManager = $submission_manager;
    $this->mailer = $mailer;
    $this->moduleHandler = $module_handler;
    $this->rsvpDonationService = $rsvp_donation_service;
    $this->attendanceManager = $attendance_manager;
  }

  public static function create(ContainerInterface $container): static {
    $donation_service = $container->has('myeventlane_donations.rsvp')
      ? $container->get('myeventlane_donations.rsvp')
      : NULL;

    $attendance_manager = $container->has('myeventlane_event_attendees.manager')
      ? $container->get('myeventlane_event_attendees.manager')
      : NULL;

    $instance = new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('logger.factory')->get('myeventlane_rsvp'),
      $container->get('messenger'),
      $container->get('email.validator'),
      $container->get('myeventlane_rsvp.submission_manager'),
      $container->get('myeventlane_rsvp.mailer'),
      $container->get('module_handler'),
      $donation_service,
      $attendance_manager,
    );
    $instance->setConfigFactory($container->get('config.factory'));
    return $instance;
  }

  public function getFormId(): string {
    return 'myeventlane_rsvp_public_form';
  }

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

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $event = NULL): array {
    $event = $event ?: $this->getEventFromRoute();
    $event_id = $event instanceof NodeInterface ? $event->id() : NULL;

    $form['#attributes']['class'][] = 'mel-rsvp-form';
    $form['#attributes']['class'][] = 'mel-rsvp-public-form';

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => $event_id,
    ];

    if (!$event_id) {
      $this->logger->warning('RSVP form built without a valid event.');
      $form['message'] = [
        '#markup' => $this->t('Event not found.'),
      ];
      return $form;
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
    ];

    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone (optional)'),
      '#required' => FALSE,
    ];

    $form['guests'] = [
      '#type' => 'number',
      '#title' => $this->t('Guests'),
      '#min' => 1,
      '#default_value' => 1,
      '#required' => TRUE,
    ];

    // Donation section: rich UI when donations enabled and vendor can receive, else simple field.
    $this->buildDonationSection($form, $event);

    // Accessibility needs (optional).
    $options = $this->getAccessibilityOptions();
    if (!empty($options)) {
      $form['accessibility_needs'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Accessibility needs (optional)'),
        '#options' => $options,
        '#required' => FALSE,
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('RSVP'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $email = trim((string) $form_state->getValue('email'));
    if ($email === '') {
      $form_state->setErrorByName('email', $this->t('Email is required.'));
      return;
    }

    // Donation: rich section (toggle + preset) or simple number field.
    $donationAmount = 0.0;
    $donationToggle = $form_state->getValue('donation_toggle');
    if ($donationToggle && $this->moduleHandler->moduleExists('myeventlane_donations')) {
      $donationConfig = $this->config('myeventlane_donations.settings');
      $minAmount = (float) ($donationConfig->get('min_amount') ?? 1.00);
      $preset = $form_state->getValue('donation_preset');
      $customAmount = $form_state->getValue('donation_custom');

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
    }
    else {
      $donationAmount = (float) ($form_state->getValue('donation_amount') ?? 0);
      if ($donationAmount < 0) {
        $form_state->setErrorByName('donation_amount', $this->t('Donation must be 0 or greater.'));
      }
    }
    $form_state->set('donation_amount', $donationAmount);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $event_id = $values['event_id'] ?? NULL;

    if (!$event_id) {
      $this->logger->error('RSVP submission missing event_id.');
      $this->messengerService->addError($this->t('Event not found. Please try again.'));
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($event_id);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $this->logger->error('Invalid event ID @id.', ['@id' => $event_id]);
      $this->messengerService->addError($this->t('Event not found. Please try again.'));
      return;
    }

    $eventId = (int) $event->id();
    $donationAmount = (float) ($form_state->get('donation_amount') ?? 0);

    try {
      $capacity = $event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()
        ? (int) $event->get('field_capacity')->value
        : NULL;

      // IMPORTANT: submission manager now creates pending_payment when donation > 0.
      $submission = $this->submissionManager->submitOrUpdate($event, [
        'name' => $values['name'] ?? '',
        'email' => $values['email'] ?? '',
        'phone' => $values['phone'] ?? '',
        'guests' => (int) ($values['guests'] ?? 1),
        'donation' => $donationAmount,
      ], $capacity);

      $submissionId = (int) $submission->id();
    }
    catch (CapacityExceededException $e) {
      $this->messengerService->addError($this->t('This event is full. Join the waitlist?'));
      return;
    }
    catch (\RuntimeException $e) {
      $this->messengerService->addError($e->getMessage());
      return;
    }

    // Donation path: go to checkout first, then confirm on Thank You page.
    if ($donationAmount > 0) {
      if (!$this->rsvpDonationService) {
        $this->logger->error('Donation requested but myeventlane_donations.rsvp service is not available.', [
          'event_id' => $eventId,
          'submission_id' => $submissionId,
        ]);
        $this->messengerService->addWarning($this->t('Your RSVP was saved, but donations are temporarily unavailable.'));
        // Flip to confirmed because we cannot take payment.
        if ($submission->hasField('status')) {
          $submission->set('status', 'confirmed');
          $submission->save();
        }
      }
      else {
        try {
          $order = $this->rsvpDonationService->createDonationOrder($submission, $event, $donationAmount);
          if ($order) {
            // RSVP is already confirmed (SubmissionManager). Send confirmation now;
            // donation checkout is optional—if completed, thank-you will show receipt.
            try {
              $this->mailer->sendConfirmation($submission, $event);
            }
            catch (\Throwable $e) {
              $this->logger->warning('RSVP confirmation email failed before checkout redirect: @message', [
                '@message' => $e->getMessage(),
              ]);
            }

            $this->messengerService->addStatus($this->t('Reserved for @event. Your RSVP is confirmed. Complete your optional donation below—or close this page; either way you are all set.', [
              '@event' => $event->label(),
            ]));

            // Build thank-you destination that will show receipt if donation completed.
            $thank_you_url = Url::fromRoute('myeventlane_rsvp.thankyou', [
              'event' => $event->id(),
            ], [
              'query' => [
                'order' => $order->id(),
              ],
              'absolute' => FALSE,
            ])->toString();

            // Redirect to checkout for optional donation.
            $form_state->setRedirect('commerce_checkout.form', [
              'commerce_order' => $order->id(),
              'step' => 'checkout',
            ], [
              'query' => [
                'destination' => $thank_you_url,
              ],
            ]);

            return;
          }

          $this->logger->warning('RSVP donation order creation returned NULL for event @event_id, submission @submission_id, amount @amount', [
            '@event_id' => $eventId,
            '@submission_id' => $submissionId,
            '@amount' => $donationAmount,
          ]);
          $this->messengerService->addWarning($this->t('Your RSVP was saved, but we could not process your donation.'));
          // Flip to confirmed because payment cannot proceed.
          if ($submission->hasField('status')) {
            $submission->set('status', 'confirmed');
            $submission->save();
          }
        }
        catch (\Throwable $e) {
          $this->logger->error('Failed to process RSVP donation: @message', [
            '@message' => $e->getMessage(),
            'event_id' => $eventId,
            'submission_id' => $submissionId,
          ]);
          $this->messengerService->addWarning($this->t('Your RSVP was saved, but we could not process your donation.'));
          // Flip to confirmed because payment cannot proceed.
          if ($submission->hasField('status')) {
            $submission->set('status', 'confirmed');
            $submission->save();
          }
        }
      }

      // If donation failed and we flipped to confirmed, continue to normal confirmation.
      // (Email + thank you).
    }

    // Optional: Save accessibility needs only when RSVP is confirmed (free path or fallback).
    $accessibilityNeeds = $values['accessibility_needs'] ?? [];
    if (!empty($accessibilityNeeds) && $this->attendanceManager) {
      $accessibilityNeeds = array_filter($accessibilityNeeds, fn($value) => $value !== 0 && $value !== FALSE && $value !== '');
      if (!empty($accessibilityNeeds)) {
        try {
          $attendeeData = [
            'name' => $values['name'] ?? '',
            'email' => $values['email'] ?? '',
            'status' => 'confirmed',
            'accessibility_needs' => array_values($accessibilityNeeds),
          ];
          $this->attendanceManager->createAttendance($event, $attendeeData, 'rsvp');
        }
        catch (\Throwable $e) {
          $this->logger->warning('Could not save accessibility needs for RSVP: @message', [
            '@message' => $e->getMessage(),
            'event_id' => $eventId,
          ]);
        }
      }
    }

    // Send confirmation email (free RSVP or donation fallback only).
    try {
      $this->mailer->sendConfirmation($submission, $event);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not send RSVP confirmation email: @message', [
        '@message' => $e->getMessage(),
        'event_id' => $eventId,
      ]);
    }

    $this->messengerService->addStatus($this->t('Reserved for @event. You will receive an email shortly.', [
      '@event' => $event->label(),
    ]));

    // Redirect to thank you.
    try {
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_rsvp.thankyou', [
        'event' => $event->id(),
      ]));
    }
    catch (\Throwable) {
      $form_state->setRedirect('entity.node.canonical', ['node' => $event->id()]);
    }
  }

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
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Builds donation section: rich UI when donations enabled and vendor can receive, else simple field.
   */
  protected function buildDonationSection(array &$form, NodeInterface $event): void {
    $showRich = FALSE;
    if ($this->moduleHandler->moduleExists('myeventlane_donations')) {
      try {
        $donationConfig = $this->config('myeventlane_donations.settings');
        $donationEnabled = (bool) ($donationConfig->get('enable_rsvp_donations') ?? FALSE);
        $requireStripeConnected = (bool) ($donationConfig->get('require_stripe_connected_for_attendee_donations') ?? TRUE);

        if ($donationEnabled) {
          $showRich = TRUE;
          if ($requireStripeConnected) {
            $showRich = $this->isVendorStripeConnected($event);
          }
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not load donation config: @message', ['@message' => $e->getMessage()]);
      }
    }

    if ($showRich) {
      $donationConfig = $this->config('myeventlane_donations.settings');
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

      $form['donation_amount'] = [
        '#type' => 'hidden',
        '#default_value' => 0,
      ];

      $form['#attached']['library'][] = 'myeventlane_donations/donation-form';
      $form['#attached']['library'][] = 'myeventlane_donations/donation-rsvp';
    }
    else {
      $form['donation_amount'] = [
        '#type' => 'number',
        '#title' => $this->t('Optional donation (AUD)'),
        '#description' => $this->t('Help support the event with a donation.'),
        '#min' => 0,
        '#step' => 1,
        '#default_value' => 0,
        '#required' => FALSE,
      ];
    }
  }

  /**
   * Checks if the vendor for an event has Stripe Connect enabled.
   */
  protected function isVendorStripeConnected(NodeInterface $event): bool {
    try {
      $vendorUid = (int) $event->getOwnerId();
      if ($vendorUid === 0) {
        return FALSE;
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
          $vendor = $vendorStorage->load(reset($vendors));
          if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
            $store = $vendor->get('field_vendor_store')->entity;
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

      if (!$store) {
        return FALSE;
      }

      if ($store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
        return (bool) $store->get('field_stripe_charges_enabled')->value;
      }
      if ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
        return (bool) $store->get('field_stripe_connected')->value;
      }

      return FALSE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}