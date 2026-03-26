<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\Error;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records immutable legal consent events for audit trail.
 *
 * Humanitix-level audit: every consent action is recorded as an append-only
 * event with policy versions, timestamps, IP, and user agent.
 */
final class ConsentAuditService {

  /**
   * Window in seconds within which duplicate events are suppressed.
   */
  private const DUPLICATE_WINDOW_SECONDS = 10;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
    private readonly TimeInterface $time,
    private readonly LegalSettingsService $legalSettings,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Records a consent event (append-only).
   *
   * @param array $data
   *   Keys: email (required), source (required), entity_type (optional),
   *   entity_id (optional), event_id (optional), terms_version (required),
   *   privacy_version (required), refund_version (optional).
   *
   * @throws \InvalidArgumentException
   *   If required keys are missing.
   */
  public function record(array $data): void {
    $email = $data['email'] ?? NULL;
    $source = $data['source'] ?? NULL;
    $terms_version = $data['terms_version'] ?? NULL;
    $privacy_version = $data['privacy_version'] ?? NULL;

    if (empty($email) || empty($source) || empty($terms_version) || empty($privacy_version)) {
      throw new \InvalidArgumentException('Consent audit requires email, source, terms_version, and privacy_version.');
    }

    if ($this->isDuplicate($email, $source, $terms_version, $privacy_version)) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $ip = $request ? $request->getClientIp() : '';
    $ua = '';
    if ($request && $request->headers->has('User-Agent')) {
      $ua = substr((string) $request->headers->get('User-Agent'), 0, 255);
    }
    $session_id = NULL;
    if ($request && $request->hasSession()) {
      $session = $request->getSession();
      $session_id = $session ? $session->getId() : NULL;
    }

    $user_id = $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : NULL;

    try {
      $storage = $this->entityTypeManager->getStorage('legal_consent_event');
      $entity = $storage->create([
        'user_id' => $user_id,
        'email' => $email,
        'source' => $source,
        'source_entity_type' => $data['entity_type'] ?? NULL,
        'source_entity_id' => isset($data['entity_id']) ? (int) $data['entity_id'] : NULL,
        'event_id' => isset($data['event_id']) ? (int) $data['event_id'] : NULL,
        'terms_version' => $terms_version,
        'privacy_version' => $privacy_version,
        'refund_version' => $data['refund_version'] ?? NULL,
        'accepted' => TRUE,
        'accepted_at' => $this->time->getRequestTime(),
        'ip_address' => $ip ?: NULL,
        'user_agent' => $ua ?: NULL,
        'session_id' => $session_id,
      ]);
      $entity->save();
    }
    catch (\Throwable $e) {
      $logger = $this->loggerFactory->get('myeventlane_legal');
      Error::logException($logger, $e);
      $logger->error('Consent audit write failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Do not rethrow: audit must not block entity save.
    }
  }

  /**
   * Whether to record an RSVP consent event (skips if duplicate within window).
   *
   * Prevents double-submit spam. Does not suppress legitimate re-consent when
   * version changed.
   *
   * @param array $context
   *   Keys: email, source (must be 'rsvp'), terms_version, privacy_version.
   *
   * @return bool
   *   TRUE if the consent should be recorded, FALSE to skip.
   */
  public function shouldRecordRsvpConsent(array $context): bool {
    $email = $context['email'] ?? NULL;
    $source = $context['source'] ?? NULL;
    $terms_version = $context['terms_version'] ?? NULL;
    $privacy_version = $context['privacy_version'] ?? NULL;

    if (empty($email) || $source !== 'rsvp' || empty($terms_version) || empty($privacy_version)) {
      return TRUE;
    }

    return !$this->isDuplicate($email, 'rsvp', $terms_version, $privacy_version);
  }

  /**
   * Checks if an identical event exists within the duplicate window.
   *
   * For RSVP: matches email, source, terms_version, privacy_version, accepted=1.
   */
  private function isDuplicate(string $email, string $source, string $terms_version, string $privacy_version): bool {
    $cutoff = $this->time->getRequestTime() - self::DUPLICATE_WINDOW_SECONDS;

    try {
      $storage = $this->entityTypeManager->getStorage('legal_consent_event');
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('email', $email)
        ->condition('source', $source)
        ->condition('terms_version', $terms_version)
        ->condition('privacy_version', $privacy_version)
        ->condition('accepted_at', $cutoff, '>=')
        ->range(0, 1);
      if ($source === 'rsvp') {
        $query->condition('accepted', 1);
      }
      $ids = $query->execute();

      return !empty($ids);
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

}
