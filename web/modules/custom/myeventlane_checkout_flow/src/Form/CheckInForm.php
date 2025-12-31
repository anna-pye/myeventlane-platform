<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Form for checking in an attendee.
 */
final class CheckInForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_checkin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ParagraphInterface $paragraph = NULL): array {
    if (!$paragraph || $paragraph->bundle() !== 'attendee_answer') {
      $form['error'] = [
        '#markup' => $this->t('Invalid paragraph.'),
      ];
      return $form;
    }

    $form['paragraph_id'] = [
      '#type' => 'hidden',
      '#value' => $paragraph->id(),
    ];

    $checked_in = $paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()
      ? (bool) $paragraph->get('field_checked_in')->value : FALSE;

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $checked_in ? $this->t('Undo Check In') : $this->t('Check In'),
      '#attributes' => [
        'class' => ['mel-btn', $checked_in ? 'mel-btn-warning' : 'mel-btn-primary'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $paragraph_id = $form_state->getValue('paragraph_id');
    if (!$paragraph_id) {
      return;
    }

    $paragraph_storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $paragraph = $paragraph_storage->load($paragraph_id);

    if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_answer') {
      return;
    }

    // Verify access.
    $accessHandler = \Drupal::entityTypeManager()->getAccessControlHandler('paragraph');
    $access = $accessHandler->access($paragraph, 'update', $this->currentUser());
    if (!$access) {
      $this->messenger()->addError($this->t('Access denied.'));
      return;
    }

    // Get event.
    $accessResolver = \Drupal::service('myeventlane_checkout_paragraph.access_resolver');
    $event = $accessResolver->getEvent($paragraph);
    if (!$event) {
      return;
    }

    // Toggle check-in.
    $is_checked_in = $paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()
      ? (bool) $paragraph->get('field_checked_in')->value : FALSE;

    $new_status = !$is_checked_in;

    if ($paragraph->hasField('field_checked_in')) {
      $paragraph->set('field_checked_in', $new_status ? 1 : 0);
    }

    if ($new_status) {
      if ($paragraph->hasField('field_checked_in_timestamp')) {
        $paragraph->set('field_checked_in_timestamp', time());
      }
      if ($paragraph->hasField('field_checked_in_by')) {
        $paragraph->set('field_checked_in_by', $this->currentUser()->id());
      }
    }
    else {
      if ($paragraph->hasField('field_checked_in_timestamp')) {
        $paragraph->set('field_checked_in_timestamp', NULL);
      }
      if ($paragraph->hasField('field_checked_in_by')) {
        $paragraph->set('field_checked_in_by', NULL);
      }
    }

    $paragraph->save();

    $this->messenger()->addStatus(
      $new_status
        ? $this->t('Attendee checked in successfully.')
        : $this->t('Check-in undone.')
    );

    // Redirect back to check-in page.
    $form_state->setRedirect('myeventlane_checkout_flow.vendor_checkin', ['node' => $event->id()]);
  }

}

