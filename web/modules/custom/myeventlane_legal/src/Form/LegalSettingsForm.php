<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Legal settings configuration form.
 */
final class LegalSettingsForm extends ConfigFormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly TimeInterface $time,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_legal.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_legal_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_legal.settings');

    $form['versions'] = [
      '#type' => 'details',
      '#title' => $this->t('Policy versions'),
      '#open' => TRUE,
      '#description' => $this->t('Versions are stored with each acceptance for audit purposes. Increment when policies change.'),
    ];

    $form['versions']['customer_terms_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Customer terms version'),
      '#default_value' => $config->get('customer_terms_version') ?? '1.0',
      '#required' => TRUE,
    ];

    $form['versions']['vendor_terms_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vendor terms version'),
      '#default_value' => $config->get('vendor_terms_version') ?? '1.0',
      '#required' => TRUE,
    ];

    $form['versions']['privacy_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Privacy policy version'),
      '#default_value' => $config->get('privacy_version') ?? '1.0',
      '#required' => TRUE,
    ];

    $form['urls'] = [
      '#type' => 'details',
      '#title' => $this->t('Policy URLs'),
      '#open' => TRUE,
      '#description' => $this->t('Full URLs to policy documents. Shown on registration, RSVP, checkout, and vendor onboarding.'),
    ];

    $form['urls']['customer_terms_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Customer terms URL'),
      '#default_value' => $config->get('customer_terms_url') ?? '',
      '#required' => TRUE,
    ];

    $form['urls']['vendor_terms_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Vendor terms URL'),
      '#default_value' => $config->get('vendor_terms_url') ?? '',
      '#required' => TRUE,
    ];

    $form['urls']['privacy_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Privacy policy URL'),
      '#default_value' => $config->get('privacy_url') ?? '',
      '#required' => TRUE,
    ];

    $form['urls']['refund_policy_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Refund policy URL'),
      '#default_value' => $config->get('refund_policy_url') ?? '',
      '#description' => $this->t('Linked from checkout consent. Required for ACL transparency.'),
      '#required' => TRUE,
    ];

    $form['urls']['cookie_policy_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Cookie policy URL'),
      '#default_value' => $config->get('cookie_policy_url') ?? '',
      '#required' => TRUE,
    ];

    $form['collection_notice'] = [
      '#type' => 'details',
      '#title' => $this->t('Privacy collection notices (APP 5)'),
      '#open' => TRUE,
      '#description' => $this->t('Displayed at point of collection. Privacy Act 1988 APP 5 requires notification of collection.'),
    ];

    $form['collection_notice']['collection_notice_rsvp'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Collection notice (RSVP)'),
      '#default_value' => $config->get('collection_notice_rsvp') ?? '',
      '#rows' => 3,
      '#description' => $this->t('Shown on RSVP forms before collecting name/email.'),
    ];

    $form['collection_notice']['collection_notice_checkout'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Collection notice (Checkout)'),
      '#default_value' => $config->get('collection_notice_checkout') ?? '',
      '#rows' => 3,
      '#description' => $this->t('Shown on checkout before order completion.'),
    ];

    $form['vendor'] = [
      '#type' => 'details',
      '#title' => $this->t('Vendor terms capture'),
      '#open' => FALSE,
    ];

    $form['vendor']['store_vendor_ip_ua'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Store IP and User-Agent on vendor terms acceptance'),
      '#description' => $this->t('When enabled, IP address and User-Agent are saved with vendor terms acceptance for audit. Disable if your privacy policy does not permit this.'),
      '#default_value' => $config->get('store_vendor_ip_ua') ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('myeventlane_legal.settings');
    $config
      ->set('customer_terms_version', $form_state->getValue('customer_terms_version'))
      ->set('vendor_terms_version', $form_state->getValue('vendor_terms_version'))
      ->set('privacy_version', $form_state->getValue('privacy_version'))
      ->set('customer_terms_url', $form_state->getValue('customer_terms_url'))
      ->set('vendor_terms_url', $form_state->getValue('vendor_terms_url'))
      ->set('privacy_url', $form_state->getValue('privacy_url'))
      ->set('refund_policy_url', $form_state->getValue('refund_policy_url'))
      ->set('cookie_policy_url', $form_state->getValue('cookie_policy_url'))
      ->set('collection_notice_rsvp', $form_state->getValue('collection_notice_rsvp'))
      ->set('collection_notice_checkout', $form_state->getValue('collection_notice_checkout'))
      ->set('store_vendor_ip_ua', $form_state->getValue('store_vendor_ip_ua'))
      ->set('updated_at', $this->time->getRequestTime())
      ->save();
    parent::submitForm($form, $form_state);
  }

}
