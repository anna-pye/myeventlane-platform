<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Core\Form\FormStateInterface;

/**
 * Form alter service for RSVP legal consent.
 */
final class RsvpLegalAlter {

  /**
   * Request-scoped storage for legal consent to be applied to next submission.
   *
   * @var array|null
   */
  public static ?array $pendingLegalConsent = NULL;

  /**
   * Constructs the alter service.
   */
  public function __construct(
    private readonly LegalSettingsService $legalSettings,
    private readonly \Drupal\Component\Datetime\TimeInterface $time,
  ) {}

  /**
   * Alters RsvpPublicForm.
   */
  public function alterRsvpPublicForm(array &$form, FormStateInterface $form_state): void {
    $form['legal_consent'] = RsvpLegalConsentHelper::buildFieldset($this->legalSettings);
    $form['#validate'][] = [$this, 'validateRsvpLegal'];
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
    $form['#validate'][] = [$this, 'validateRsvpLegal'];
    $form['#submit'] = array_merge(
      [[$this, 'submitStoreLegalConsent']],
      $form['#submit'] ?? []
    );
  }

  /**
   * Validation callback.
   *
   * Uses nested getValue path and empty() checks to avoid strict TRUE failures
   * (checkbox returns 1 when checked, not TRUE). Uses setErrorByName for the
   * error key to avoid requiring #parents on the element.
   */
  public function validateRsvpLegal(array &$form, FormStateInterface $form_state): void {
    $terms_agreed = $form_state->getValue(['legal_consent', 'customer_terms_agreed']);
    if (empty($terms_agreed)) {
      $form_state->setErrorByName('legal_consent][customer_terms_agreed', t('You must agree to the Terms of Service.'));
    }
    $privacy_agreed = $form_state->getValue(['legal_consent', 'privacy_agreed']);
    if (empty($privacy_agreed)) {
      $form_state->setErrorByName('legal_consent][privacy_agreed', t('You must confirm you have read the Privacy Policy.'));
    }
  }

  /**
   * Submit callback (runs first): store legal consent for entity_presave.
   */
  public function submitStoreLegalConsent(array &$form, FormStateInterface $form_state): void {
    $legal = $form_state->getValue('legal_consent') ?? [];
    self::$pendingLegalConsent = [
      'legal' => $legal,
      'legal_settings' => $this->legalSettings,
      'timestamp' => $this->time->getRequestTime(),
    ];
  }

}
