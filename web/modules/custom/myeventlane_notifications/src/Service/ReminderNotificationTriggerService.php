<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\NotificationContext;
use Drupal\myeventlane_notifications\NotificationDomain;
use Drupal\node\NodeInterface;

/**
 * In-app event reminders aligned with email reminder windows.
 */
final class ReminderNotificationTriggerService {

  public function __construct(
    private readonly NotificationManager $notificationManager,
    private readonly LoggerChannelInterface $logger,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * Queues a personal reminder notification for an order holder.
   *
   * @param string $reminderWindow
   *   Short key: 24h or 1h.
   */
  public function onOrderEventReminder(OrderInterface $order, NodeInterface $event, string $reminderWindow): void {
    $customerId = (int) $order->getCustomerId();
    if ($customerId < 1) {
      return;
    }

    $eventId = (int) $event->id();
    $orderId = (int) $order->id();

    [$title, $message, $actionContext] = match ($reminderWindow) {
      '1h' => [
        (string) $this->translation->translate('Event starts in 1 hour'),
        (string) $this->translation->translate('@event starts in about one hour.', ['@event' => $event->label()]),
        'event_reminder_1h',
      ],
      default => [
        (string) $this->translation->translate('Event starts in 24 hours'),
        (string) $this->translation->translate('@event starts tomorrow. See you there!', ['@event' => $event->label()]),
        'event_reminder_24h',
      ],
    };

    try {
      $notification = $this->notificationManager->createNotification([
        'type' => MelNotification::TYPE_REMINDER,
        'title' => $title,
        'message' => $message,
        'audience_type' => MelNotification::AUDIENCE_USER_IDS,
        'audience_data' => ['user_ids' => [$customerId]],
        'channels' => ['toast'],
        'status' => MelNotification::STATUS_DRAFT,
        'priority' => MelNotification::PRIORITY_NORMAL,
        'priority_score' => 50,
        'suppression_key' => 'inapp_reminder:' . $reminderWindow . ':' . $orderId . ':' . $eventId,
        'group_key' => 'event_reminder:' . $eventId,
        'context' => NotificationContext::PERSONAL,
        'domain' => NotificationDomain::REMINDERS,
        'action_label' => (string) $this->translation->translate('View event'),
        'route_name' => 'entity.node.canonical',
        'route_parameters' => ['node' => $eventId],
        'action_context' => $actionContext,
      ]);
      $this->notificationManager->queueNotification($notification);
    }
    catch (\RuntimeException) {
      // Suppressed duplicate.
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to queue in-app @window reminder for order @order: @message', [
        '@window' => $reminderWindow,
        '@order' => (string) $orderId,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
