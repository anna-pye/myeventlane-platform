<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Applies RSVP legal consent to rsvp_submission entities (version-aware).
 */
final class RsvpSubmissionConsentHelper {

  public function __construct(
    private readonly LegalSettingsService $settings,
    private readonly TimeInterface $time,
    private readonly ConsentAuditService $consentAudit,
  ) {}

  /**
   * Writes consent fields when policy versions change or were never recorded.
   *
   * @param array $consent
   *   Keys: terms (bool), privacy (bool), marketing (bool). Terms and privacy
   *   typically match the single RSVP checkbox; both gate version updates.
   */
  public function applyConsentToEntity(EntityInterface $entity, array $consent): void {
    if ($entity->getEntityTypeId() !== 'rsvp_submission') {
      return;
    }

    if (!$entity->hasField('field_customer_terms_version')) {
      return;
    }

    $termsAccepted = !empty($consent['terms']);
    $privacyAccepted = !empty($consent['privacy']);
    $marketing = !empty($consent['marketing']);
    $now = $this->time->getRequestTime();

    $currentTermsVersion = $this->settings->getCustomerTermsVersion();
    $currentPrivacyVersion = $this->settings->getPrivacyVersion();

    if ($termsAccepted) {
      $existingTerms = '';
      if ($entity->hasField('field_customer_terms_version') && !$entity->get('field_customer_terms_version')->isEmpty()) {
        $existingTerms = (string) $entity->get('field_customer_terms_version')->value;
      }
      if ($existingTerms === '' || $existingTerms !== $currentTermsVersion) {
        $entity->set('field_customer_terms_version', $currentTermsVersion);
        $entity->set('field_customer_terms_accepted_at', $now);
      }
    }

    if ($privacyAccepted) {
      $existingPrivacy = '';
      if ($entity->hasField('field_privacy_version') && !$entity->get('field_privacy_version')->isEmpty()) {
        $existingPrivacy = (string) $entity->get('field_privacy_version')->value;
      }
      if ($existingPrivacy === '' || $existingPrivacy !== $currentPrivacyVersion) {
        $entity->set('field_privacy_version', $currentPrivacyVersion);
        $entity->set('field_privacy_accepted_at', $now);
      }
    }

    if ($entity->hasField('field_marketing_opt_in')) {
      $entity->set('field_marketing_opt_in', $marketing);
      if ($marketing && $entity->hasField('field_marketing_opt_in_at')) {
        $entity->set('field_marketing_opt_in_at', $now);
      }
    }

    if ($termsAccepted && $privacyAccepted) {
      try {
        $email = $entity->hasField('email') && !$entity->get('email')->isEmpty()
          ? (string) $entity->get('email')->value
          : NULL;
        if ($email) {
          $eventId = NULL;
          if ($entity->hasField('event_id') && !$entity->get('event_id')->isEmpty()) {
            $ref = $entity->get('event_id')->first();
            $eventId = $ref && $ref->target_id ? (int) $ref->target_id : NULL;
          }

          $context = [
            'email' => $email,
            'source' => 'rsvp',
            'entity_type' => 'rsvp_submission',
            'entity_id' => $entity->id() ?: NULL,
            'event_id' => $eventId,
            'terms_version' => $currentTermsVersion,
            'privacy_version' => $currentPrivacyVersion,
          ];

          if ($this->consentAudit->shouldRecordRsvpConsent($context)) {
            $this->consentAudit->record($context);
          }
        }
      }
      catch (\Throwable $e) {
        $this->settings->getLogger()->error('Consent audit write failed: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

}
