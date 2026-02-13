<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendor terms acceptance form.
 */
final class VendorTermsForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    private readonly LegalSettingsService $legalSettings,
    private readonly AccountProxyInterface $currentUser,
    private readonly OnboardingManager $onboardingManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_legal.settings'),
      $container->get('current_user'),
      $container->get('myeventlane_onboarding.manager'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vendor_terms_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $vendorUrl = $this->legalSettings->getVendorTermsUrl();
    $privacyUrl = $this->legalSettings->getPrivacyUrl();

    $vendorLink = $vendorUrl ? '<a href="' . htmlspecialchars($vendorUrl) . '" target="_blank" rel="noopener">' . $this->t('Vendor Terms of Service') . '</a>' : $this->t('Vendor Terms of Service');
    $privacyLink = $privacyUrl ? '<a href="' . htmlspecialchars($privacyUrl) . '" target="_blank" rel="noopener">' . $this->t('Privacy Policy') . '</a>' : $this->t('Privacy Policy');

    $form['#attached']['library'][] = 'myeventlane_vendor/onboarding';

    $form['legal'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Agreements'),
      '#attributes' => ['class' => ['mel-vendor-terms']],
    ];
    $form['legal']['vendor_terms_agreed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the @terms', ['@terms' => $vendorLink]),
      '#required' => TRUE,
      '#default_value' => FALSE,
      '#attributes' => ['aria-required' => 'true'],
    ];
    $form['legal']['privacy_acknowledged'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I have read the @privacy', ['@privacy' => $privacyLink]),
      '#required' => TRUE,
      '#default_value' => FALSE,
      '#attributes' => ['aria-required' => 'true'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Accept and continue'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $vendorAgreed = (bool) $form_state->getValue(['legal', 'vendor_terms_agreed']);
    $privacyAck = (bool) $form_state->getValue(['legal', 'privacy_acknowledged']);
    if (!$vendorAgreed) {
      $form_state->setError($form['legal']['vendor_terms_agreed'], $this->t('You must agree to the Vendor Terms of Service.'));
    }
    if (!$privacyAck) {
      $form_state->setError($form['legal']['privacy_acknowledged'], $this->t('You must confirm you have read the Privacy Policy.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return;
    }

    $account = $this->currentUser->getAccount();
    $vendor = $this->onboardingManager->ensureVendorExists($account);
    if (!$vendor) {
      return;
    }

    $timestamp = $this->time->getRequestTime();
    if ($vendor->hasField('field_vendor_terms_version')) {
      $vendor->set('field_vendor_terms_version', $this->legalSettings->getVendorTermsVersion());
    }
    if ($vendor->hasField('field_vendor_terms_accepted_at')) {
      $vendor->set('field_vendor_terms_accepted_at', $timestamp);
    }
    if ($this->legalSettings->storeVendorIpUa()) {
      $request = $this->getRequest();
      if ($request) {
        if ($vendor->hasField('field_vendor_terms_accepted_ip')) {
          $vendor->set('field_vendor_terms_accepted_ip', $request->getClientIp());
        }
        if ($vendor->hasField('field_vendor_terms_accepted_ua')) {
          $vendor->set('field_vendor_terms_accepted_ua', $request->headers->get('User-Agent', ''));
        }
      }
    }

    $vendor->save();

    $this->messenger()->addStatus($this->t('Thank you. You can now create events.'));
    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_vendor.create_event_gateway'));
  }

}
