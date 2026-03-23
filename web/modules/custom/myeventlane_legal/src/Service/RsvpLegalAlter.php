<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Form alter service for RSVP legal consent.
 */
final class RsvpLegalAlter {

  /**
   * Form state key for RSVP consent payload consumed by RsvpSubmissionManager.
   *
   * @var string
   */
  public const RSVP_CONSENT_FORM_STATE_KEY = 'myeventlane_legal_rsvp_consent';

  /**
   * Current HTTP request stack (Symfony).
   */
  private readonly RequestStack $requestStack;

  /**
   * Constructs the alter service.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack|null $requestStack
   *   Injected request stack; omit only when the container was not rebuilt after
   *   adding the third service argument (falls back to \Drupal::requestStack()).
   */
  public function __construct(
    private readonly LegalSettingsService $legalSettings,
    ?RequestStack $requestStack = NULL,
  ) {
    $this->requestStack = $requestStack ?? \Drupal::requestStack();
  }

  /**
   * Alters RsvpPublicForm.
   */
  public function alterRsvpPublicForm(array &$form, FormStateInterface $form_state): void {
    $form['legal_consent'] = RsvpLegalConsentHelper::buildFieldset($this->legalSettings);
    if (isset($form['donation_section'])) {
      $form['donation_section']['#weight'] = 25;
    }
    $form['legal_consent']['#weight'] = 5;
    $this->enforceLegalConsentNonCoreRequired($form);
    array_unshift($form['#validate'], [$this, 'validateRsvpLegal']);
    $form['#submit'] = array_merge(
      [[$this, 'submitStoreLegalConsent']],
      $form['#submit'] ?? []
    );
  }

  /**
   * Alters RsvpBookingForm.
   */
  public function alterRsvpBookingForm(array &$form, FormStateInterface $form_state): void {
    $form['legal_consent'] = RsvpLegalConsentHelper::buildFieldset($this->legalSettings);
    if (isset($form['donation_section'])) {
      $form['donation_section']['#weight'] = 25;
    }
    $form['legal_consent']['#weight'] = 5;
    $this->enforceLegalConsentNonCoreRequired($form);
    array_unshift($form['#validate'], [$this, 'validateRsvpLegal']);
    $form['#submit'] = array_merge(
      [[$this, 'submitStoreLegalConsent']],
      $form['#submit'] ?? []
    );
  }

  /**
   * Ensures core #required validation cannot run on the legal checkbox.
   *
   * Core's FormValidator sets "@title field is required." when #required is TRUE
   * and the checkbox #value is 0 (see doValidateForm). That runs before any
   * form #validate handlers, so consent must not use core required handling.
   *
   * Important: #after_build is skipped on later requests because cached forms
   * carry #after_build_done; hook_form_alter does not run when the form is
   * loaded from FormCache on POST. #process runs every build (FormBuilder resets
   * #processed each doBuildForm), so we append processLegalConsentCheckbox() to
   * run after core checkbox processors.
   */
  public function enforceLegalConsentNonCoreRequired(array &$form): void {
    if (!isset($form['legal_consent']['customer_terms_agreed'])) {
      return;
    }
    self::stripLegalConsentRequiredFlags($form);
    self::registerCheckboxProcess($form);
    self::registerCheckboxAfterBuild($form);
    self::registerRootFormAfterBuild($form);
  }

  /**
   * Clears #required and HTML required attributes on the legal consent subtree.
   */
  private static function stripLegalConsentRequiredFlags(array &$form): void {
    if (!isset($form['legal_consent']['customer_terms_agreed'])) {
      return;
    }
    $form['legal_consent']['#required'] = FALSE;
    $form['legal_consent']['customer_terms_agreed']['#required'] = FALSE;
    unset($form['legal_consent']['customer_terms_agreed']['#required_error']);
    $form['legal_consent']['customer_terms_agreed']['#attributes'] ??= [];
    unset(
      $form['legal_consent']['customer_terms_agreed']['#attributes']['required'],
      $form['legal_consent']['customer_terms_agreed']['#attributes']['aria-required'],
    );
  }

  /**
   * Appends #process on the checkbox so it runs after core Checkbox processors.
   *
   * Running last ensures nothing in the #process chain re-applies #required.
   */
  private static function registerCheckboxProcess(array &$form): void {
    if (!isset($form['legal_consent']['customer_terms_agreed'])) {
      return;
    }
    $callbacks = $form['legal_consent']['customer_terms_agreed']['#process'] ?? [];
    $pair = [self::class, 'processLegalConsentCheckbox'];
    if (!in_array($pair, $callbacks, TRUE)) {
      $callbacks[] = $pair;
    }
    $form['legal_consent']['customer_terms_agreed']['#process'] = $callbacks;
  }

