<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Determines whether a user must re-consent when policy versions change.
 *
 * Compares current configured policy versions against the last accepted
 * consent stored in the legal_consent_event ledger.
 */
final class ConsentVersionDiffService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LegalSettingsService $legalSettings,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Gets consent status for RSVP context.
   *
   * @param string|null $email
   *   Email address (from form or entity).
   * @param int|null $uid
   *   User ID if authenticated.
   * @param int|null $event_id
   *   Event node ID if available.
   *
   * @return array
   *   Structured result with keys:
   *   - has_prior_consent: (bool)
   *   - requires_reconsent: (bool)
   *   - current_terms_version: (string)
   *   - current_privacy_version: (string)
   *   - last_terms_version: (string|null)
   *   - last_privacy_version: (string|null)
   *   - terms_changed: (bool)
   *   - privacy_changed: (bool)
   */
  public function getRsvpConsentStatus(?string $email, ?int $uid = NULL, ?int $event_id = NULL): array {
    $currentTerms = $this->legalSettings->getCustomerTermsVersion();
    $currentPrivacy = $this->legalSettings->getPrivacyVersion();

    $default = [
      'has_prior_consent' => FALSE,
      'requires_reconsent' => FALSE,
      'current_terms_version' => $currentTerms,
      'current_privacy_version' => $currentPrivacy,
      'last_terms_version' => NULL,
      'last_privacy_version' => NULL,
      'terms_changed' => FALSE,
      'privacy_changed' => FALSE,
    ];

    $email = $email !== null && $email !== '' ? trim($email) : NULL;
    $uid = $uid !== null && $uid > 0 ? (int) $uid : NULL;

    if ($email === NULL && $uid === NULL) {
      return $default;
    }

    try {
      $event = $this->findMostRecentRsvpConsent($email, $uid, $event_id);
    }
    catch (\Throwable $e) {
      $this->legalSettings->getLogger()->error('Version diff lookup failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $default;
    }

    if ($event === NULL) {
      return $default;
    }

    $lastTerms = (string) $event->get('terms_version')->value;
    $lastPrivacy = (string) $event->get('privacy_version')->value;

    $termsChanged = $lastTerms !== $currentTerms;
    $privacyChanged = $lastPrivacy !== $currentPrivacy;
    $requiresReconsent = $termsChanged || $privacyChanged;

    return [
      'has_prior_consent' => TRUE,
      'requires_reconsent' => $requiresReconsent,
      'current_terms_version' => $currentTerms,
      'current_privacy_version' => $currentPrivacy,
      'last_terms_version' => $lastTerms,
      'last_privacy_version' => $lastPrivacy,
      'terms_changed' => $termsChanged,
      'privacy_changed' => $privacyChanged,
    ];
  }

  /**
   * Finds the most recent RSVP consent event for the given context.
   *
   * Match priority: user_id if present, else email.
   * event_id is optional; if provided, may narrow the search but does not block.
   */
  private function findMostRecentRsvpConsent(?string $email, ?int $uid, ?int $event_id): ?\Drupal\myeventlane_legal\Entity\LegalConsentEventInterface {
    $storage = $this->entityTypeManager->getStorage('legal_consent_event');

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('source', 'rsvp')
      ->condition('accepted', 1)
      ->sort('accepted_at', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 1);

    if ($uid !== NULL) {
      $query->condition('user_id', $uid);
    }
    elseif ($email !== NULL) {
      $query->condition('email', $email);
    }
    else {
      return NULL;
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return NULL;
    }

    $entity = $storage->load(reset($ids));
    return $entity instanceof \Drupal\myeventlane_legal\Entity\LegalConsentEventInterface ? $entity : NULL;
  }

}
