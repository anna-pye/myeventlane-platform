<?php

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class VendorOnboardProfileForm extends FormBase {

  public function getFormId() {
    return 'vendor_onboard_profile_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['#step_number'] = 2;
    $form['#total_steps'] = 6;
    $form['#step_title'] = $this->t('Set up your organiser profile');
    $form['#step_description'] = $this->t('Tell people about your organisation. This information will appear on your public organiser page.');

    $form['step_content'] = [
      '#type' => 'container',
    ];

    $form['step_content']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organiser name'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Enter your organiser name'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue'),
      '#button_type' => 'primary',
    ];

    $form['#attributes']['class'][] = 'mel-onboard-form';
    $form['#method'] = 'post';

    $form['#attached']['library'][] = 'myeventlane_vendor/onboarding';

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $name = $form_state->getValue(['step_content', 'name']);

    if (empty($name)) {
      $form_state->setErrorByName('step_content][name', $this->t('Name is required.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->logger('myeventlane_vendor')->notice('FORM SUBMITTED WORKING');

    $name = $form_state->getValue(['step_content', 'name']);

    $this->logger('myeventlane_vendor')->notice('Profile saved with name: @name', [
      '@name' => $name,
    ]);

    // TODO: Save to vendor entity here.

    $form_state->setRedirect('myeventlane_vendor.onboard.stripe');
  }

}
