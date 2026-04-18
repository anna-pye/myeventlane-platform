<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Symfony\Component\HttpFoundation\RequestStack;

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
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
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
      '#tree' => TRUE,
      '#attributes' => ['class' => ['mel-legal-consent']],
    ];

    $form['legal_consent']['customer_terms_agreed'] = [
      '#type' => 'checkbox',
      '#title' => Markup::create(t('I agree to the') . ' ' . $termsLink),
      // Enforced in validateLegalConsent(), not core #required (checkbox + core
      // required runs before #validate and yields "@title is required.").
      '#required' => FALSE,
      '#default_value' => FALSE,
      '#return_value' => 1,
      '#attributes' => ['class' => ['mel-consent-terms'], 'aria-required' => 'true'],
    ];

    $form['legal_consent']['privacy_agreed'] = [
      '#type' => 'checkbox',
      '#title' => Markup::create(t('I have read the') . ' ' . $privacyLink),
      '#required' => FALSE,
      '#default_value' => FALSE,
      '#return_value' => 1,
      '#attributes' => ['class' => ['mel-consent-privacy'], 'aria-required' => 'true'],
    ];

    $form['legal_consent']['marketing_opt_in'] = [
      '#type' => 'checkbox',
      '#title' => t('Send me updates, tips and event news'),
      '#required' => FALSE,
      '#default_value' => FALSE,
      '#return_value' => 1,
      '#attributes' => ['class' => ['mel-consent-marketing']],
    ];

    $form['#validate'][] = [$this, 'validateLegalConsent'];
    $form['#entity_builders'][] = [self::class, 'populateUserLegalFields'];
  }

  /**
   * Validation callback for legal consent.
   */
  public function validateLegalConsent(array &$form, FormStateInterface $form_state): void {
    $this->mergeLegalConsentFromRequest($form_state);
    $this->normalizeLegalConsentNestedValues($form_state);
    $this->loggerFactory->get('mel_debug')->notice('Consent value: <pre>@v</pre>', [
      '@v' => print_r($form_state->getValue('legal_consent'), TRUE),
    ]);
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
   * Aligns checkbox values with the POST body (same pattern as RSVP legal flow).
   *
   * Some browsers/themes submit multipart registration forms such that checkbox
   * values are present in the request but not yet reflected in form state.
   *
   * Symfony InputBag::get() only returns scalars and throws BadRequestException
   * when the key holds an array (e.g. legal_consent[customer_terms_agreed]).
   * Always read nested POST via Request::request->all().
   */
  private function mergeLegalConsentFromRequest(FormStateInterface $form_state): void {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || $request->getMethod() !== 'POST') {
      return;
    }
    $all = $request->request->all();
    $legal = $all['legal_consent'] ?? NULL;
    if (!is_array($legal)) {
      return;
    }
    // Malformed client input: legal_consent[] yields a list, not a keyed tree.
    if ($legal !== [] && array_is_list($legal)) {
      $first = reset($legal);
      $on = ($first === NULL || $first === '' || $first === '0' || $first === 0 || $first === FALSE) ? 0 : 1;
      $legal = [
        'customer_terms_agreed' => $on,
        'privacy_agreed' => $on,
        'marketing_opt_in' => 0,
      ];
    }
    $merged = $form_state->getValue('legal_consent');
    $merged = is_array($merged) ? $merged : [];
    foreach (['customer_terms_agreed', 'privacy_agreed', 'marketing_opt_in'] as $key) {
      if (!array_key_exists($key, $legal)) {
        $merged[$key] = 0;
        continue;
      }
      $v = $legal[$key];
      if (is_array($v)) {
        $v = reset($v);
      }
      $merged[$key] = ($v === NULL || $v === '' || $v === '0' || $v === 0 || $v === FALSE) ? 0 : 1;
    }
    $form_state->setValue('legal_consent', $merged);
  }

  /**
   * Ensures each legal_consent child is 0/1, never a nested array.
   */
  private function normalizeLegalConsentNestedValues(FormStateInterface $form_state): void {
    $legal = $form_state->getValue('legal_consent');
    if (!is_array($legal)) {
      return;
    }
    foreach (['customer_terms_agreed', 'privacy_agreed', 'marketing_opt_in'] as $key) {
      if (!array_key_exists($key, $legal)) {
        continue;
      }
      $v = $legal[$key];
      if (is_array($v)) {
        $v = reset($v);
        $legal[$key] = ($v === NULL || $v === '' || $v === '0' || $v === 0 || $v === FALSE) ? 0 : 1;
      }
    }
    $form_state->setValue('legal_consent', $legal);
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
