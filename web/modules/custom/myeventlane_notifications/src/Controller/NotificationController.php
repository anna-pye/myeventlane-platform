<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_notifications\Service\NotificationUserInboxService;
use Drupal\myeventlane_notifications\Service\NotificationViewBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Authenticated JSON API for unread toasts, bell preview, counts, and action tracking.
 */
final class NotificationController extends ControllerBase {

  private const UNREAD_HARD_LIMIT = 10;

  public function __construct(
    private readonly NotificationUserInboxService $userInbox,
    private readonly NotificationViewBuilder $viewBuilder,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_notifications.user_inbox'),
      $container->get('myeventlane_notifications.view_builder'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_notifications'),
    );
  }

  /**
   * Returns unread in-app deliveries for the current user (toast polling).
   */
  public function unread(): CacheableJsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      throw new AccessDeniedHttpException();
    }

    $ids = $this->userInbox->getUnreadDeliveryIds($uid, self::UNREAD_HARD_LIMIT);
    $rows = $this->viewBuilder->buildRowsForDeliveries($ids, $uid);
    $payload = [];
    foreach ($rows as $row) {
      $payload[] = [
        'id' => (int) $row['delivery_id'],
        'notification_id' => (int) $row['notification_id'],
        'title' => (string) $row['title'],
        'message' => (string) $row['message'],
        'message_preview' => (string) $row['message_preview'],
        'type' => (string) $row['type'],
        'created' => (int) $row['created'],
        'priority_score' => (int) $row['priority_score'],
        'toast_eligible' => (bool) $row['toast_eligible'],
        'surface' => (string) $row['surface'],
        'action' => $row['action'],
      ];
    }

    $response = new CacheableJsonResponse($payload);
    $response->addCacheableDependency($this->currentUser());
    $response->getCacheableMetadata()->setCacheContexts(['user']);
    $response->getCacheableMetadata()->setCacheMaxAge(0);

    return $response;
  }

  /**
   * JSON unread count for the bell badge.
   */
  public function unreadCount(): CacheableJsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      throw new AccessDeniedHttpException();
    }
    $count = $this->userInbox->countUnread($uid);
    $response = new CacheableJsonResponse(['unread' => $count]);
    $response->addCacheableDependency($this->currentUser());
    $response->getCacheableMetadata()->setCacheContexts(['user']);
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    return $response;
  }

  /**
   * Bell dropdown preview (recent unread first).
   */
  public function preview(): CacheableJsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      throw new AccessDeniedHttpException();
    }
    $ids = $this->userInbox->getUnreadDeliveryIds($uid, NotificationUserInboxService::BELL_PREVIEW_LIMIT);
    $rows = $this->viewBuilder->buildRowsForDeliveries($ids, $uid);
    $response = new CacheableJsonResponse(['items' => $rows]);
    $response->addCacheableDependency($this->currentUser());
    $response->getCacheableMetadata()->setCacheContexts(['user']);
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    return $response;
  }

  /**
   * Records a click on the notification action, then redirects to the internal target.
   */
  public function actionGo(int $delivery): RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      throw new AccessDeniedHttpException();
    }

    $storage = $this->entityTypeManager()->getStorage('mel_notification_delivery');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery|null $entity */
    $entity = $storage->load($delivery);
    if ($entity === NULL || (int) $entity->get('recipient_uid')->target_id !== $uid) {
      throw new NotFoundHttpException();
    }

    $notifStorage = $this->entityTypeManager()->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification|null $notification */
    $notification = $notifStorage->load($entity->get('notification_id')->target_id);
    if ($notification === NULL) {
      throw new NotFoundHttpException();
    }

    $action = $this->viewBuilder->buildActionFromNotification($notification);
    if ($action === NULL) {
      throw new NotFoundHttpException();
    }

    $now = $this->time->getRequestTime();
    if ((int) ($entity->get('clicked_at')->value ?? 0) < 1) {
      try {
        $entity->set('clicked_at', $now);
        $entity->save();
      }
      catch (\Throwable $e) {
        $this->logger->error('Could not persist notification click for delivery @id: @message', [
          '@id' => (string) $delivery,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return new RedirectResponse($action['url']);
  }

  /**
   * Marks a delivery as read (CSRF header required on route).
   */
  public function markRead(int $delivery): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      throw new AccessDeniedHttpException();
    }

    if (!$this->userInbox->markReadOne($uid, $delivery)) {
      throw new NotFoundHttpException();
    }

    return new JsonResponse(['status' => 'ok']);
  }

  /**
   * Marks all deliveries read for the current user.
   */
  public function markAllRead(): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      throw new AccessDeniedHttpException();
    }
    $updated = $this->userInbox->markAllRead($uid);
    return new JsonResponse(['status' => 'ok', 'updated' => $updated]);
  }

}
