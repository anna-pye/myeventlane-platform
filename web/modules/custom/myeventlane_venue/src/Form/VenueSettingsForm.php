<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin settings form for venue configuration.
 */
class VenueSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_venue.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_venue_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_venue.settings');

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General settings'),
      '#open' => TRUE,
    ];

    $form['general']['allow_public_directory'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow public venue directory'),
      '#description' => $this->t('When enabled, venues can be listed in a public directory.'),
      '#default_value' => $config->get('allow_public_directory') ?? TRUE,
    ];

    $form['general']['require_approval_for_public'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require approval for public venues'),
      '#description' => $this->t('When enabled, venues must be approved before appearing in the public directory.'),
      '#default_value' => $config->get('require_approval_for_public') ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_venue.settings')
      ->set('allow_public_directory', $form_state->getValue('allow_public_directory'))
      ->set('require_approval_for_public', $form_state->getValue('require_approval_for_public'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
