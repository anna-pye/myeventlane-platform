<?php

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for Vendor configuration.
 */
class VendorSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['myeventlane_vendor.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'myeventlane_vendor_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attributes']['class'][] = 'mel-no-ajax';

    $config = $this->config('myeventlane_vendor.settings');

    $form['directory_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Directory title'),
      '#default_value' => $config->get('directory_title') ?: 'Organisers',
      '#description' => $this->t('Title to show on the public organiser directory page.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('myeventlane_vendor.settings')
      ->set('directory_title', $form_state->getValue('directory_title'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
