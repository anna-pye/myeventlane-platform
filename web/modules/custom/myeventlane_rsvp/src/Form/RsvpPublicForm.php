<?php

namespace Drupal\myeventlane_rsvp\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_donations\Service\RsvpDonationService;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_event_attendees\Service\AttendanceManager;
use Drupal\myeventlane_event_attendees\Service\EventAttendeeQuestionCaptureService;
use Drupal\myeventlane_event_attendees\Service\VendorAttendeePresentationService;
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
   * Event attendee question templates (RSVP tiered path).
   */
  protected EventAttendeeQuestionCaptureService $questionCapture;

  /**
   * Vendor parity / RSVP debug logging for attendee questions.
   */
  protected VendorAttendeePresentationService $vendorAttendeePresentation;

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
    EventAttendeeQuestionCaptureService $question_capture,
    VendorAttendeePresentationService $vendor_attendee_presentation,
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
    $this->questionCapture = $question_capture;
    $this->vendorAttendeePresentation = $vendor_attendee_presentation;
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
      $container->get('myeventlane_event_attendees.rsvp_question_capture'),
      $container->get('myeventlane_event_attendees.vendor_presentation'),
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

    $guest_default = $this->resolveGuestCount($form_state);

    $form['intent'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-intent', 'mel-card', 'mel-card--intent']],
      '#weight' => -20,
    ];
    $form['intent']['heading'] = [
      '#markup' => '<h2 class="mel-card__title">' . $this->t("You're going 🎉") . '</h2>',
    ];
    $form['intent']['subtext'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Save your spot in under a minute — you’re almost in'),
      '#attributes' => ['class' => ['mel-intent__sub']],
    ];
    $form['people'] = [
      '#prefix' => '<div class="mel-step-label">' . $this->t('Step 1: Who is coming') . '</div>',
      '#type' => 'radios',
      '#title' => $this->t('Who is coming?'),
      '#description' => $this->t('Bring your people — it’s better together'),
      '#options' => [
        1 => $this->t('Just me'),
        2 => $this->t('2 people'),
        3 => $this->t('3 people'),
        4 => $this->t('4 people'),
      ],
      '#default_value' => min(4, $guest_default),
      '#attributes' => ['class' => ['mel-guest-selector', 'mel-chip-group']],
      '#wrapper_attributes' => ['class' => ['mel-card', 'mel-card--intent-choices']],
      '#weight' => -19,
    ];

    $form['details'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-details', 'mel-card', 'mel-card--details']],
      '#weight' => 10,
    ];
    $form['details']['step_label'] = [
      '#markup' => '<div class="mel-step-label">' . $this->t('Step 2: Your details') . '</div>',
      '#weight' => -110,
    ];
    $form['details']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Your details'),
      '#attributes' => ['class' => ['mel-card__title']],
      '#weight' => -100,
    ];

    $form['details']['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone (optional)'),
      '#required' => FALSE,
      '#parents' => ['phone'],
    ];

    $form['details']['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of people'),
      '#min' => 1,
      '#max' => 10,
      '#default_value' => $guest_default,
      '#required' => TRUE,
      '#weight' => 5,
      '#parents' => ['quantity'],
      '#attributes' => ['class' => ['mel-input', 'mel-rsvp-quantity']],
    ];

    $form['details']['attendees_intro'] = [
      '#markup' => '<p class="mel-muted mel-attendees__intro">Bring your people</p>',
      '#weight' => 4,
    ];

    $form['details']['attendees'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-attendees']],
      '#weight' => 6,
    ];

    for ($i = 0; $i < 10; $i++) {
      $card = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-attendee-card'],
          'data-index' => (string) $i,
        ],
      ];

      $card['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $i === 0
          ? $this->t('You 🧑')
          : $this->t('Guest @number 🎟️', ['@number' => $i]),
      ];
      $card['status'] = [
        '#markup' => $i === 0
          ? '<div class="mel-attendee-status">' . $this->t('You’re all set 🎟️') . '</div>'
          : '<div class="mel-attendee-status">' . $this->t('Your friend 🎉') . '</div>',
      ];
      $card['meta'] = [
        '#markup' => '<div class="mel-attendee-meta">🎉 Ticket locked in</div>',
      ];
      if ($i === 0) {
        $card['copy_all'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Use these details for everyone'),
          '#attributes' => ['class' => ['mel-copy-all']],
        ];
      }
      $card['attendee_name_' . $i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#parents' => ['attendees', $i, 'name'],
      ];
      $card['attendee_email_' . $i] = [
        '#type' => 'email',
        '#title' => $this->t('Email'),
        '#parents' => ['attendees', $i, 'email'],
      ];

      $form['details']['attendees'][$i] = $card;
    }

    $attendeeDetailsEnabled = $this->isRsvpAttendeeDetailsEnabled($event);
    $this->vendorAttendeePresentation->logRsvpEventQuestionTemplateCount($event);
    $questionTemplates = $attendeeDetailsEnabled ? $this->questionCapture->getTemplatesFromEvent($event) : [];
    $questionElements = $this->questionCapture->buildFormElements($questionTemplates);
    if ($questionElements !== []) {
      $questionElements['#parents'] = ['mel_rsvp_event_questions'];
      $form['details']['mel_rsvp_event_questions'] = $questionElements;
    }

    // Donation section appears after attendee details and before optional details.
    $this->buildDonationSection($form, $event);

    $form['optional'] = [
      '#type' => 'details',
      '#title' => $this->t('Optional'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['mel-rsvp-optional', 'mel-card', 'mel-card--optional']],
      '#weight' => 30,
    ];

    $form['accessibility'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Accessibility needs (optional)'),
      '#options' => $this->getAccessibilityOptions(),
      '#required' => FALSE,
      '#parents' => ['accessibility_needs'],
      '#attributes' => ['class' => ['mel-chip-group', 'mel-chip-group--tags']],
      '#wrapper_attributes' => ['class' => ['mel-card', 'mel-card--accessibility']],
      '#weight' => 31,
      '#access' => $attendeeDetailsEnabled,
    ];

    // Single legal section for terms + marketing consent.
    $form['legal'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-rsvp-legal', 'mel-card', 'mel-card--legal']],
      '#weight' => 40,
    ];
    $form['legal']['step_label'] = [
      '#markup' => '<div class="mel-step-label">' . $this->t('Step 3: Confirm your spot') . '</div>',
      '#weight' => -40,
    ];
    $form['legal']['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Almost done — just confirm your spot'),
      '#attributes' => ['class' => ['mel-legal__intro']],
      '#weight' => -30,
    ];
    $form['legal']['required_label'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Required to reserve'),
      '#attributes' => ['class' => ['mel-legal__label', 'mel-legal__label--required']],
      '#weight' => -20,
    ];
    $form['legal']['terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the Terms & Privacy Policy'),
      '#required' => TRUE,
      '#default_value' => FALSE,
      '#parents' => ['terms'],
      '#title_display' => 'after',
      '#wrapper_attributes' => ['class' => ['mel-legal__choice', 'mel-legal__choice--required', 'mel-chip-group']],
      '#description' => $this->t('You need this to complete your RSVP.'),
    ];
    $form['legal']['optional_label'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Optional updates'),
      '#attributes' => ['class' => ['mel-legal__label', 'mel-legal__label--optional']],
      '#weight' => 10,
    ];
    $form['legal']['marketing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send me updates (optional)'),
      '#required' => FALSE,
      '#default_value' => FALSE,
      '#parents' => ['marketing'],
      '#title_display' => 'after',
      '#wrapper_attributes' => ['class' => ['mel-legal__choice', 'mel-legal__choice--optional', 'mel-chip-group']],
      '#description' => $this->t('Only occasional event news and updates.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mel-rsvp-cta']],
      '#weight' => 60,
    ];
    $form['actions']['helper'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Secure your spot now — no payment required'),
      '#attributes' => ['class' => ['mel-cta-helper']],
      '#weight' => -10,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('✨ Reserve my spot'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => [
          'mel-btn',
          'mel-btn--primary',
          'mel-btn--xl',
        ],
      ],
    ];

    // Presentation-only library for the RSVP public form.
    $form['#attached']['library'][] = 'myeventlane_theme/myeventlane-global';
    $form['#attached']['library'][] = 'myeventlane_rsvp/rsvp_form';
    $form['#attached']['library'][] = 'myeventlane_rsvp/rsvp-conversion';

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $guestCount = $this->resolveGuestCount($form_state);
    if ($guestCount < 1 || $guestCount > 10) {
      $form_state->setErrorByName('quantity', $this->t('Number of attendees must be between 1 and 10.'));
    }
    $form_state->setValue('quantity', $guestCount);
    $visibleCount = min(10, $guestCount);

    $attendees = $form_state->getValue('attendees');
    $attendees = is_array($attendees) ? $attendees : [];
    for ($i = 0; $i < $visibleCount; $i++) {
      $row = $attendees[$i] ?? [];
      $name = trim((string) ($row['name'] ?? ''));
      $email = trim((string) ($row['email'] ?? ''));

      if ($name === '') {
        $form_state->setErrorByName(sprintf('attendees][%d][name', $i), $this->t('Name is required for attendee @number.', [
          '@number' => $i + 1,
        ]));
      }
      if ($email === '') {
        $form_state->setErrorByName(sprintf('attendees][%d][email', $i), $this->t('Email is required for attendee @number.', [
          '@number' => $i + 1,
        ]));
      }
      elseif (!$this->emailValidator->isValid($email)) {
        $form_state->setErrorByName(sprintf('attendees][%d][email', $i), $this->t('Email is invalid for attendee @number.', [
          '@number' => $i + 1,
        ]));
      }
    }

    $event_id = $form_state->getValue('event_id');
    $event_node = $event_id ? $this->entityTypeManager->getStorage('node')->load($event_id) : NULL;
    if ($event_node instanceof NodeInterface && $this->isRsvpAttendeeDetailsEnabled($event_node)) {
      $templates = $this->questionCapture->getTemplatesFromEvent($event_node);
      if ($templates !== []) {
        $raw = $form_state->getValue('mel_rsvp_event_questions');
        $this->questionCapture->validateRequired($templates, is_array($raw) ? $raw : [], $form_state);
      }
    }

    // Donation: validate only when opt-in is checked.
    $donationAmount = '0.00';
    $donationToggle = (bool) ($this->getNestedOrFlatValue(
      $form_state,
      ['donation_section', 'donation_enabled'],
      'donation_enabled'
    ) ?? FALSE);
    if ($donationToggle && $this->moduleHandler->moduleExists('myeventlane_donations')) {
      $donationConfig = $this->config('myeventlane_donations.settings');
      $minAmount = (float) ($donationConfig->get('min_amount') ?? 1.00);
      $preset = (string) ($this->getNestedOrFlatValue(
        $form_state,
        ['donation_section', 'donation_amounts', 'donation_choice'],
        'donation_choice'
      ) ?? '');
      $customAmount = $this->getNestedOrFlatValue(
        $form_state,
        ['donation_section', 'donation_amounts', 'donation_other'],
        'donation_other'
      );

      if ($preset === 'other') {
        if (empty($customAmount) || (float) $customAmount < $minAmount) {
          $form_state->setErrorByName('donation_other', $this->t('Please enter a donation amount of at least $@min.', [
            '@min' => number_format($minAmount, 2),
          ]));
        }
        else {
          $donationAmount = (float) $customAmount;
        }
      }
      elseif ($preset !== '' && is_numeric($preset)) {
        $donationAmount = (float) $preset;
      }
      else {
        $form_state->setErrorByName('donation_choice', $this->t('Please select a donation amount.'));
      }
      $donationAmount = number_format($donationAmount, 2, '.', '');
    }

    $this->logger->notice('Terms value: @v', ['@v' => print_r($form_state->getValue('terms'), TRUE)]);
    if (!$form_state->getValue('terms')) {
      $form_state->setErrorByName('terms', $this->t('You must agree before continuing.'));
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
    $donationAmount = (string) ($form_state->get('donation_amount') ?? '0.00');
    $guestCount = $this->resolveGuestCount($form_state);
    $attendees = $this->normalizeAttendees($form_state->getValue('attendees'), $guestCount);
    $primaryAttendee = $attendees[0] ?? ['name' => '', 'email' => ''];
    $values['name'] = $primaryAttendee['name'];
    $values['email'] = $primaryAttendee['email'];
    $extraData = ['attendees' => $attendees];
    $this->logger->notice('RSVP submit triggered');
    $this->logger->notice('RSVP submit reached for event @nid', ['@nid' => (string) $event->id()]);

    try {
      $capacity = $event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()
        ? (int) $event->get('field_capacity')->value
        : NULL;

      $legalConsent = [
        'terms' => (bool) $form_state->getValue('terms'),
        'privacy' => (bool) $form_state->getValue('terms'),
        'marketing' => (bool) $form_state->getValue('marketing'),
      ];

      // IMPORTANT: submission manager now creates pending_payment when donation > 0.
      $submission = $this->submissionManager->submitOrUpdate($event, [
        'name' => $values['name'] ?? '',
        'email' => $values['email'] ?? '',
        'phone' => $values['phone'] ?? '',
        'mode' => 'create',
        'quantity' => $guestCount,
        'guests' => $guestCount,
        'extra_data' => $extraData,
        // Store as canonical decimal string. Never use float for currency.
        'donation' => $donationAmount,
      ], $capacity, $legalConsent);

      $submissionId = (int) $submission->id();

      $this->syncVendorMirrorForRsvp($event, $form_state, $values, $attendees);
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
    // $donationAmount is canonical "X.YY" (or empty when no donation path is used).
    // Avoid bccomp(): BCMath may not be installed.
    if ($donationAmount !== '' && $donationAmount !== '0.00') {
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

  /**
   * Mirrors RSVP identity + optional questions/accessibility onto event_attendee.
   *
   * Runs when the event defines attendee question templates and/or the guest
   * selected accessibility options (tiered vendor-console parity).
   */
  private function syncVendorMirrorForRsvp(NodeInterface $event, FormStateInterface $form_state, array $values, array $attendees): void {
    if (!$this->attendanceManager) {
      return;
    }

    $templates = $this->questionCapture->getTemplatesFromEvent($event);
    $rawQuestions = $form_state->getValue('mel_rsvp_event_questions');
    $rawQuestions = is_array($rawQuestions) ? $rawQuestions : [];
    $structured = $templates !== []
      ? $this->questionCapture->buildStructuredExtraData($templates, $rawQuestions)
      : [];

    $accessibilityNeeds = $values['accessibility_needs'] ?? [];
    $accessibilityNeeds = is_array($accessibilityNeeds)
      ? array_filter($accessibilityNeeds, static fn ($value) => $value !== 0 && $value !== FALSE && $value !== '')
      : [];
    $accessibilityNeeds = array_values($accessibilityNeeds);

    foreach ($attendees as $attendee) {
      $attendeeName = trim((string) ($attendee['name'] ?? ''));
      $attendeeEmail = trim((string) ($attendee['email'] ?? ''));
      if ($attendeeName === '' || $attendeeEmail === '') {
        continue;
      }
      try {
        $perAttendeeExtra = $structured;
        $perAttendeeExtra['attendees'] = $attendees;
        $this->attendanceManager->upsertRsvpVendorMirror($event, [
          'name' => $attendeeName,
          'email' => $attendeeEmail,
          'phone' => $values['phone'] ?? '',
          'extra_data' => $perAttendeeExtra,
          'accessibility_needs' => $accessibilityNeeds,
          'status' => EventAttendee::STATUS_CONFIRMED,
        ]);
        if ($templates !== []) {
          $this->vendorAttendeePresentation->logRsvpQuestionsSaved(
            (int) $event->id(),
            count($templates),
            count($structured),
          );
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Could not sync RSVP vendor mirror (event_attendee): @message', [
          '@message' => $e->getMessage(),
          'event_id' => (int) $event->id(),
          'attendee_email' => $attendeeEmail,
        ]);
      }
    }
  }

  protected function getAccessibilityOptions(): array {
    return [
      'auslan' => $this->t('Auslan Interpreter'),
      'sensory' => $this->t('Sensory-friendly event'),
      'wheelchair' => $this->t('Wheelchair Access'),
    ];
  }

  /**
   * Returns a nested submitted value, with flat + raw-input fallback.
   *
   * Some RSVP render paths flatten donation keys in submitted input. Validation
   * must accept both nested and flat field shapes.
   *
   * @param array<string|int> $nestedPath
   *   Nested value path.
   * @param string $flatKey
   *   Flat-key fallback.
   *
   * @return mixed
   *   Retrieved value or NULL when absent.
   */
  private function getNestedOrFlatValue(FormStateInterface $form_state, array $nestedPath, string $flatKey): mixed {
    $nested = $form_state->getValue($nestedPath);
    if ($nested !== NULL) {
      return $nested;
    }

    $flat = $form_state->getValue($flatKey);
    if ($flat !== NULL) {
      return $flat;
    }

    $userInput = $form_state->getUserInput();
    if (!is_array($userInput)) {
      return NULL;
    }

    $nestedInput = NestedArray::getValue($userInput, $nestedPath);
    if ($nestedInput !== NULL) {
      return $nestedInput;
    }

    return $userInput[$flatKey] ?? NULL;
  }

  /**
   * Builds donation section: rich UI when donations enabled and vendor can receive, else simple field.
   */
  protected function buildDonationSection(array &$form, NodeInterface $event): void {
    if (!$this->isRsvpDonationsEnabled($event)) {
      return;
    }

    $donationOptions = $this->getRsvpDonationOptions($event);
    $suggested = $this->getRsvpDonationSuggestedAmount($event);
    $suggestedChoice = in_array($suggested, $donationOptions, TRUE)
      ? $suggested
      : (string) reset($donationOptions);
    $formattedOptions = [];
    foreach ($donationOptions as $amount) {
      $label = '$' . $amount;
      if ($amount === $suggestedChoice) {
        $label = '🔥 Most popular — $' . $amount;
      }
      $formattedOptions[$amount] = $label;
    }
    $formattedOptions['other'] = (string) $this->t('✨ Choose your amount');

    $form['donation'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-donation', 'mel-card', 'mel-card--donation']],
      '#weight' => 29,
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('💛 Optional: Support this event'),
        '#attributes' => ['class' => ['mel-card__title']],
      ],
      'intro' => [
        '#markup' => '<p class="mel-muted">Tap to add a small contribution</p>',
        '#weight' => -10,
      ],
      'donation_enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('💛 Support this event'),
        '#default_value' => FALSE,
        '#parents' => ['donation_enabled'],
        '#title_display' => 'after',
        '#attributes' => [
          'class' => ['mel-donation-toggle', 'mel-donation-card'],
        ],
        '#wrapper_attributes' => ['class' => ['mel-donation__toggle', 'mel-chip-group']],
      ],
      'amount' => [
        '#type' => 'radios',
        '#title' => $this->t('Choose your contribution'),
        '#options' => $formattedOptions,
        '#default_value' => NULL,
        '#required' => FALSE,
        '#parents' => ['donation_choice'],
        '#attributes' => ['class' => ['mel-chip-group', 'mel-chip-group--donation']],
        '#wrapper_attributes' => ['class' => ['mel-donation__amounts']],
        '#states' => [
          'visible' => [
            ':input[name="donation_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ],
      'donation_other' => [
        '#type' => 'number',
        '#title' => $this->t('Other amount'),
        '#required' => FALSE,
        '#min' => 0,
        '#step' => 0.01,
        '#field_prefix' => '$',
        '#parents' => ['donation_other'],
        '#attributes' => ['placeholder' => '0.00'],
        '#states' => [
          'visible' => [
            ':input[name="donation_choice"]' => ['value' => 'other'],
          ],
          'required' => [
            ':input[name="donation_choice"]' => ['value' => 'other'],
          ],
        ],
      ],
    ];
  }

  private function isRsvpDonationsEnabled(NodeInterface $event): bool {
    return $event->hasField('field_enable_donations')
      && !$event->get('field_enable_donations')->isEmpty()
      && (bool) $event->get('field_enable_donations')->value;
  }

  private function getRsvpDonationLabel(NodeInterface $event): string {
    if ($event->hasField('field_donation_label') && !$event->get('field_donation_label')->isEmpty()) {
      $label = trim((string) $event->get('field_donation_label')->value);
      if ($label !== '') {
        return $label;
      }
    }
    return 'Support this event (optional)';
  }

  private function getRsvpDonationSuggestedAmount(NodeInterface $event): string {
    if ($event->hasField('field_donation_suggested_amount') && !$event->get('field_donation_suggested_amount')->isEmpty()) {
      $suggested = trim((string) $event->get('field_donation_suggested_amount')->value);
      if (is_numeric($suggested) && (float) $suggested > 0) {
        return number_format((float) $suggested, 2, '.', '');
      }
    }

    $eventType = '';
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      $eventType = strtolower((string) $event->get('field_event_type')->value);
    }

    $categoryNames = [];
    if ($event->hasField('field_category') && !$event->get('field_category')->isEmpty()) {
      foreach ($event->get('field_category')->referencedEntities() as $term) {
        $name = strtolower(trim((string) $term->label()));
        if ($name !== '') {
          $categoryNames[] = $name;
        }
      }
    }
    $categoryText = implode(' ', $categoryNames);

    if (str_contains($categoryText, 'premium') || str_contains($categoryText, 'business')) {
      return '15.00';
    }
    if (str_contains($categoryText, 'community')
      || str_contains($categoryText, 'lgbtq')
      || str_contains($categoryText, 'grassroots')
    ) {
      return '5.00';
    }

    if ($eventType === 'rsvp') {
      return '5.00';
    }
    if ($eventType === 'paid' || $eventType === 'both') {
      return '10.00';
    }

    return '10.00';
  }

  /**
   * Returns normalized RSVP donation options (canonical decimal strings).
   */
  private function getRsvpDonationOptions(NodeInterface $event): array {
    $normalized = [];
    if ($event->hasField('field_donation_options') && !$event->get('field_donation_options')->isEmpty()) {
      $raw = trim((string) $event->get('field_donation_options')->value);
      if ($raw !== '') {
        $parsed = [];
        $decoded = json_decode($raw, TRUE);
        if (is_array($decoded)) {
          $parsed = $decoded;
        }
        else {
          $parsed = preg_split('/\r\n|\r|\n|,/', $raw) ?: [];
        }

        foreach ($parsed as $candidate) {
          if (!is_numeric((string) $candidate)) {
            continue;
          }
          $amount = (float) $candidate;
          if ($amount <= 0) {
            continue;
          }
          $normalized[] = number_format($amount, 2, '.', '');
        }
      }
    }

    if ($normalized === []) {
      $normalized = ['5.00', '10.00', '25.00', '50.00'];
    }

    $normalized = array_values(array_unique($normalized));
    sort($normalized, SORT_NUMERIC);
    return $normalized;
  }

  private function isRsvpAttendeeDetailsEnabled(NodeInterface $event): bool {
    if ($event->hasField('field_collect_per_ticket') && !$event->get('field_collect_per_ticket')->isEmpty()) {
      return (bool) $event->get('field_collect_per_ticket')->value;
    }
    return FALSE;
  }

  /**
   * Resolves requested RSVP count from form state.
   *
   * Uses quantity as the primary source and falls back to the "people" intent
   * selector when quantity is still defaulted to 1.
   */
  private function resolveGuestCount(FormStateInterface $form_state): int {
    $rawQuantity = $form_state->getValue('quantity');
    $rawPeople = $form_state->getValue('people');

    $quantity = is_numeric($rawQuantity) ? (int) $rawQuantity : NULL;
    $people = is_numeric($rawPeople) ? (int) $rawPeople : NULL;

    if ($quantity === NULL || $quantity < 1) {
      return max(1, min(10, $people ?? 1));
    }

    // UI uses a "people" selector and a quantity input. If JS sync does not
    // run, quantity can stay 1 while people is > 1; honor the explicit intent.
    if ($quantity === 1 && $people !== NULL && $people > 1) {
      $this->logger->warning('RSVP guest-count fallback applied: quantity=@quantity people=@people event_id=@event_id', [
        '@quantity' => (string) $quantity,
        '@people' => (string) $people,
        '@event_id' => (string) ($form_state->getValue('event_id') ?? ''),
      ]);
      return max(1, min(10, $people));
    }

    return max(1, min(10, $quantity));
  }

  /**
   * Normalizes submitted attendee rows for visible attendee cards.
   *
   * @param mixed $rawAttendees
   *   Raw attendees form value.
   *
   * @return array<int, array{name: string, email: string}>
   *   Clean attendees.
   */
  private function normalizeAttendees(mixed $rawAttendees, int $guestCount): array {
    $rows = is_array($rawAttendees) ? $rawAttendees : [];
    $visibleCount = min(10, max(1, $guestCount));
    $normalized = [];
    for ($i = 0; $i < $visibleCount; $i++) {
      $row = is_array($rows[$i] ?? NULL) ? $rows[$i] : [];
      $normalized[] = [
        'name' => trim((string) ($row['name'] ?? '')),
        'email' => trim((string) ($row['email'] ?? '')),
      ];
    }
    return $normalized;
  }

  /**
   * Checks if the vendor for an event has Stripe Connect enabled.
   */
  protected function isVendorStripeConnected(NodeInterface $event): bool {
    try {
      $store = $this->resolveOrganiserCommerceStore($event);
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