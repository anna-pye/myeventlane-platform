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
 * Service IDs must match COOKiES cookie service entities (gtm, gtag, meta_pixel,
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

    $cookies_url = Url::fromRoute('entity.cookies_service.collection')->toString();
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Tracking IDs are injected only after user consent via the COOKiES consent banner. Create matching cookie service entities (gtm, gtag, meta_pixel, hotjar, recaptcha) at <a href=":url">COOKiES configuration</a>.', [
        ':url' => $cookies_url,
      ]) . '</p>',
      '#weight' => -100,
    ];

    $form['gtm_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Tag Manager ID'),
      '#description' => $this->t('e.g. GTM-XXXXXXX. Requires cookie service ID "gtm" in COOKiES.'),
      '#default_value' => $config->get('gtm_id') ?? '',
      '#maxlength' => 32,
    ];

    $form['ga4_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GA4 / gtag ID'),
      '#description' => $this->t('e.g. G-XXXXXXXXXX. Use only if not using GTM. Requires cookie service ID "gtag".'),
      '#default_value' => $config->get('ga4_id') ?? '',
      '#maxlength' => 32,
    ];

    $form['meta_pixel_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Meta (Facebook) Pixel ID'),
      '#description' => $this->t('Numeric Pixel ID. Requires cookie service ID "meta_pixel".'),
      '#default_value' => $config->get('meta_pixel_id') ?? '',
      '#maxlength' => 32,
    ];

    $form['hotjar_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hotjar Site ID'),
      '#description' => $this->t('Numeric Hotjar ID. Requires cookie service ID "hotjar".'),
      '#default_value' => $config->get('hotjar_id') ?? '',
      '#maxlength' => 16,
    ];

    $form['recaptcha_site_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('reCAPTCHA v3 Site Key'),
      '#description' => $this->t('Site key for reCAPTCHA v3. Requires cookie service ID "recaptcha".'),
      '#default_value' => $config->get('recaptcha_site_key') ?? '',
      '#maxlength' => 64,
    ];

    return parent::buildForm($form, $form_state);
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
