<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_attendees\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_event_attendees\Service\AttendanceManager;
use Drupal\myeventlane_event_attendees\Service\AttendanceWaitlistManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Waitlist signup form for events.
 *
 * Allows users to join the waitlist when an event is full.
 */
final class WaitlistSignupForm extends FormBase {

  /**
   * Constructs WaitlistSignupForm.
   */
  public function __construct(
    private readonly AttendanceManager $attendanceManager,
    private readonly AttendanceWaitlistManager $waitlistManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event_attendees.manager'),
      $container->get('myeventlane_event_attendees.waitlist'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_waitlist_signup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node || $node->bundle() !== 'event') {
      throw new \InvalidArgumentException('Event not provided.');
    }

    // Check if event is actually full - if not, redirect to booking.
    $availability = $this->attendanceManager->getAvailability($node);
    if ($availability['available']) {
      $this->messenger()->addStatus($this->t('This event still has available spots.'));
      $form_state->setRedirect('myeventlane_commerce.event_book', ['node' => $node->id()]);
      return $form;
    }

    // Store event for submit handler.
    $form_state->set('event', $node);

    // Check if user is already on waitlist.
    $currentUser = $this->currentUser();
    $isAlreadyWaitlisted = FALSE;

    if ($currentUser->isAuthenticated()) {
      $attendees = $this->attendanceManager->getAttendeesForEvent(
        (int) $node->id(),
        'waitlist'
      );

      foreach ($attendees as $attendee) {
        if ($attendee->getUserId() && (int) $attendee->getUserId() === (int) $currentUser->id()) {
          $isAlreadyWaitlisted = TRUE;
          break;
        }
      }
    }

    if ($isAlreadyWaitlisted) {
      $form['already_waitlisted'] = [
        '#type' => 'markup',
        '#markup' => '<div class="mel-waitlist-message mel-waitlist-message--info"><p>' . $this->t('You are already on the waitlist for this event.') . '</p></div>',
      ];
      return $form;
    }

    $form['#theme'] = 'waitlist_signup_form';
    $form['#event'] = $node;

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p class="mel-waitlist-description">' . $this->t('This event is currently full. Join the waitlist to be notified if spots become available.') . '</p>',
    ];

    // For authenticated users, pre-fill with account info.
    if ($currentUser->isAuthenticated()) {
      $account = $currentUser->getAccount();
      $form['user_info'] = [
        '#type' => 'markup',
        '#markup' => '<p class="mel-waitlist-user-info">' . $this->t('You will be added to the waitlist as @name.', [
          '@name' => $account->getDisplayName(),
        ]) . '</p>',
      ];
      $form['email'] = [
        '#type' => 'value',
        '#value' => $account->getEmail(),
      ];
      $form['name'] = [
        '#type' => 'value',
        '#value' => $account->getDisplayName(),
      ];
    }
    else {
      // Anonymous users need to provide details.
      $form['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Your name'),
        '#required' => TRUE,
        '#attributes' => [
          'placeholder' => $this->t('Enter your full name'),
        ],
      ];

      $form['email'] = [
        '#type' => 'email',
        '#title' => $this->t('Email address'),
        '#required' => TRUE,
        '#description' => $this->t('We will notify you at this email if a spot becomes available.'),
        '#attributes' => [
          'placeholder' => $this->t('your.email@example.com'),
        ],
      ];
    }

    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone number'),
      '#description' => $this->t('Optional. We may contact you via phone if a spot becomes available.'),
      '#required' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Join Waitlist'),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn-primary', 'mel-btn-lg'],
      ],
    ];

    $form['#attached'] = [
      'library' => ['myeventlane_event_attendees/waitlist-signup'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event');
    if (!$event) {
      $form_state->setErrorByName('', $this->t('Event information is missing.'));
      return;
    }

    // Check if email is already on waitlist.
    $email = $form_state->getValue('email');
    if ($email) {
      $attendees = $this->attendanceManager->getAttendeesForEvent(
        (int) $event->id(),
        'waitlist'
      );

      foreach ($attendees as $attendee) {
        if (strtolower($attendee->getEmail()) === strtolower($email)) {
          $form_state->setErrorByName('email', $this->t('This email address is already on the waitlist for this event.'));
          break;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event');
    if (!$event) {
      return;
    }

    $currentUser = $this->currentUser();
    $name = $form_state->getValue('name');
    $email = $form_state->getValue('email');
    $phone = $form_state->getValue('phone');

    // Prepare attendee data.
    $attendeeData = [
      'name' => $name,
      'email' => $email,
      'phone' => $phone,
      'status' => 'waitlist',
    ];

    // Set user ID if authenticated.
    if ($currentUser->isAuthenticated()) {
      $attendeeData['uid'] = $currentUser->id();
    }

    // Create waitlist attendee.
    $attendee = $this->attendanceManager->createAttendance(
      $event,
      $attendeeData,
      'waitlist_signup'
    );

    // Get waitlist position.
    $position = $this->waitlistManager->getWaitlistPosition($attendee);

    $this->messenger()->addStatus($this->t('You have been added to the waitlist. Your position is #@position. We will notify you if a spot becomes available.', [
      '@position' => $position ?? '?',
    ]));

    // Redirect back to event page.
    $form_state->setRedirect('entity.node.canonical', ['node' => $event->id()]);
  }

}
