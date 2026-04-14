<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a coarse identity / commerce intent for auth and onboarding redirects.
 *
 * Query keys: mel_intent (preferred), intent (alias). Values are lowercase
 * snake-case strings; unknown values map to browse.
 */
final class IdentityIntentResolver {

  public const INTENT_BROWSE = 'browse';

  public const INTENT_BUY_TICKET = 'buy_ticket';

  public const INTENT_RSVP = 'rsvp';

  public const INTENT_CREATE_EVENT = 'create_event';

  /**
   * @var list<string>
   */
  private const KNOWN = [
    self::INTENT_BROWSE,
    self::INTENT_BUY_TICKET,
    self::INTENT_RSVP,
    self::INTENT_CREATE_EVENT,
  ];

  /**
   * Reads intent from a request (GET query).
   */
  public function resolveFromRequest(Request $request): string {
    $raw = $request->query->get('mel_intent');
    if (!is_string($raw) || $raw === '') {
      $raw = $request->query->get('intent');
    }
    return $this->normalizeIntent(is_string($raw) ? $raw : '');
  }

  /**
   * Normalizes a raw intent string to a known constant.
   */
  public function normalizeIntent(string $raw): string {
    $v = strtolower(trim($raw));
    if ($v === '') {
      return self::INTENT_BROWSE;
    }
    if (in_array($v, self::KNOWN, TRUE)) {
      return $v;
    }
    return self::INTENT_BROWSE;
  }

}