  /**
   * Prepends an after_build on the checkbox (first build only; see class doc).
   */
  private static function registerCheckboxAfterBuild(array &$form): void {
    if (!isset($form['legal_consent']['customer_terms_agreed'])) {
      return;
    }
    $callbacks = $form['legal_consent']['customer_terms_agreed']['#after_build'] ?? [];
    $pair = [self::class, 'afterBuildLegalConsentCheckbox'];
    if (!in_array($pair, $callbacks, TRUE)) {
      array_unshift($callbacks, $pair);
    }
    $form['legal_consent']['customer_terms_agreed']['#after_build'] = $callbacks;
  }

  /**
   * Appends an after_build on the form root (first build only; see class doc).
   */
  private static function registerRootFormAfterBuild(array &$form): void {
    $pair = [self::class, 'afterBuildRootFormLegalConsent'];
    $callbacks = $form['#after_build'] ?? [];
    if (!in_array($pair, $callbacks, TRUE)) {
      $callbacks[] = $pair;
      $form['#after_build'] = $callbacks;
    }
  }

  /**
   * #process: strip required on every form build (including cached POST).
   *
   * @param array $element
   *   The checkbox render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   *
   * @return array
   *   The element.
   */
  public static function processLegalConsentCheckbox(&$element, FormStateInterface $form_state, &$complete_form) {
    $element['#required'] = FALSE;
    unset($element['#required_error']);
    $element['#attributes'] ??= [];
    unset($element['#attributes']['required'], $element['#attributes']['aria-required']);
    // Do not set #validated here: it can interact badly with value handling and rebuilds.
    // Core required is off; enforce consent only in validateRsvpLegal().
    return $element;
  }

  /**
   * After-build on the checkbox (initial uncached build only).
   *
   * @param array $element
   *   The checkbox render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The element.
   */
  public static function afterBuildLegalConsentCheckbox(array $element, FormStateInterface $form_state): array {
    $element['#required'] = FALSE;
    unset($element['#required_error']);
    $element['#attributes'] ??= [];
    unset($element['#attributes']['required'], $element['#attributes']['aria-required']);
    return $element;
  }

  /**
   * After-build on the form root (initial uncached build only).
   *
   * @param array $element
   *   The form render array (root).
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array.
   */
  public static function afterBuildRootFormLegalConsent(array $element, FormStateInterface $form_state): array {
    self::stripLegalConsentRequiredFlags($element);
    return $element;
  }

  /**
   * Validation callback.
   *
   * One checkbox covers Terms + Privacy; we still store both flags on submit.
   */
  public function validateRsvpLegal(array &$form, FormStateInterface $form_state): void {
    $this->normalizeLegalConsentFromRequest($form_state);

    if ($this->isTermsCheckboxAccepted($form_state)) {
      return;
    }

    $this->legalSettings->getLogger()->warning('RSVP legal: terms/privacy not accepted. POST legal_consent keys: @keys', [
      '@keys' => $this->legalConsentPostKeysForLog(),
    ]);
    $form_state->setErrorByName('legal_consent][customer_terms_agreed', t('You must agree to the Terms of Service and Privacy Policy.'));
  }

  /**
   * Whether the terms checkbox is accepted.
   *
   * Do not prefer the render element's #value here: validateRsvpLegal() runs
   * normalizeLegalConsentFromRequest() first, which may align form_state with
   * the POST body while #value still reflects earlier input handling (e.g.
   * ordering/truncation). consentCheckboxChecked() uses getValue(), raw user
   * input, and the request — consistent with normalize.
   */
  private function isTermsCheckboxAccepted(FormStateInterface $form_state): bool {
    return $this->consentCheckboxChecked($form_state, 'customer_terms_agreed');
  }

  /**
   * Same semantics as core checkbox value handling for "checked".
   */
  private static function checkboxValueIndicatesChecked(mixed $value, mixed $return_value): bool {
    if ($value === TRUE) {
      return TRUE;
    }
    if ($value === FALSE || $value === 0) {
      return FALSE;
    }
    return (string) $value === (string) $return_value;
  }

  /**
   * Copies legal_consent checkbox values from the POST request into form state.
   */
  private function normalizeLegalConsentFromRequest(FormStateInterface $form_state): void {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || $request->getMethod() !== 'POST') {
      return;
    }

