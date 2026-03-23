<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_legal\Service\LegalSettingsService;

/**
 * Adds legal consent fields to user registration form.
 */
final class UserRegisterLegalAlter {

  /**
   * Constructs the alter service.
   */
  public function __construct(
    private readonly LegalSettingsService $legalSettings,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Alters the user registration form.
   */
  public function alterForm(array &$form, FormStateInterface $form_state): void {
    if ($this->currentUser->hasPermission('administer users')) {
      return;
    }

    $termsUrl = $this->legalSettings->getCustomerTermsUrl();
    $privacyUrl = $this->legalSettings->getPrivacyUrl();

    $termsLink = $termsUrl ? '<a href="' . htmlspecialchars($termsUrl) . '" target="_blank" rel="noopener">' . t('Terms of Service') . '</a>' : t('Terms of Service');
    $privacyLink = $privacyUrl ? '<a href="' . htmlspecialchars($privacyUrl) . '" target="_blank" rel="noopener">' . t('Privacy Policy') . '</a>' : t('Privacy Policy');

    $form['legal_consent'] = [
      '#type' => 'fieldset',
      '#title' => t('Legal agreements'),
      '#weight' => 5,
      '#attributes' => ['class' => ['mel-legal-consent']],
    ];

    $form['legal_consent']['customer_terms_agreed'] = [
      '#type' => 'checkbox',
      '#title' => Markup::create(t('I agree to the') . ' ' . $termsLink),
      // Enforced in validateLegalConsent(), not core #required (checkbox + core
      // required runs before #validate and yields "@title is required.").
      '#required' => FALSE,
      '#default_value' => FALSE,
      '#attributes' => ['class' => ['mel-consent-terms'], 'aria-required' => 'true'],
    ];

    $form['legal_consent']['privacy_agreed'] = [
      '#type' => 'checkbox',
      '#title' => Markup::create(t('I have read the') . ' ' . $privacyLink),
      '#required' => FALSE,
      '#default_value' => FALSE,
      '#attributes' => ['class' => ['mel-consent-privacy'], 'aria-required' => 'true'],
    ];

    $form['legal_consent']['marketing_opt_in'] = [
      '#type' => 'checkbox',
      '#title' => t('Send me updates, tips and event news'),
      '#required' => FALSE,
      '#default_value' => FALSE,
      '#attributes' => ['class' => ['mel-consent-marketing']],
    ];

    $form['#validate'][] = [$this, 'validateLegalConsent'];
    $form['#entity_builders'][] = [self::class, 'populateUserLegalFields'];
  }

  /**
   * Validation callback for legal consent.
   */
  public function validateLegalConsent(array &$form, FormStateInterface $form_state): void {
    $terms = (bool) $form_state->getValue(['legal_consent', 'customer_terms_agreed']);
    $privacy = (bool) $form_state->getValue(['legal_consent', 'privacy_agreed']);
    if (!$terms) {
      $form_state->setError($form['legal_consent']['customer_terms_agreed'], t('You must agree to the Terms of Service.'));
    }
    if (!$privacy) {
      $form_state->setError($form['legal_consent']['privacy_agreed'], t('You must confirm you have read the Privacy Policy.'));
    }
  }

  /**
   * Entity builder: populate legal fields on the user entity.
   */
  public static function populateUserLegalFields(string $entity_type, $entity, array $form, FormStateInterface $form_state): void {
    if ($entity_type !== 'user' || !$entity->isNew()) {
      return;
    }
    if ($form_state->getErrors()) {
      return;
    }

    $legal = $form_state->getValue('legal_consent', []);
    $termsAgreed = (bool) ($legal['customer_terms_agreed'] ?? FALSE);
    $privacyAgreed = (bool) ($legal['privacy_agreed'] ?? FALSE);
    $marketingOptIn = (bool) ($legal['marketing_opt_in'] ?? FALSE);

    $container = \Drupal::getContainer();
    $legalSettings = $container->get('myeventlane_legal.settings');
    $time = $container->get('datetime.time');

    if ($termsAgreed && $entity->hasField('field_customer_terms_version')) {
      $entity->set('field_customer_terms_version', $legalSettings->getCustomerTermsVersion());
      $entity->set('field_customer_terms_accepted_at', $time->getRequestTime());
    }
    if ($privacyAgreed && $entity->hasField('field_privacy_version')) {
      $entity->set('field_privacy_version', $legalSettings->getPrivacyVersion());
      $entity->set('field_privacy_accepted_at', $time->getRequestTime());
    }
    if ($entity->hasField('field_marketing_opt_in')) {
      $entity->set('field_marketing_opt_in', $marketingOptIn);
      if ($marketingOptIn && $entity->hasField('field_marketing_opt_in_at')) {
        $entity->set('field_marketing_opt_in_at', $time->getRequestTime());
      }
    }
  }

}
