<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\Session\SessionManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Per-browser-session grants for hidden / access-code tiers and waitlist claims.
 */
final class TicketBookingSessionService {

  private const SESSION_KEY = 'mel_ticket_booking_access';

  private readonly \Closure $isCommandLine;
  private readonly \Closure $headersSent;
  private bool $sessionUnavailable = FALSE;

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly SessionManagerInterface $sessionManager,
    ?callable $isCommandLine = NULL,
    ?callable $headersSent = NULL,
  ) {
    $this->isCommandLine = $isCommandLine !== NULL
      ? \Closure::fromCallable($isCommandLine)
      : static fn (): bool => PHP_SAPI === 'cli';
    $this->headersSent = $headersSent !== NULL
      ? \Closure::fromCallable($headersSent)
      : static fn (): bool => headers_sent();
  }

  /**
   * @return list<int>
   */
  public function getUnlockedTierIds(int $eventId): array {
    $bucket = $this->getBucket($eventId);
    $tiers = $bucket['tiers'] ?? [];
    return array_values(array_unique(array_map('intval', is_array($tiers) ? $tiers : [])));
  }

  /**
   * Records tiers unlocked by a validated access code (and metadata for re-validation).
   *
   * @param list<int> $tierIds
   */
  public function recordAccessGrant(int $eventId, int $accessCodeId, int $productId, array $tierIds): void {
    $this->startSession();
    $session = $this->getSession();
    if (!$session) {
      return;
    }
    $clean = [];
    foreach ($tierIds as $id) {
      $id = (int) $id;
      if ($id > 0) {
        $clean[] = $id;
      }
    }
    $data = $session->get(self::SESSION_KEY, []);
    if (!is_array($data)) {
      $data = [];
    }
    $prev = is_array($data[$eventId] ?? NULL) ? $data[$eventId] : [];
    $data[$eventId] = array_merge($prev, [
      'tiers' => array_values(array_unique($clean)),
      'access_code_id' => $accessCodeId,
      'access_product_id' => $productId,
    ]);
    $session->set(self::SESSION_KEY, $data);
  }

  /**
   * @param list<int> $tierIds
   *
   * @deprecated Use recordAccessGrant() so session grants can be re-validated against the code row.
   */
  public function addUnlockedTiers(int $eventId, array $tierIds): void {
    $this->startSession();
    $session = $this->getSession();
    if (!$session) {
      return;
    }
    $all = $this->getUnlockedTierIds($eventId);
    foreach ($tierIds as $id) {
      $id = (int) $id;
      if ($id > 0) {
        $all[] = $id;
      }
    }
    $data = $session->get(self::SESSION_KEY, []);
    if (!is_array($data)) {
      $data = [];
    }
    $prev = is_array($data[$eventId] ?? NULL) ? $data[$eventId] : [];
    $data[$eventId] = array_merge($prev, [
      'tiers' => array_values(array_unique($all)),
    ]);
    $session->set(self::SESSION_KEY, $data);
  }

  public function getAccessCodeId(int $eventId): ?int {
    $bucket = $this->getBucket($eventId);
    $id = $bucket['access_code_id'] ?? NULL;
    return is_numeric($id) ? (int) $id : NULL;
  }

  public function getAccessProductId(int $eventId): ?int {
    $bucket = $this->getBucket($eventId);
    $id = $bucket['access_product_id'] ?? NULL;
    return is_numeric($id) ? (int) $id : NULL;
  }

  public function setWaitlistClaimEntryId(int $eventId, int $entryId): void {
    $this->startSession();
    $session = $this->getSession();
    if (!$session) {
      return;
    }
    $data = $session->get(self::SESSION_KEY, []);
    if (!is_array($data)) {
      $data = [];
    }
    $data[$eventId] = array_merge(is_array($data[$eventId] ?? NULL) ? $data[$eventId] : [], [
      'waitlist_entry' => $entryId,
    ]);
    $session->set(self::SESSION_KEY, $data);
  }

  public function getWaitlistClaimEntryId(int $eventId): ?int {
    $bucket = $this->getBucket($eventId);
    $id = $bucket['waitlist_entry'] ?? NULL;
    return is_numeric($id) ? (int) $id : NULL;
  }

  public function clearWaitlistClaimEntryId(int $eventId): void {
    $this->startSession();
    $session = $this->getSession();
    if (!$session) {
      return;
    }
    $data = $session->get(self::SESSION_KEY, []);
    if (!is_array($data) || !isset($data[$eventId]) || !is_array($data[$eventId])) {
      return;
    }
    unset($data[$eventId]['waitlist_entry']);
    $session->set(self::SESSION_KEY, $data);
  }

  /**
   * @return array<string, mixed>
   */
  private function getBucket(int $eventId): array {
    $session = $this->getSession();
    if (!$session) {
      return [];
    }
    $data = $session->get(self::SESSION_KEY, []);
    if (!is_array($data) || !isset($data[$eventId]) || !is_array($data[$eventId])) {
      return [];
    }
    return $data[$eventId];
  }

  private function getSession(): ?SessionInterface {
    // Public ticket availability is also evaluated by cron and CLI indexers.
    // Those contexts must never try to start or read an HTTP session.
    if (($this->isCommandLine)() || $this->sessionUnavailable) {
      return NULL;
    }

    if (!$this->sessionManager->isStarted() && ($this->headersSent)()) {
      $this->sessionUnavailable = TRUE;
      return NULL;
    }

    $request = $this->requestStack->getCurrentRequest();
    if (!$request || !$request->hasSession()) {
      return NULL;
    }

    return $request->getSession();
  }

  private function startSession(): void {
    if (($this->isCommandLine)() || $this->sessionUnavailable) {
      return;
    }

    if ($this->sessionManager->isStarted()) {
      return;
    }
    if (($this->headersSent)()) {
      $this->sessionUnavailable = TRUE;
      return;
    }
    $request = $this->requestStack->getCurrentRequest();
    if ($request && $request->hasSession()) {
      try {
        $this->sessionManager->start();
      }
      catch (\RuntimeException) {
        $this->sessionUnavailable = TRUE;
        // Session-backed grants are optional. Fail closed when the response
        // has already started or no writable session is available.
      }
    }
  }

}
