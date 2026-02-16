<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

/**
 * Helper to build legal consent form elements for RSVP forms.
 */
final class RsvpLegalConsentHelper {

  /**
   * Builds the legal consent fieldset for RSVP forms.
   *
   * @return array
   *   Form element array.
   */
  public static function buildFieldset(LegalSettingsService $legalSettings): array {
    $termsUrl = $legalSettings->getCustomerTermsUrl();
    $privacyUrl = $legalSettings->getPrivacyUrl();

    $termsLink = $termsUrl ? '<a href="' . htmlspecialchars($termsUrl) . '" target="_blank" rel="noopener">' . t('Terms of Service') . '</a>' : t('Terms of Service');
    $privacyLink = $privacyUrl ? '<a href="' . htmlspecialchars($privacyUrl) . '" target="_blank" rel="noopener">' . t('Privacy Policy') . '</a>' : t('Privacy Policy');

    $notice = $legalSettings->getCollectionNoticeRsvp();

    $elements = [
      '#type' => 'fieldset',
      '#title' => t('Legal agreements'),
      '#weight' => 50,
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-legal-consent', 'mel-rsvp-legal-consent']],
      'customer_terms_agreed' => [
        '#type' => 'checkbox',
        '#title' => t('I agree to the @terms', ['@terms' => $termsLink]),
        '#required' => TRUE,
        '#default_value' => FALSE,
        '#return_value' => 1,
        '#attributes' => ['class' => ['mel-consent-terms'], 'aria-required' => 'true'],
      ],
      'privacy_agreed' => [
        '#type' => 'checkbox',
        '#title' => t('I have read the @privacy', ['@privacy' => $privacyLink]),
        '#required' => TRUE,
        '#default_value' => FALSE,
        '#return_value' => 1,
        '#attributes' => ['class' => ['mel-consent-privacy'], 'aria-required' => 'true'],
      ],
      'marketing_opt_in' => [
        '#type' => 'checkbox',
        '#title' => t('Send me updates, tips and event news'),
        '#required' => FALSE,
        '#default_value' => FALSE,
        '#return_value' => 1,
        '#attributes' => ['class' => ['mel-consent-marketing']],
      ],
    ];

    if ($notice !== '') {
      $elements = [
        '#type' => 'fieldset',
        '#title' => t('Legal agreements'),
        '#weight' => 50,
        '#tree' => TRUE,
        '#attributes' => ['class' => ['mel-legal-consent', 'mel-rsvp-legal-consent']],
        'collection_notice' => [
          '#type' => 'markup',
          '#markup' => '<p class="mel-collection-notice">' . nl2br(htmlspecialchars($notice)) . '</p>',
          '#weight' => -10,
        ],
      ] + $elements;
    }

    return $elements;
  }

  /**
   * Validates legal consent values.
   *
   * @return array
   *   Empty array if valid, or form state errors.
   */
  public static function validate(array $legal, string $prefix = 'legal_consent'): array {
    $errors = [];
    if (empty($legal['customer_terms_agreed'])) {
      $errors[] = [$prefix . '][customer_terms_agreed', t('You must agree to the Terms of Service.')];
    }
    if (empty($legal['privacy_agreed'])) {
      $errors[] = [$prefix . '][privacy_agreed', t('You must confirm you have read the Privacy Policy.')];
    }
    return $errors;
  }

  /**
   * Populates legal fields on an rsvp_submission entity.
   */
  public static function populateSubmission($submission, array $legal, LegalSettingsService $legalSettings, int $timestamp): void {
    if (!$submission->hasField('field_customer_terms_version')) {
      return;
    }
    $termsAgreed = (bool) ($legal['customer_terms_agreed'] ?? FALSE);
    $privacyAgreed = (bool) ($legal['privacy_agreed'] ?? FALSE);
    $marketingOptIn = (bool) ($legal['marketing_opt_in'] ?? FALSE);

    if ($termsAgreed) {
      $submission->set('field_customer_terms_version', $legalSettings->getCustomerTermsVersion());
      $submission->set('field_customer_terms_accepted_at', $timestamp);
    }
    if ($privacyAgreed) {
      $submission->set('field_privacy_version', $legalSettings->getPrivacyVersion());
      $submission->set('field_privacy_accepted_at', $timestamp);
    }
    if ($submission->hasField('field_marketing_opt_in')) {
      $submission->set('field_marketing_opt_in', $marketingOptIn);
      if ($marketingOptIn && $submission->hasField('field_marketing_opt_in_at')) {
        $submission->set('field_marketing_opt_in_at', $timestamp);
      }
    }
  }

}
