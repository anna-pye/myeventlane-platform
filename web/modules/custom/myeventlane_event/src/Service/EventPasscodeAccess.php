<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Canonical passcode hashing, verification, and session-based unlock for events.
 */
final class EventPasscodeAccess {

  private const SESSION_PREFIX = 'myeventlane.event_passcode.';

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Hashes a plaintext passcode for storage.
   */
  public function hashPasscode(string $passcode): string {
    return password_hash($passcode, PASSWORD_BCRYPT);
  }

  /**
   * Verifies a plaintext passcode against the stored hash on an event.
   */
  public function verifyPasscode(NodeInterface $event, string $passcode): bool {
    $hash = $this->getStoredHash($event);
    if ($hash === NULL) {
      return FALSE;
    }

    return password_verify($passcode, $hash);
  }

  /**
   * Whether the event requires a passcode to access.
   */
  public function requiresPasscode(NodeInterface $event): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    if (!$event->hasField('field_event_visibility') || $event->get('field_event_visibility')->isEmpty()) {
      return FALSE;
    }

    if ((string) $event->get('field_event_visibility')->value !== PublicEventVisibility::VISIBILITY_PASSCODE) {
      return FALSE;
    }

    return $this->getStoredHash($event) !== NULL;
  }

  /**
   * Marks the event as unlocked in the current session.
   *
   * Stores a fingerprint of the current hash so that changing the passcode
   * invalidates existing unlocks.
   */
  public function unlock(NodeInterface $event, ?Request $request = NULL): void {
    $session = $this->getSession($request);
    if ($session === NULL) {
      return;
    }

    $hash = $this->getStoredHash($event);
    $fingerprint = $hash !== NULL ? $this->fingerprint($hash) : '';
    $session->set($this->sessionKey($event), $fingerprint);
  }

  /**
   * Whether the event is currently unlocked in the session.
   */
  public function isUnlocked(NodeInterface $event, ?Request $request = NULL): bool {
    $session = $this->getSession($request);
    if ($session === NULL) {
      return FALSE;
    }

    $key = $this->sessionKey($event);
    if (!$session->has($key)) {
      return FALSE;
    }

    $stored_fingerprint = (string) $session->get($key);
    $hash = $this->getStoredHash($event);
    if ($hash === NULL) {
      return FALSE;
    }

    return $stored_fingerprint === $this->fingerprint($hash);
  }

  /**
   * Removes the unlock from the session.
   */
  public function clearUnlock(NodeInterface $event, ?Request $request = NULL): void {
    $session = $this->getSession($request);
    if ($session === NULL) {
      return;
    }

    $session->remove($this->sessionKey($event));
  }

  /**
   * Returns the stored bcrypt hash, or NULL if absent.
   */
  private function getStoredHash(NodeInterface $event): ?string {
    if (!$event->hasField('field_event_passcode_hash') || $event->get('field_event_passcode_hash')->isEmpty()) {
      return NULL;
    }

    $hash = (string) $event->get('field_event_passcode_hash')->value;
    return $hash !== '' ? $hash : NULL;
  }

  /**
   * Deterministic fingerprint of a hash for session storage.
   *
   * Avoids storing the full bcrypt hash in the session while still
   * invalidating when the hash changes.
   */
  private function fingerprint(string $hash): string {
    return hash('sha256', $hash);
  }

  private function sessionKey(NodeInterface $event): string {
    return self::SESSION_PREFIX . (int) $event->id();
  }

  private function getSession(?Request $request = NULL): ?SessionInterface {
    $request ??= $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }

    return $request->hasSession() ? $request->getSession() : NULL;
  }

}
