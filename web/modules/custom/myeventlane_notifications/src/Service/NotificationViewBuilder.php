<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\Entity\MelNotificationDelivery;
use Drupal\myeventlane_notifications\NotificationDomain;
use Drupal\myeventlane_notifications\NotificationSurface;

/**
 * Presenter for bell preview, inbox rows, grouping, and badge helpers.
 */
final class NotificationViewBuilder {

  private const PREVIEW_MAX = 180;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UrlGeneratorInterface $urlGenerator,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  public function messagePreview(string $message): string {
    $plain = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message)));
    if ($plain === '') {
      return '';
    }
    return $this->truncatePlain($plain, self::PREVIEW_MAX);
  }

  private function truncatePlain(string $text, int $max): string {
    if ($max < 1) {
      return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
      return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
    if (strlen($text) > $max) {
      return rtrim(substr($text, 0, $max - 1)) . '…';
    }
    return $text;
  }

  /**
   * @return array<string, mixed>|null
   *   Keys: label (string), url (string absolute).
   */
  public function buildActionFromNotification(MelNotification $notification): ?array {
    $label = trim((string) $notification->get('action_label')->value);
    if ($label === '') {
      return NULL;
    }

    $routeName = trim((string) $notification->get('route_name')->value);
    if ($routeName !== '') {
      $params = $this->decodeRouteParameters($notification);
      try {
        if (!$this->routeProvider->getRouteByName($routeName)) {
          return NULL;
        }
        $url = Url::fromRoute($routeName, $params);
        return [
          'label' => $label,
          'url' => $url->setAbsolute()->toString(),
        ];
      }
      catch (\Throwable) {
        // Fall through to action_uri.
      }
    }

    $uri = trim((string) $notification->get('action_uri')->value);
    if ($uri === '') {
      return NULL;
    }
    if (!str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
      return NULL;
    }
    try {
      $url = Url::fromUserInput($uri);
      return [
        'label' => $label,
        'url' => $url->setAbsolute()->toString(),
      ];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @return array<string, scalar>
   */
  private function decodeRouteParameters(MelNotification $notification): array {
    $raw = trim((string) $notification->get('route_parameters')->value);
    if ($raw === '') {
      return [];
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($decoded)) {
        return [];
      }
      $out = [];
      foreach ($decoded as $key => $value) {
        if (is_string($key) && (is_string($value) || is_int($value))) {
          $out[$key] = $value;
        }
      }
      return $out;
    }
    catch (\Throwable) {
      return [];
    }
  }

  public function isToastEligible(MelNotificationDelivery $delivery): bool {
    if (!empty($delivery->get('suppressed')->value)) {
      return FALSE;
    }
    $surface = (string) $delivery->get('surface')->value;
    return $surface === NotificationSurface::TOAST_INBOX;
  }

  /**
   * Action link that records analytics before redirecting to the internal path.
   *
   * @return array<string, string>|null
   */
  public function buildTrackedActionForDelivery(MelNotificationDelivery $delivery, MelNotification $notification): ?array {
    $base = $this->buildActionFromNotification($notification);
    if ($base === NULL) {
      return NULL;
    }
    return [
      'label' => $base['label'],
      'url' => $this->urlGenerator->generateFromRoute(
        'myeventlane_notifications.action_go',
        ['delivery' => (int) $delivery->id()],
        ['absolute' => TRUE],
      ),
    ];
  }

  /**
   * @param list<int> $delivery_ids
   *
   * @return list<array<string, mixed>>
   */
  public function buildRowsForDeliveries(array $delivery_ids, int $recipient_uid): array {
    if ($delivery_ids === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery[] $deliveries */
    $deliveries = $storage->loadMultiple($delivery_ids);
    $notifStorage = $this->entityTypeManager->getStorage('mel_notification');
    $rows = [];
    foreach ($delivery_ids as $did) {
      $delivery = $deliveries[$did] ?? NULL;
      if (!$delivery instanceof MelNotificationDelivery) {
        continue;
      }
      if ((int) $delivery->get('recipient_uid')->target_id !== $recipient_uid) {
        continue;
      }
      $notification = $notifStorage->load($delivery->get('notification_id')->target_id);
      if (!$notification instanceof MelNotification) {
        continue;
      }
      $rows[] = $this->buildRow($delivery, $notification);
    }
    return $rows;
  }

  /**
   * @return array<string, mixed>
   */
  public function buildRow(MelNotificationDelivery $delivery, MelNotification $notification): array {
    $status = (string) $delivery->get('status')->value;
    $isUnread = in_array($status, [
      MelNotificationDelivery::STATUS_SENT,
      MelNotificationDelivery::STATUS_PENDING,
    ], TRUE);

    $message = (string) $notification->get('message')->value;
    $action = $this->buildTrackedActionForDelivery($delivery, $notification);
    $delivered = (int) ($delivery->get('delivered_at')->value ?? 0);
    $groupKey = trim((string) $notification->get('group_key')->value);
    $domain = (string) $notification->get('domain')->value;
    $context = (string) $notification->get('context')->value;

    return [
      'delivery_id' => (int) $delivery->id(),
      'notification_id' => (int) $notification->id(),
      'title' => (string) $notification->get('title')->value,
      'message' => $message,
      'message_preview' => $this->messagePreview($message),
      'type' => (string) $notification->get('type')->value,
      'context' => $context,
      'domain' => $domain,
      'icon' => $this->iconForDomain($domain, (string) $notification->get('type')->value),
      'badge' => $this->badgeForDomain($domain),
      'created' => (int) $notification->get('created')->value,
      'delivered_at' => $delivered,
      'priority_score' => (int) $notification->get('priority_score')->value,
      'status' => $status,
      'is_unread' => $isUnread,
      'toast_eligible' => $this->isToastEligible($delivery),
      'surface' => (string) $delivery->get('surface')->value,
      'action' => $action,
      'group_key' => $groupKey,
    ];
  }

  /**
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, mixed>>
   *   Each group: label (date), items (list of rows), optional group_summary.
   */
  public function groupRowsByDate(array $rows): array {
    if ($rows === []) {
      return [];
    }
    $groups = [];
    foreach ($rows as $row) {
      $ts = (int) ($row['delivered_at'] ?? $row['created'] ?? 0);
      $day = $ts > 0 ? gmdate('Y-m-d', $ts) : 'unknown';
      if (!isset($groups[$day])) {
        $groups[$day] = [
          'day_key' => $day,
          'sort_ts' => $ts,
          'items' => [],
        ];
      }
      $groups[$day]['items'][] = $row;
    }
    uasort($groups, static function (array $a, array $b): int {
      return ($b['sort_ts'] ?? 0) <=> ($a['sort_ts'] ?? 0);
    });
    return array_values($groups);
  }

  /**
   * Backend grouping: collapse rows that share a non-empty group_key when
   * delivered inside the same calendar day.
   *
   * @param list<array<string, mixed>> $rows
   *
   * @return list<array<string, mixed>>
   *   Rows may be replaced with a synthetic summary row (is_group_summary).
   */
  public function applyGroupSummaries(array $rows): array {
    if ($rows === []) {
      return [];
    }
    $out = [];
    $buffer = [];
    $bufferKey = NULL;
    $flush = static function () use (&$out, &$buffer, &$bufferKey): void {
      if ($buffer === []) {
        return;
      }
      if (count($buffer) === 1) {
        $out[] = $buffer[0];
      }
      else {
        $first = $buffer[0];
        $count = count($buffer);
        $out[] = [
          'delivery_id' => (int) ($first['delivery_id'] ?? 0),
          'notification_id' => (int) ($first['notification_id'] ?? 0),
          'title' => (string) ($first['title'] ?? ''),
          'message_preview' => '',
          'type' => (string) ($first['type'] ?? ''),
          'context' => (string) ($first['context'] ?? ''),
          'domain' => (string) ($first['domain'] ?? ''),
          'icon' => $first['icon'] ?? 'bell',
          'badge' => $first['badge'] ?? 'bell',
          'created' => (int) ($first['created'] ?? 0),
          'delivered_at' => (int) ($first['delivered_at'] ?? 0),
          'priority_score' => (int) ($first['priority_score'] ?? 0),
          'status' => (string) ($first['status'] ?? ''),
          'is_unread' => (bool) array_reduce($buffer, static function (bool $carry, array $r): bool {
            return $carry || !empty($r['is_unread']);
          }, FALSE),
          'toast_eligible' => (bool) array_reduce($buffer, static function (bool $carry, array $r): bool {
            return $carry || !empty($r['toast_eligible']);
          }, FALSE),
          'surface' => (string) ($first['surface'] ?? NotificationSurface::BELL_INBOX),
          'action' => $first['action'] ?? NULL,
          'group_key' => (string) ($first['group_key'] ?? ''),
          'is_group_summary' => TRUE,
          'group_count' => $count,
          'group_summary_label' => (string) $count . ' updates',
        ];
      }
      $buffer = [];
      $bufferKey = NULL;
    };

    foreach ($rows as $row) {
      $gk = trim((string) ($row['group_key'] ?? ''));
      $day = (int) ($row['delivered_at'] ?? $row['created'] ?? 0);
      $dayKey = $day > 0 ? gmdate('Y-m-d', $day) : '';
      $composite = $gk !== '' ? $gk . '|' . $dayKey : '';

      if ($composite === '' || $gk === '') {
        $flush();
        $out[] = $row;
        continue;
      }

      if ($bufferKey !== $composite) {
        $flush();
        $bufferKey = $composite;
      }
      $buffer[] = $row;
    }
    $flush();

    return $out;
  }

  public function iconForDomain(string $domain, string $legacyType = ''): string {
    return match ($domain) {
      NotificationDomain::TICKETS => 'ticket',
      NotificationDomain::ORDERS => 'order',
      NotificationDomain::SALES => 'sale',
      NotificationDomain::REFUNDS => 'refund',
      NotificationDomain::RSVPS => 'rsvp',
      NotificationDomain::FOLLOWERS => 'follower',
      NotificationDomain::EVENT_UPDATES => 'event-update',
      NotificationDomain::BOOSTS => 'boost',
      NotificationDomain::REMINDERS => 'reminder',
      NotificationDomain::PLATFORM => 'platform',
      default => $this->iconForType($legacyType),
    };
  }

  public function badgeForDomain(string $domain): string {
    return $this->iconForDomain($domain);
  }

  public function iconForType(string $type): string {
    return match ($type) {
      MelNotification::TYPE_TICKET => 'ticket',
      MelNotification::TYPE_EVENT => 'calendar',
      MelNotification::TYPE_REMINDER => 'clock',
      MelNotification::TYPE_PROMO => 'spark',
      MelNotification::TYPE_SYSTEM => 'platform',
      default => 'bell',
    };
  }

}
