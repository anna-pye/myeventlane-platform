<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\NotificationSurface;
use Drupal\user\UserInterface;

/**
 * Decides how a delivery should surface (toast vs bell vs quiet inbox).
 *
 * Uses existing mel_notification fields: priority, priority_score, suppression_key,
 * group_key, channels. Outcomes map to per-delivery {@see NotificationSurface} values.
 * User preferences (see {@see NotificationPreferenceService}) may suppress non-critical
 * categories or downgrade surfaces; ticket-type notifications cannot be fully suppressed.
 */
final class NotificationDecisionEngine {

  private const TOAST_SUPPRESS_TTL = 900;

  private const GROUP_WINDOW_TTL = 600;

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NotificationPreferenceService $preferenceService,
  ) {}

  /**
   * Computes the surface for a single recipient at dispatch time (preferences applied).
   *
   * @deprecated Prefer resolveDeliveryPresentation(); retained for narrow call sites.
   */
  public function resolveSurface(MelNotification $notification, int $recipientUid): string {
    return $this->resolveDeliveryPresentation($notification, $recipientUid)['surface'];
  }

  /**
   * Full presentation decision including UI suppression for inbox/bell/toast.
   *
   * @return array{surface: string, suppressed: bool}
   */
  public function resolveDeliveryPresentation(MelNotification $notification, int $recipientUid): array {
    $base = $this->resolveBaseSurface($notification, $recipientUid);
    if ($recipientUid < 1) {
      return ['surface' => $base, 'suppressed' => FALSE];
    }
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->entityTypeManager->getStorage('user')->load($recipientUid);
    if (!$user instanceof UserInterface) {
      return ['surface' => $base, 'suppressed' => FALSE];
    }
    return $this->preferenceService->applyPreferences($notification, $user, $base);
  }

  /**
   * Engine-only surface (before user preference layer).
   */
  private function resolveBaseSurface(MelNotification $notification, int $recipientUid): string {
    if ($recipientUid < 1) {
      return NotificationSurface::INBOX_ONLY;
    }

    if (!$this->notificationHasToastChannel($notification)) {
      return NotificationSurface::BELL_INBOX;
    }

    if ((string) $notification->get('priority')->value === MelNotification::PRIORITY_HIGH) {
      return NotificationSurface::TOAST_INBOX;
    }

    $suppressionKey = trim((string) $notification->get('suppression_key')->value);
    if ($suppressionKey !== '' && (string) $notification->get('priority')->value === MelNotification::PRIORITY_LOW) {
      $cid = $this->toastCooldownId($recipientUid, $suppressionKey);
      if ($this->cache->get($cid)) {
        $this->logger->debug('Toast suppressed for uid @uid (suppression_key @key).', [
          '@uid' => (string) $recipientUid,
          '@key' => $suppressionKey,
        ]);
        return NotificationSurface::INBOX_ONLY;
      }
      $this->cache->set($cid, 1, $this->time->getRequestTime() + self::TOAST_SUPPRESS_TTL);
    }

    $groupKey = trim((string) $notification->get('group_key')->value);
    if ($groupKey !== '' && (int) $notification->get('priority_score')->value < 25) {
      $gcid = $this->groupWindowId($recipientUid, $groupKey);
      if ($this->cache->get($gcid)) {
        return NotificationSurface::BELL_INBOX;
      }
      $this->cache->set($gcid, 1, $this->time->getRequestTime() + self::GROUP_WINDOW_TTL);
    }

    if ((string) $notification->get('type')->value === MelNotification::TYPE_SYSTEM
      && (string) $notification->get('priority')->value === MelNotification::PRIORITY_LOW
      && $suppressionKey === '') {
      return NotificationSurface::BELL_INBOX;
    }

    return NotificationSurface::TOAST_INBOX;
  }

  private function notificationHasToastChannel(MelNotification $notification): bool {
    foreach ($notification->get('channels')->getValue() as $item) {
      if (($item['value'] ?? '') === 'toast') {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function toastCooldownId(int $uid, string $suppressionKey): string {
    return 'myeventlane_notifications:toast_cd:' . hash('sha256', $uid . ':' . $suppressionKey);
  }

  private function groupWindowId(int $uid, string $groupKey): string {
    return 'myeventlane_notifications:grp_win:' . hash('sha256', $uid . ':' . $groupKey);
  }

}
