<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a coarse identity / commerce intent for auth and onboarding redirects.
 *
 * Keys: mel_intent (preferred), intent (alias). Matches priority used in
 * myeventlane_auth_user_login() for query and POST body.
 *
 * Values are lowercase snake-case strings; unknown values map to browse.
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
   * Reads intent from a request (query then request body, mel_intent then intent).
   */
  public function resolveFromRequest(Request $request): string {
    foreach ([
      [$request->query, 'mel_intent'],
      [$request->request, 'mel_intent'],
      [$request->query, 'intent'],
      [$request->request, 'intent'],
    ] as $pair) {
      [$bag, $key] = $pair;
      $raw = $bag->get($key);
      if (is_string($raw) && $raw !== '') {
        return $this->normalizeIntent($raw);
      }
    }
    return $this->normalizeIntent('');
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
