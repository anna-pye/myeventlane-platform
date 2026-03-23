<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Core\Render\Markup;

/**
 * Helper to build legal consent form elements for RSVP forms.
 */
final class RsvpLegalConsentHelper {

  /**
   * Builds the legal consent fieldset for RSVP forms.
   *
   * Uses one checkbox for both Terms and Privacy acknowledgment so attendees
   * cannot pass one control and miss the other (common support issue). Stored
   * values still set both terms and privacy fields on the submission.
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
        '#title' => t('I agree to the :terms and confirm I have read the :privacy.', [
          ':terms' => Markup::create($termsLink),
          ':privacy' => Markup::create($privacyLink),
        ]),
        '#description' => t('Required to RSVP. The marketing option below is optional.'),
        '#required' => FALSE,
        '#default_value' => FALSE,
        '#return_value' => 1,
        // Do not set aria-required: some stacks treat it like HTML required and
        // core may still apply #required validation before our #validate runs.
        '#attributes' => ['class' => ['mel-consent-terms']],
      ],
      'marketing_opt_in' => [
        '#type' => 'checkbox',
        '#title' => t('Send me updates, tips and event news (optional)'),
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
      $errors[] = [$prefix . '][customer_terms_agreed', t('You must agree to the Terms of Service and Privacy Policy.')];
    }
    return $errors;
  }

}
