<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\NotificationSurface;
use Drupal\user\UserInterface;

/**
 * Loads and applies per-user notification preferences (JSON on user entity).
 */
final class NotificationPreferenceService {

  public const FIELD_NAME = 'mel_notification_prefs';

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
      self::CATEGORY_TICKETS,
      self::CATEGORY_EVENTS,
      self::CATEGORY_REMINDERS,
      self::CATEGORY_PLATFORM,
      self::CATEGORY_PROMO,
    ];
  }

  /**
   * @var array<string, array{enabled: bool, surface: string}>
   */
  private const DEFAULT_PREFS = [
    self::CATEGORY_TICKETS => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::TOAST_INBOX,
    ],
    self::CATEGORY_EVENTS => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::CATEGORY_REMINDERS => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::INBOX_ONLY,
    ],
    self::CATEGORY_PLATFORM => [
      'enabled' => TRUE,
      'surface' => NotificationSurface::BELL_INBOX,
    ],
    self::CATEGORY_PROMO => [
      'enabled' => FALSE,
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
   * Maps mel_notification.type to preference category key.
   */
  public function categoryForNotificationType(string $type): string {
    return match ($type) {
      MelNotification::TYPE_TICKET => self::CATEGORY_TICKETS,
      MelNotification::TYPE_EVENT => self::CATEGORY_EVENTS,
      MelNotification::TYPE_REMINDER => self::CATEGORY_REMINDERS,
      MelNotification::TYPE_PROMO => self::CATEGORY_PROMO,
      MelNotification::TYPE_SYSTEM => self::CATEGORY_PLATFORM,
      default => self::CATEGORY_PLATFORM,
    };
  }

  /**
   * @return array{surface: string, suppressed: bool}
   */
  public function applyPreferences(MelNotification $notification, UserInterface $user, string $engineSurface): array {
    $type = (string) $notification->get('type')->value;
    $category = $this->categoryForNotificationType($type);
    $prefs = $this->getPreferences($user);
    $cat = $prefs[$category] ?? self::DEFAULT_PREFS[$category];
    $enabled = (bool) ($cat['enabled'] ?? TRUE);
    $userSurface = isset($cat['surface']) && is_string($cat['surface']) && in_array($cat['surface'], NotificationSurface::allowed(), TRUE)
      ? (string) $cat['surface']
      : NULL;

    if (!$enabled) {
      if ($type === MelNotification::TYPE_TICKET) {
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
