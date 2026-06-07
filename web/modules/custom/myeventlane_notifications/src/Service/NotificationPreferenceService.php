<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\NotificationContext;
use Drupal\myeventlane_notifications\NotificationDomain;
use Drupal\myeventlane_notifications\NotificationSurface;
use Drupal\myeventlane_notifications\NotificationTaxonomy;
use Drupal\user\UserInterface;

/**
 * Loads and applies per-user notification preferences (JSON on user entity).
 */
final class NotificationPreferenceService {

  public const FIELD_NAME = 'mel_notification_prefs';

  // Personal.
  public const PERSONAL_TICKET_PURCHASES = 'personal_ticket_purchases';

  public const PERSONAL_ORDER_UPDATES = 'personal_order_updates';

  public const PERSONAL_EVENT_REMINDERS = 'personal_event_reminders';

  // Business.
  public const BUSINESS_SALES = 'business_sales';

  public const BUSINESS_REFUNDS = 'business_refunds';

  public const BUSINESS_RSVPS = 'business_rsvps';

  public const BUSINESS_FOLLOWERS = 'business_followers';

  public const BUSINESS_EVENT_UPDATES = 'business_event_updates';

  public const BUSINESS_BOOSTS = 'business_boosts';

  // Platform.
  public const PLATFORM_SECURITY = 'platform_security';

  public const PLATFORM_ACCOUNT = 'platform_account';

  public const PLATFORM_SYSTEM = 'platform_system';

  // Legacy keys (read-only migration).
  public const CATEGORY_TICKETS = 'tickets';

  public const CATEGORY_EVENTS = 'events';

  public const CATEGORY_REMINDERS = 'reminders';

  public const CATEGORY_PLATFORM = 'platform';

  public const CATEGORY_PROMO = 'promo';

  /**
   * @return list<string>
   */
  public static function categoryKeys(): array {
    return [
      self::PERSONAL_TICKET_PURCHASES,
      self::PERSONAL_ORDER_UPDATES,
      self::PERSONAL_EVENT_REMINDERS,
      self::BUSINESS_SALES,
      self::BUSINESS_REFUNDS,
      self::BUSINESS_RSVPS,
      self::BUSINESS_FOLLOWERS,
      self::BUSINESS_EVENT_UPDATES,
      self::BUSINESS_BOOSTS,
      self::PLATFORM_SECURITY,
      self::PLATFORM_ACCOUNT,
      self::PLATFORM_SYSTEM,
    ];
  }

