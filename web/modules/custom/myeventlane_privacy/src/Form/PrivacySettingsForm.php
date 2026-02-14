<?php

declare(strict_types=1);

namespace Drupal\myeventlane_privacy\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Privacy & tracking ID configuration form.
 *
 * Values are passed to drupalSettings.myeventlane for the consent dispatcher.
 * Service IDs must match Klaro service entity IDs (gtm, gtag, meta_pixel,
 * hotjar, recaptcha) for consent-aware injection to work.
 */
final class PrivacySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_privacy.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_privacy_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_privacy.settings');

    // Duplicate tracking guard: GTM + GA4 both configured causes double pageviews.
    if ($config->get('gtm_id') && $config->get('ga4_id')) {
      $this->messenger()->addWarning(
        $this->t('Both Google Tag Manager and GA4 are configured. This may cause duplicate pageview tracking and messy analytics. Use GTM OR GA4, not both.')
      );
    }

    $klaro_services_url = Url::fromUri('internal:/admin/config/user-interface/klaro/services')->toString();
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Tracking IDs are injected only after user consent via the Klaro! consent banner. Create matching Klaro services (gtm, gtag, meta_pixel, hotjar, recaptcha) at <a href=":url">Klaro services</a>.', [
        ':url' => $klaro_services_url,
      ]) . '</p>',
      '#weight' => -100,
    ];

    $form['gtm_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Tag Manager ID'),
      '#description' => $this->t('e.g. GTM-XXXXXXX. Requires Klaro service ID "gtm".'),
      '#default_value' => $config->get('gtm_id') ?? '',
      '#maxlength' => 32,
    ];

    $form['ga4_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GA4 / gtag ID'),
      '#description' => $this->t('e.g. G-XXXXXXXXXX. Use only if not using GTM. Requires Klaro service ID "gtag".'),
      '#default_value' => $config->get('ga4_id') ?? '',
      '#maxlength' => 32,
    ];

    $form['meta_pixel_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Meta (Facebook) Pixel ID'),
      '#description' => $this->t('Numeric Pixel ID. Requires Klaro service ID "meta_pixel".'),
      '#default_value' => $config->get('meta_pixel_id') ?? '',
      '#maxlength' => 32,
    ];

    $form['hotjar_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hotjar Site ID'),
      '#description' => $this->t('Numeric Hotjar ID. Requires Klaro service ID "hotjar".'),
      '#default_value' => $config->get('hotjar_id') ?? '',
      '#maxlength' => 16,
    ];

    $form['recaptcha_site_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('reCAPTCHA v3 Site Key'),
      '#description' => $this->t('Site key for reCAPTCHA v3. Requires Klaro service ID "recaptcha".'),
      '#default_value' => $config->get('recaptcha_site_key') ?? '',
      '#maxlength' => 64,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $gtm = trim((string) $form_state->getValue('gtm_id'));
    $ga4 = trim((string) $form_state->getValue('ga4_id'));
    $meta = trim((string) $form_state->getValue('meta_pixel_id'));
    $hotjar = trim((string) $form_state->getValue('hotjar_id'));
    $recaptcha = trim((string) $form_state->getValue('recaptcha_site_key'));

    if ($gtm !== '' && !preg_match('/^GTM-[A-Z0-9]+$/', $gtm)) {
      $form_state->setErrorByName('gtm_id', $this->t('Invalid Google Tag Manager ID format. Example: GTM-XXXXXXX'));
    }
    if ($ga4 !== '' && !preg_match('/^G-[A-Z0-9]+$/', $ga4)) {
      $form_state->setErrorByName('ga4_id', $this->t('Invalid GA4 Measurement ID format. Example: G-XXXXXXXXXX'));
    }
    if ($meta !== '' && !preg_match('/^[0-9]+$/', $meta)) {
      $form_state->setErrorByName('meta_pixel_id', $this->t('Meta Pixel ID must be numeric only.'));
    }
    if ($hotjar !== '' && !preg_match('/^[0-9]+$/', $hotjar)) {
      $form_state->setErrorByName('hotjar_id', $this->t('Hotjar Site ID must be numeric only.'));
    }
    if ($recaptcha !== '' && !preg_match('/^[a-zA-Z0-9_-]{20,}$/', $recaptcha)) {
      $form_state->setErrorByName('recaptcha_site_key', $this->t('reCAPTCHA Site Key appears invalid. Expect alphanumeric string (20+ chars).'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_privacy.settings')
      ->set('gtm_id', trim((string) $form_state->getValue('gtm_id')))
      ->set('ga4_id', trim((string) $form_state->getValue('ga4_id')))
      ->set('meta_pixel_id', trim((string) $form_state->getValue('meta_pixel_id')))
      ->set('hotjar_id', trim((string) $form_state->getValue('hotjar_id')))
      ->set('recaptcha_site_key', trim((string) $form_state->getValue('recaptcha_site_key')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