    $post = $request->request->all();
    $legal = $post['legal_consent'] ?? NULL;
    if (!is_array($legal)) {
      return;
    }

    $merged = $form_state->getValue('legal_consent');
    $merged = is_array($merged) ? $merged : [];

    foreach (['customer_terms_agreed', 'marketing_opt_in'] as $key) {
      if (!array_key_exists($key, $legal)) {
        continue;
      }
      $v = $legal[$key];
      if ($v === NULL || $v === '' || $v === '0' || $v === 0 || $v === FALSE) {
        $merged[$key] = 0;
      }
      else {
        $merged[$key] = 1;
      }
    }

    // Some stacks POST attendee_terms_agreed instead of customer_terms_agreed;
    // treat it as the same combined Terms + Privacy checkbox.
    if (
      (!array_key_exists('customer_terms_agreed', $merged) || !$merged['customer_terms_agreed'])
      && array_key_exists('attendee_terms_agreed', $legal)
    ) {
      $v = $legal['attendee_terms_agreed'];
      if ($v === NULL || $v === '' || $v === '0' || $v === 0 || $v === FALSE) {
        $merged['customer_terms_agreed'] = 0;
      }
      else {
        $merged['customer_terms_agreed'] = 1;
      }
    }

    if (isset($merged['customer_terms_agreed'])) {
      $merged['privacy_agreed'] = (int) (bool) $merged['customer_terms_agreed'];
    }

    $form_state->setValue('legal_consent', $merged);
  }

  /**
   * Comma-separated keys under legal_consent in POST (no values), for logs.
   */
  private function legalConsentPostKeysForLog(): string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return '';
    }
    $legal = $request->request->all()['legal_consent'] ?? NULL;
    if (!is_array($legal)) {
      return '(missing)';
    }
    return implode(', ', array_keys($legal));
  }

  /**
   * Whether a legal consent checkbox is checked (processed value or raw input).
   */
  private function consentCheckboxChecked(FormStateInterface $form_state, string $key): bool {
    $processed = $form_state->getValue(['legal_consent', $key]);
    $return = 1;
    if (isset($form_state->getCompleteForm()['legal_consent'][$key]['#return_value'])) {
      $return = $form_state->getCompleteForm()['legal_consent'][$key]['#return_value'];
    }
    if (self::checkboxValueIndicatesChecked($processed, $return)) {
      return TRUE;
    }
    $userInput = $form_state->getUserInput();
    if (!is_array($userInput)) {
      $userInput = [];
    }
    $raw = NestedArray::getValue($userInput, ['legal_consent', $key]);
    if ($raw !== NULL && $raw !== '' && self::checkboxValueIndicatesChecked($raw, $return)) {
      return TRUE;
    }
    if ($key === 'customer_terms_agreed') {
      $rawAttendee = NestedArray::getValue($userInput, ['legal_consent', 'attendee_terms_agreed']);
      if ($rawAttendee !== NULL && $rawAttendee !== '' && self::checkboxValueIndicatesChecked($rawAttendee, $return)) {
        return TRUE;
      }
    }
    $request = $this->requestStack->getCurrentRequest();
    if ($request && $request->getMethod() === 'POST') {
      $legal = $request->request->all()['legal_consent'] ?? NULL;
      if (is_array($legal) && array_key_exists($key, $legal)) {
        $v = $legal[$key];
        return self::checkboxValueIndicatesChecked($v, $return);
      }
      if (
        $key === 'customer_terms_agreed'
        && is_array($legal)
        && array_key_exists('attendee_terms_agreed', $legal)
      ) {
        $v = $legal['attendee_terms_agreed'];
        return self::checkboxValueIndicatesChecked($v, $return);
      }
    }
    return FALSE;
  }

  /**
   * Submit callback (runs first): store consent on form state for submitOrUpdate().
   *
   * RsvpSubmissionManager attaches this to the entity as _myeventlaneLegalConsent
   * before save; hook_entity_presave() applies it via consent_helper.
   */
  public function submitStoreLegalConsent(array &$form, FormStateInterface $form_state): void {
    $terms_ok = $this->consentCheckboxChecked($form_state, 'customer_terms_agreed');
    $marketing_ok = $this->consentCheckboxChecked($form_state, 'marketing_opt_in');
    $form_state->set(self::RSVP_CONSENT_FORM_STATE_KEY, [
      'terms' => $terms_ok,
      'privacy' => $terms_ok,
      'marketing' => $marketing_ok,
    ]);
  }

}
