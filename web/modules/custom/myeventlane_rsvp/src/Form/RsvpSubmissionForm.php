<?php

namespace Drupal\myeventlane_rsvp\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;

/**
 * RSVP Submission Form.
 */
final class RsvpSubmissionForm extends FormBase {

  /**
   *
   */
  public function getFormId(): string {
    return 'myeventlane_rsvp_submission_form';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node || $node->bundle() !== 'event') {
      return ['#markup' => $this->t('Invalid event.')];
    }

    $form['event_id'] = [
      '#type' => 'value',
      '#value' => $node->id(),
    ];

    // Logged in users: we already know their identity.
    if ($this->currentUser()->isAuthenticated()) {
      $form['user_info'] = [
        '#markup' => '<p>' . $this->t('Submitting RSVP as @name', [
          '@name' => $this->currentUser()->getAccountName(),
        ]) . '</p>',
      ];
    }
    else {
      // Anonymous RSVP fields.
      $form['anon_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Your name'),
        '#required' => TRUE,
      ];
      $form['anon_email'] = [
        '#type' => 'email',
        '#title' => $this->t('Email'),
        '#required' => TRUE,
      ];
    }

    $capacity = (int) $node->get('field_capacity')->value ?: 0;
    $is_full = $capacity > 0 && $this->capacity->isAtCapacity($node->id(), $capacity);

    $form['status'] = [
      '#type' => 'value',
      '#value' => $is_full ? 'waitlist' : 'confirmed',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $is_full ? $this->t('Join Waitlist') : $this->t('RSVP Now'),
      '#attributes' => ['class' => ['mel-btn', $is_full ? 'mel-btn-secondary' : 'mel-btn-primary']],
    ];
    $this->mailer->sendCancellation($submission, $event);
    $this->mailer->notifyVendor($event, 'cancel');

    return $form;
  }

  /**
   *
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Nothing additional for now.
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = $form_state->getValue('event_id');

    /** @var \Drupal\myeventlane_rsvp\Entity\RsvpSubmission $submission */
    $submission = RsvpSubmission::create([
      'event' => $event_id,
      'status' => 'confirmed',
      'metadata' => json_encode([
        'anon_name' => $form_state->getValue('anon_name'),
        'anon_email' => $form_state->getValue('anon_email'),
      ]),
    ]);

    if ($this->currentUser()->isAuthenticated()) {
      $submission->setOwnerId($this->currentUser()->id());
    }
    $storage = \Drupal::service('myeventlane_rsvp.storage');
    $id = $storage->add($values);

    $mailer = \Drupal::service('myeventlane_rsvp.mailer');
    $mailer->sendConfirmation([
      'id' => $id,
      'event_nid' => $event->id(),
      'email' => $values['email'],
      'name' => $values['name'],
    ]);

    $submission->save();

    $this->mailer->sendConfirmation($submission, $event);
    $this->mailer->notifyVendor($event, 'new_rsvp');

    $this->messenger()->addStatus($this->t('RSVP received!'));
    $form_state->setRedirect('entity.node.canonical', ['node' => $event_id]);

    $queue = \Drupal::service('queue')->get('myeventlane_rsvp_sms_delivery');
    $queue->createItem([
      'to' => $phone,
      'msg' => $sms_body,
    ]);
  }

}
