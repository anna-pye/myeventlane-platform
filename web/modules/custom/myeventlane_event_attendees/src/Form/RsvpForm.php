<?php

namespace Drupal\myeventlane_event_attendees\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_event_attendees\Service\AttendanceManager;
use Drupal\myeventlane_event_attendees\Service\AttendanceWaitlistManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
final class RsvpForm extends FormBase {

  protected AttendanceManager $attendeeManager;
  protected AttendanceWaitlistManager $waitlistManager;
  protected NodeInterface $event;

  /**
   *
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->attendeeManager = $container->get('myeventlane_event_attendees.manager');
    $instance->waitlistManager = $container->get('myeventlane_event_attendees.waitlist');
    return $instance;
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'myeventlane_event_attendees_rsvp_form';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node || $node->bundle() !== 'event') {
      throw new \InvalidArgumentException('Event not provided.');
    }

    $this->event = $node;

    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => 'First Name',
      '#required' => TRUE,
    ];

    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => 'Last Name',
      '#required' => TRUE,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => 'Email',
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Submit RSVP',
    ];

    return $form;
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $capacity = (int) ($this->event->get('field_capacity')->value ?? 0);

    $current = $this->attendeeManager->getConfirmedCount($this->event);
    $is_full = $capacity > 0 && $current >= $capacity;

    $attendee = $this->attendeeManager->create(
      $this->event,
      $form_state->getValue('first_name'),
      $form_state->getValue('last_name'),
      $form_state->getValue('email'),
      $is_full ? 'waitlist' : 'confirmed'
    );

    if ($is_full) {
      $this->messenger()->addWarning('The event is full. You have been added to the waitlist.');
    }
    else {
      $this->messenger()->addStatus('Your RSVP is confirmed.');
    }
  }

}
