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
   */
  public function validateRsvpLegal(array &$form, FormStateInterface $form_state): void {
    $legal = $form_state->getValue('legal_consent', []);
    if (!($legal['customer_terms_agreed'] ?? FALSE)) {
      $form_state->setError($form['legal_consent']['customer_terms_agreed'], t('You must agree to the Terms of Service.'));
    }
    if (!($legal['privacy_agreed'] ?? FALSE)) {
      $form_state->setError($form['legal_consent']['privacy_agreed'], t('You must confirm you have read the Privacy Policy.'));
    }
  }

  /**
   * Submit callback (runs first): store legal consent for entity_presave.
   */
  public function submitStoreLegalConsent(array &$form, FormStateInterface $form_state): void {
    $legal = $form_state->getValue('legal_consent', []);
    self::$pendingLegalConsent = [
      'legal' => $legal,
      'legal_settings' => $this->legalSettings,
      'timestamp' => $this->time->getRequestTime(),
    ];
  }

}
