<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\myeventlane_core\Service\EventDateTimeResolver;
use Drupal\myeventlane_event_state\Service\EventStateResolver;
use Drupal\myeventlane_event_state\Service\EventStateResolverInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Canonical rules for whether an event belongs on public discovery surfaces.
 *
 * Views should continue to express these rules as filters where possible; this
 * service is the single PHP source of truth for controllers, APIs, and SEO.
 */
final class PublicEventVisibility {

  public const VISIBILITY_PUBLIC = 'public';
  public const VISIBILITY_UNLISTED = 'unlisted';
  public const VISIBILITY_PRIVATE = 'private';
  public const VISIBILITY_PASSCODE = 'passcode';

  private const ALLOWED_VISIBILITY_VALUES = [
    self::VISIBILITY_PUBLIC,
    self::VISIBILITY_UNLISTED,
    self::VISIBILITY_PRIVATE,
    self::VISIBILITY_PASSCODE,
  ];

  /**
   * field_event_state values that must never appear in public listings.
   */
  private const EXCLUDED_STATES = [
    EventStateResolver::STATE_DRAFT,
    EventStateResolver::STATE_CANCELLED,
    EventStateResolver::STATE_ARCHIVED,
  ];

  public function __construct(
    private readonly EventStateResolverInterface $eventStateResolver,
    private readonly TimeInterface $time,
    private readonly ?EventPasscodeAccess $passcodeAccess = NULL,
    private readonly ?EventDateTimeResolver $eventDateTime = NULL,
  ) {}

  /**
   * Obvious internal staging markers in titles (fallback when no status field).
   *
   * @var list<string>
   */
  private const INTERNAL_TITLE_MARKERS = [
    'test',
    'demo',
    'copy',
    'untitled',
  ];

  /**
   * Returns the canonical visibility value for an event.
   *
   * Empty or missing field is treated as public for backwards compatibility.
   */
  public function getVisibility(NodeInterface $event): string {
    if ($event->bundle() !== 'event') {
      return self::VISIBILITY_PUBLIC;
    }

    if (!$event->hasField('field_event_visibility') || $event->get('field_event_visibility')->isEmpty()) {
      return self::VISIBILITY_PUBLIC;
    }

    $value = (string) $event->get('field_event_visibility')->value;

    if (!in_array($value, self::ALLOWED_VISIBILITY_VALUES, TRUE)) {
      return self::VISIBILITY_PUBLIC;
    }

    return $value;
  }

  public function isPublic(NodeInterface $event): bool {
    return $this->getVisibility($event) === self::VISIBILITY_PUBLIC;
  }

  public function isUnlisted(NodeInterface $event): bool {
    return $this->getVisibility($event) === self::VISIBILITY_UNLISTED;
  }

  public function isPrivate(NodeInterface $event): bool {
    return $this->getVisibility($event) === self::VISIBILITY_PRIVATE;
  }

  public function isPasscodeProtected(NodeInterface $event): bool {
    return $this->getVisibility($event) === self::VISIBILITY_PASSCODE;
  }

  /**
   * Whether a published event may appear on public discovery listings.
   *
   * Enforces both lifecycle state and visibility rules.
   */
  public function isPubliclyListable(NodeInterface $event): bool {
    if ($event->bundle() !== 'event' || !$event->isPublished()) {
      return FALSE;
    }

    if ($this->isExcludedLifecycleState($event)) {
      return FALSE;
    }

    if ($this->hasEnded($event)) {
      return FALSE;
    }

    if ($this->hasInternalMarkerTitle($event->label())) {
      return FALSE;
    }

    if ($this->getVisibility($event) !== self::VISIBILITY_PUBLIC) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Whether an event is viewable via direct URL by the given account.
   *
   * - public/unlisted: viewable by anyone (anonymous included)
   * - private: viewable only by owner, vendor, admin, staff
   * - passcode: viewable after valid passcode unlock OR by privileged accounts
   */
  public function isDirectlyViewable(NodeInterface $event, AccountInterface $account, ?Request $request = NULL): bool {
    if ($event->bundle() !== 'event') {
      return TRUE;
    }

    if (!$event->isPublished()) {
      return FALSE;
    }

    $visibility = $this->getVisibility($event);

    if ($visibility === self::VISIBILITY_PUBLIC || $visibility === self::VISIBILITY_UNLISTED) {
      return TRUE;
    }

    if ($this->isPrivilegedAccount($event, $account)) {
      return TRUE;
    }

    if ($visibility === self::VISIBILITY_PASSCODE) {
      return $this->isPasscodeUnlocked($event, $request);
    }

    return FALSE;
  }

  /**
   * Whether the user can access the booking flow for this event.
   *
   * - public/unlisted: allowed (booking resolver decides availability)
   * - private: owner/vendor/admin/staff only
   * - passcode: allowed after passcode unlock OR by privileged accounts
   */
  public function canBook(NodeInterface $event, AccountInterface $account, ?Request $request = NULL): bool {
    if ($event->bundle() !== 'event' || !$event->isPublished()) {
      return FALSE;
    }

    $visibility = $this->getVisibility($event);

    if ($visibility === self::VISIBILITY_PUBLIC || $visibility === self::VISIBILITY_UNLISTED) {
      return TRUE;
    }

    if ($this->isPrivilegedAccount($event, $account)) {
      return TRUE;
    }

    if ($visibility === self::VISIBILITY_PASSCODE) {
      return $this->isPasscodeUnlocked($event, $request);
    }

    return FALSE;
  }

  /**
   * Whether the passcode gate has been unlocked in the current session.
   */
  public function isPasscodeUnlocked(NodeInterface $event, ?Request $request = NULL): bool {
    if ($this->passcodeAccess === NULL) {
      return FALSE;
    }

    return $this->passcodeAccess->isUnlocked($event, $request);
  }

  /**
   * Whether the event should be indexable by search engines (JSON-LD, sitemap).
   */
  public function isSeoIndexable(NodeInterface $event): bool {
    return $this->isPubliclyListable($event);
  }

  /**
   * Whether the event should have noindex headers applied.
   *
   * Unlisted, private, and passcode events must never be indexed, even if
   * accessible via direct URL.
   */
  public function shouldNoindex(NodeInterface $event): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }

