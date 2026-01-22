<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Donation settings form for MyEventLane.
 */
final class DonationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_donations.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_donations_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_donations.settings');

    $form['platform'] = [
      '#type' => 'details',
      '#title' => $this->t('Platform donations (Vendor → MyEventLane)'),
      '#open' => TRUE,
    ];

    $form['platform']['enable_platform_donations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable platform donations'),
      '#description' => $this->t('Allow vendors to donate to MyEventLane to support platform development.'),
      '#default_value' => $config->get('enable_platform_donations') ?? TRUE,
    ];

    $form['platform']['platform_presets'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Preset amounts (comma-separated)'),
      '#description' => $this->t('Enter preset donation amounts separated by commas (e.g., 10, 25, 50, 100). Amounts should be in AUD.'),
      '#default_value' => implode(', ', $config->get('platform_presets') ?? [10.00, 25.00, 50.00, 100.00]),
      '#states' => [
        'visible' => [
          ':input[name="enable_platform_donations"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['platform']['platform_copy'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Help text'),
      '#description' => $this->t('Text displayed to vendors explaining platform donations.'),
      '#default_value' => $config->get('platform_copy') ?? 'Support MyEventLane and help us continue building tools for event creators.',
      '#rows' => 3,
      '#states' => [
        'visible' => [
          ':input[name="enable_platform_donations"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rsvp'] = [
      '#type' => 'details',
      '#title' => $this->t('RSVP attendee donations (Attendee → Vendor)'),
      '#open' => TRUE,
    ];

    $form['rsvp']['enable_rsvp_donations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable RSVP attendee donations'),
      '#description' => $this->t('Allow attendees to donate to event organisers during RSVP. Payments go to vendors via Stripe Connect.'),
      '#default_value' => $config->get('enable_rsvp_donations') ?? TRUE,
    ];

    $form['rsvp']['require_stripe_connected_for_attendee_donations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require Stripe Connect for attendee donations'),
      '#description' => $this->t('Only show donation options if the vendor has completed Stripe Connect onboarding.'),
      '#default_value' => $config->get('require_stripe_connected_for_attendee_donations') ?? TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enable_rsvp_donations"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rsvp']['attendee_presets'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Preset amounts (comma-separated)'),
      '#description' => $this->t('Enter preset donation amounts separated by commas (e.g., 5, 10, 25, 50). Amounts should be in AUD.'),
      '#default_value' => implode(', ', $config->get('attendee_presets') ?? [5.00, 10.00, 25.00, 50.00]),
      '#states' => [
        'visible' => [
          ':input[name="enable_rsvp_donations"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rsvp']['attendee_copy'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Help text'),
      '#description' => $this->t('Text displayed to attendees explaining donations.'),
      '#default_value' => $config->get('attendee_copy') ?? 'Support this event organiser with an optional donation. Your contribution helps make this event possible.',
      '#rows' => 3,
      '#states' => [
        'visible' => [
          ':input[name="enable_rsvp_donations"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General settings'),
      '#open' => TRUE,
    ];

    $form['general']['min_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum donation amount (AUD)'),
      '#description' => $this->t('The minimum amount that can be donated.'),
      '#default_value' => $config->get('min_amount') ?? 1.00,
      '#min' => 0.01,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate platform presets.
    if ($form_state->getValue('enable_platform_donations')) {
      $presets = $this->parsePresets($form_state->getValue('platform_presets'));
      if (empty($presets)) {
        $form_state->setErrorByName('platform_presets', $this->t('Please provide at least one preset amount.'));
      }
      foreach ($presets as $preset) {
        if ($preset < 0.01) {
          $form_state->setErrorByName('platform_presets', $this->t('Preset amounts must be at least $0.01.'));
        }
      }
    }

    // Validate attendee presets.
    if ($form_state->getValue('enable_rsvp_donations')) {
      $presets = $this->parsePresets($form_state->getValue('attendee_presets'));
      if (empty($presets)) {
        $form_state->setErrorByName('attendee_presets', $this->t('Please provide at least one preset amount.'));
      }
      foreach ($presets as $preset) {
        if ($preset < 0.01) {
          $form_state->setErrorByName('attendee_presets', $this->t('Preset amounts must be at least $0.01.'));
        }
      }
    }

    // Validate min amount.
    $minAmount = (float) $form_state->getValue('min_amount');
    if ($minAmount < 0.01) {
      $form_state->setErrorByName('min_amount', $this->t('Minimum amount must be at least $0.01.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('myeventlane_donations.settings');

    $config->set('enable_platform_donations', (bool) $form_state->getValue('enable_platform_donations'));
    $config->set('enable_rsvp_donations', (bool) $form_state->getValue('enable_rsvp_donations'));
    $config->set('require_stripe_connected_for_attendee_donations', (bool) $form_state->getValue('require_stripe_connected_for_attendee_donations'));
    $config->set('min_amount', (float) $form_state->getValue('min_amount'));
    $config->set('platform_copy', $form_state->getValue('platform_copy'));
    $config->set('attendee_copy', $form_state->getValue('attendee_copy'));

    // Parse and save presets.
    if ($form_state->getValue('enable_platform_donations')) {
      $platformPresets = $this->parsePresets($form_state->getValue('platform_presets'));
      $config->set('platform_presets', $platformPresets);
    }

    if ($form_state->getValue('enable_rsvp_donations')) {
      $attendeePresets = $this->parsePresets($form_state->getValue('attendee_presets'));
      $config->set('attendee_presets', $attendeePresets);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Parses comma-separated preset amounts.
   *
   * @param string $input
   *   Comma-separated values.
   *
   * @return array<float>
   *   Array of float values.
   */
  private function parsePresets(string $input): array {
    $presets = [];
    $values = explode(',', $input);
    foreach ($values as $value) {
      $value = trim($value);
      if ($value !== '' && is_numeric($value)) {
        $presets[] = (float) $value;
      }
    }
    return $presets;
  }

}
