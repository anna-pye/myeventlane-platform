<?php

namespace Drupal\myeventlane_event_attendees\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\myeventlane_event_attendees\Service\AttendanceManager;

/**
 * Public RSVP submission form.
 */
class RsvpPublicForm extends FormBase {

  protected AttendanceManager $attendanceManager;

  /**
   *
   */
  public static function create(ContainerInterface $container): self {
    $instance = new static();
    $instance->attendanceManager = $container->get('myeventlane_event_attendees.manager');
    return $instance;
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'myeventlane_rsvp_public_form';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Node $event = NULL): array {
    if (!$event || $event->bundle() !== 'event') {
      $form['message'] = [
        '#markup' => 'Event not found.',
      ];
      return $form;
    }

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => $event->id(),
    ];

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

    // Vendor-defined questions (from event node field_attendee_questions).
    if ($event->hasField('field_attendee_questions')) {
      $form['custom_questions'] = [
        '#type' => 'fieldset',
        '#title' => 'Additional Information',
      ];

      foreach ($event->get('field_attendee_questions') as $delta => $paragraph) {
        $p = $paragraph->entity;
        $key = 'q_' . $delta;

        $label = $p->get('field_question_label')->value ?? 'Question';
        $type = $p->get('field_question_type')->value ?? 'text';

        switch ($type) {
          case 'select':
            $options_raw = $p->get('field_question_options')->value ?? '';
            $options = array_map('trim', explode("\n", $options_raw));

            $form['custom_questions'][$key] = [
              '#type' => 'select',
              '#title' => $label,
              '#options' => array_combine($options, $options),
              '#required' => (bool) $p->get('field_question_required')->value,
            ];
            break;

          default:
            $form['custom_questions'][$key] = [
              '#type' => 'textfield',
              '#title' => $label,
              '#required' => (bool) $p->get('field_question_required')->value,
            ];
        }
      }
    }

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Submit RSVP',
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = $form_state->getValue('event_id');
    $first = $form_state->getValue('first_name');
    $last = $form_state->getValue('last_name');
    $email = $form_state->getValue('email');

    $custom = [];
    foreach ($form_state->getValues() as $key => $value) {
      if (str_starts_with($key, 'q_')) {
        $custom[$key] = $value;
      }
    }

    $values = [
      'event_id' => $event_id,
      'first_name' => $first,
      'last_name' => $last,
      'email' => $email,
      'record_type' => 'rsvp',
      'status' => 'confirmed',
      'extra_answers' => json_encode($custom),
    ];

    $this->attendanceManager->createAttendee($values);

    $this->messenger()->addStatus('Thank you. Your RSVP has been recorded.');
  }

}