    $visibility = $this->getVisibility($event);

    return $visibility !== self::VISIBILITY_PUBLIC;
  }

  /**
   * Whether the event should appear in public API responses.
   */
  public function isApiVisible(NodeInterface $event): bool {
    return $this->isPubliclyListable($event);
  }

  /**
   * Whether a title contains obvious internal staging markers.
   */
  public function hasInternalMarkerTitle(string $title): bool {
    $normalized = trim(mb_strtolower($title));
    if ($normalized === '') {
      return TRUE;
    }

    foreach (self::INTERNAL_TITLE_MARKERS as $marker) {
      if ($normalized === $marker) {
        return TRUE;
      }
      if (str_starts_with($normalized, $marker . ' ')
        || str_starts_with($normalized, $marker . '-')
        || str_contains($normalized, ' ' . $marker . ' ')
        || str_contains($normalized, ' ' . $marker)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * SQL LIKE patterns for excluding internal marker titles in Views queries.
   *
   * @return list<string>
   */
  public function getInternalTitleSqlLikePatterns(): array {
    $patterns = [];
    foreach (self::INTERNAL_TITLE_MARKERS as $marker) {
      $patterns[] = $marker;
      $patterns[] = $marker . ' %';
      $patterns[] = $marker . '-%';
      $patterns[] = '% ' . $marker;
      $patterns[] = '% ' . $marker . ' %';
    }

    return $patterns;
  }

  /**
   * Whether the event is still in progress or upcoming (not past).
   */
  public function isUpcomingOrCurrent(NodeInterface $event): bool {
    return !$this->hasEnded($event);
  }

  /**
   * field_event_state values excluded from public discovery (for Views filters).
   *
   * @return list<string>
   */
  public function getExcludedLifecycleStates(): array {
    return self::EXCLUDED_STATES;
  }

  /**
   * Checks stored lifecycle state on the event node.
   */
  private function isExcludedLifecycleState(NodeInterface $event): bool {
    if (!$event->hasField('field_event_state') || $event->get('field_event_state')->isEmpty()) {
      return FALSE;
    }

    $state = (string) $event->get('field_event_state')->value;
    if (in_array($state, self::EXCLUDED_STATES, TRUE)) {
      return TRUE;
    }

    return $state === EventStateResolver::STATE_ENDED;
  }

  /**
   * Whether the event has ended by stored state or end datetime.
   */
  private function hasEnded(NodeInterface $event): bool {
    if ($event->hasField('field_event_state') && !$event->get('field_event_state')->isEmpty()) {
      if ((string) $event->get('field_event_state')->value === EventStateResolver::STATE_ENDED) {
        return TRUE;
      }
    }

    if ($event->hasField('field_event_end') && !$event->get('field_event_end')->isEmpty()) {
      $endTimestamp = $this->eventDateTime?->getFieldTimestamp(
        $event,
        'field_event_end',
      );
      if ($this->eventDateTime === NULL) {
        $endTimestamp = $event->get('field_event_end')->date?->getTimestamp();
      }
      if ($endTimestamp !== NULL && $endTimestamp < $this->time->getRequestTime()) {
        return TRUE;
      }
    }

    return $this->eventStateResolver->resolveState($event) === EventStateResolver::STATE_ENDED;
  }

  /**
   * Whether the account has privileged access to the event.
   *
   * Owners, vendor admins, site admins, and staff bypass visibility rules.
   */
  private function isPrivilegedAccount(NodeInterface $event, AccountInterface $account): bool {
    if ($account->hasPermission('administer nodes')) {
      return TRUE;
    }

    if ($account->hasPermission('bypass node access')) {
      return TRUE;
    }

    if ((int) $event->getOwnerId() === (int) $account->id() && (int) $account->id() > 0) {
      return TRUE;
    }

    if ($account->hasPermission('manage vendor events')) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Replaces obvious placeholder copy for public event display surfaces.
   *
   * Returns NULL when the source text should not be shown publicly.
   */
  public function sanitizePublicDisplayText(?string $text): ?string {
    if ($text === NULL || trim($text) === '') {
      return NULL;
    }

    $plain = trim(strip_tags($text));
    if ($plain === '') {
      return NULL;
    }

    $markers = [
      '[date]',
      '[time]',
      '[venue]',
      'lorem ipsum',
    ];
    foreach ($markers as $marker) {
      if (stripos($plain, $marker) !== FALSE) {
        return NULL;
      }
    }

    return $text;
  }

  /**
   * Fallback copy when public teaser/highlight text is withheld.
   */
  public function publicDisplayFallbackMessage(): string {
    return (string) new TranslatableMarkup('More details coming soon.');
  }

  /**
   * Validates and normalizes a visibility value for storage.
   *
   * Returns 'public' for invalid/unknown values.
   */
  public static function normalizeVisibilityValue(string $value): string {
    return in_array($value, self::ALLOWED_VISIBILITY_VALUES, TRUE) ? $value : self::VISIBILITY_PUBLIC;
  }

}
