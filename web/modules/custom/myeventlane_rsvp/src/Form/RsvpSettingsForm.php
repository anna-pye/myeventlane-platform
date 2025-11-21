<?php

namespace Drupal\myeventlane_rsvp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class RsvpSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['myeventlane_rsvp.settings'];
  }

  public function getFormId() {
    return 'myeventlane_rsvp_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('myeventlane_rsvp.settings');

    $form['send_vendor_copy'] = [
      '#type' => 'checkbox',
      '#title' => 'Send vendor copy',
      '#default_value' => $config->get('send_vendor_copy'),
    ];

    $form['langcode'] = [
      '#type' => 'textfield',
      '#title' => 'Email language code',
      '#default_value' => $config->get('langcode'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('myeventlane_rsvp.settings')
      ->set('send_vendor_copy', $form_state->getValue('send_vendor_copy'))
      ->set('langcode', $form_state->getValue('langcode'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}