  /**
   * @var array<string, array{enabled: bool, surface: string}>
   */
  private const DEFAULT_PREFS = [
    self::PERSONAL_TICKET_PURCHASES => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::TOAST_INBOX,
    ],
    self::PERSONAL_ORDER_UPDATES => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::PERSONAL_EVENT_REMINDERS => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::INBOX_ONLY,
    ],
    self::BUSINESS_SALES => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::TOAST_INBOX,
    ],
    self::BUSINESS_REFUNDS => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::BUSINESS_RSVPS => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::BUSINESS_FOLLOWERS => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::BUSINESS_EVENT_UPDATES => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::BUSINESS_BOOSTS => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::PLATFORM_SECURITY => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::PLATFORM_ACCOUNT => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::PLATFORM_SYSTEM => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::INBOX_ONLY,
    ],
  ];

  public function __construct(
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * @return array<string, array{enabled: bool, surface: string}>
   */
  public function getPreferences(UserInterface $user): array {
    $merged = self::DEFAULT_PREFS;
    if (!$user->hasField(self::FIELD_NAME)) {
      return $merged;
    }
    $raw = trim((string) $user->get(self::FIELD_NAME)->value);
    if ($raw === '') {
      return $merged;
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $e) {
      $this->logger->notice('Invalid MEL notification prefs JSON for user @uid: @message', [
        '@uid' => (string) $user->id(),
        '@message' => $e->getMessage(),
      ]);
      return $merged;
    }
    if (!is_array($decoded)) {
      return $merged;
    }

    $migrated = NotificationTaxonomy::migrateLegacyPreferences($decoded);
    foreach ($migrated as $key => $entry) {
      if (isset($merged[$key])) {
        $merged[$key] = array_merge($merged[$key], $entry);
      }
    }

    foreach ($merged as $key => $default) {
      if (!isset($decoded[$key]) || !is_array($decoded[$key])) {
        continue;
      }
      $entry = $decoded[$key];
      if (array_key_exists('enabled', $entry)) {
        $merged[$key]['enabled'] = (bool) $entry['enabled'];
      }
      if (isset($entry['surface']) && is_string($entry['surface'])) {
        $surface = $entry['surface'];
        if (in_array($surface, NotificationSurface::allowed(), TRUE)) {
          $merged[$key]['surface'] = $surface;
        }
      }
    }
    return $merged;
  }

  public function isEnabled(UserInterface $user, string $category): bool {
    $prefs = $this->getPreferences($user);
    return (bool) ($prefs[$category]['enabled'] ?? TRUE);
  }

  public function getSurfaceOverride(UserInterface $user, string $category): ?string {
    if (!$this->isEnabled($user, $category)) {
      return NULL;
    }
    $prefs = $this->getPreferences($user);
    $surface = $prefs[$category]['surface'] ?? NULL;
    if (is_string($surface) && in_array($surface, NotificationSurface::allowed(), TRUE)) {
      return $surface;
    }
    return NULL;
  }

  /**
   * Maps mel_notification context/domain to preference category key.
   */
  public function categoryForNotification(MelNotification $notification): string {
    $context = (string) $notification->get('context')->value;
    $domain = (string) $notification->get('domain')->value;
    if ($context === '' || $domain === '') {
      return $this->categoryForNotificationType((string) $notification->get('type')->value);
    }
    return NotificationTaxonomy::preferenceKey($context, $domain);
  }

  /**
   * Maps legacy mel_notification.type to preference category key.
   */
  public function categoryForNotificationType(string $type): string {
    $legacy = NotificationTaxonomy::fromLegacyType($type);
    return NotificationTaxonomy::preferenceKey($legacy['context'], $legacy['domain']);
  }

  /**
   * @return array{surface: string, suppressed: bool}
   */
  public function applyPreferences(MelNotification $notification, UserInterface $user, string $engineSurface): array {
    $category = $this->categoryForNotification($notification);
    $prefs = $this->getPreferences($user);
    $cat = $prefs[$category] ?? self::DEFAULT_PREFS[$category] ?? [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ];
    $enabled = (bool) ($cat['enabled'] ?? TRUE);
    $userSurface = isset($cat['surface']) && is_string($cat['surface']) && in_array($cat['surface'], NotificationSurface::allowed(), TRUE)
      ? (string) $cat['surface']
      : NULL;

    $domain = (string) $notification->get('domain')->value;
    $isTicket = $domain === NotificationDomain::TICKETS
      || (string) $notification->get('type')->value === MelNotification::TYPE_TICKET;

    if (!$enabled) {
      if ($isTicket) {
        return [
          'surface' => NotificationSurface::INBOX_ONLY,
          'suppressed' => FALSE,
        ];
      }
      return [
        'surface' => $engineSurface,
        'suppressed' => TRUE,
      ];
    }

    $surface = $userSurface !== NULL
      ? $this->leastIntrusiveSurface($engineSurface, $userSurface)
      : $engineSurface;

    return [
      'surface' => $surface,
      'suppressed' => FALSE,
    ];
  }

  /**
   * Prefer the less intrusive surface when combining engine output with user choice.
   */
  private function leastIntrusiveSurface(string $engine, string $user): string {
    $rank = [
      NotificationSurface::INBOX_ONLY => 0,
      NotificationSurface::BELL_INBOX => 1,
      NotificationSurface::TOAST_INBOX => 2,
    ];
    $re = $rank[$engine] ?? 1;
    $ru = $rank[$user] ?? 1;
    return $re <= $ru ? $engine : $user;
  }

}
