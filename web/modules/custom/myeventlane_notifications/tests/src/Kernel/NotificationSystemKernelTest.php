<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_notifications\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_notifications\Controller\NotificationController;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\Entity\MelNotificationDelivery;
use Drupal\myeventlane_notifications\NotificationContext;
use Drupal\myeventlane_notifications\NotificationDomain;
use Drupal\myeventlane_notifications\NotificationFilter;
use Drupal\myeventlane_notifications\NotificationSurface;
use Drupal\myeventlane_notifications\NotificationTaxonomy;
use Drupal\myeventlane_notifications\Plugin\QueueWorker\NotificationDispatchWorker;
use Drupal\myeventlane_notifications\Service\NotificationAnalyticsService;
use Drupal\myeventlane_notifications\Service\NotificationDecisionEngine;
use Drupal\myeventlane_notifications\Service\NotificationPreferenceService;
use Drupal\myeventlane_notifications\Service\NotificationUserInboxService;
use Drupal\myeventlane_notifications\Service\NotificationViewBuilder;
use Drupal\myeventlane_notifications\Service\RefundNotificationTriggerService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;

/**
 * @coversDefaultClass \Drupal\myeventlane_notifications\Service\NotificationManager
 *
 * @group myeventlane_notifications
 */
final class NotificationSystemKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'serialization',
    'path',
    'entity',
    'node',
    'commerce',
    'commerce_order',
    'commerce_price',
    'commerce_store',
    'commerce_number_pattern',
    'state_machine',
    'myeventlane_notifications',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('myeventlane_notifications', 'install');
  }

  public function testBroadcastAllUsersQueuesAndDelivers(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['commerce_store', 'commerce_order', 'user', 'node']);
    myeventlane_notifications_apply_delivery_schema();

    for ($i = 0; $i < 3; $i++) {
      $user = User::create([
        'name' => 'mel_notif_test_' . $i,
        'mail' => 'mel_notif_test_' . $i . '@example.com',
        'status' => 1,
      ]);
      $user->save();
    }

    /** @var \Drupal\myeventlane_notifications\Service\NotificationManager $manager */
    $manager = $this->container->get('myeventlane_notifications.notification_manager');

    $notification = $manager->createNotification([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'Test broadcast',
      'message' => 'Hello everyone',
      'audience_type' => MelNotification::AUDIENCE_ALL,
      'audience_data' => [],
      'channels' => ['toast'],
      'status' => MelNotification::STATUS_DRAFT,
    ]);

    $manager->queueNotification($notification);

    $queue = $this->container->get('queue')->get(NotificationDispatchWorker::QUEUE_ID);
    $this->assertEquals(1, $queue->numberOfItems());

    /** @var \Drupal\myeventlane_notifications\Plugin\QueueWorker\NotificationDispatchWorker $worker */
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(NotificationDispatchWorker::QUEUE_ID);
    $item = $queue->claimItem();
    $this->assertNotNull($item);
    $worker->processItem($item->data);
    $queue->deleteItem($item);

    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    $ids = $deliveryStorage->getQuery()->accessCheck(FALSE)->execute();
    $this->assertCount(3, $ids);

    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $reloaded */
    $reloaded = $this->container->get('entity_type.manager')->getStorage('mel_notification')->load($notification->id());
    $this->assertSame(MelNotification::STATUS_SENT, $reloaded->get('status')->value);
  }

  public function testDuplicateQueueRunDoesNotDuplicateDeliveries(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['commerce_store', 'commerce_order', 'user']);
    myeventlane_notifications_apply_delivery_schema();

    $user = User::create([
      'name' => 'mel_notif_dup',
      'mail' => 'dup@example.com',
      'status' => 1,
    ]);
    $user->save();

    $manager = $this->container->get('myeventlane_notifications.notification_manager');
    $notification = $manager->createNotification([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'Dup',
      'message' => 'M',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => ['user_ids' => [(int) $user->id()]],
      'channels' => ['toast'],
    ]);
    $manager->queueNotification($notification);

    $queue = $this->container->get('queue')->get(NotificationDispatchWorker::QUEUE_ID);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(NotificationDispatchWorker::QUEUE_ID);
    $item = $queue->claimItem();
    $this->assertNotNull($item);
    $data = $item->data;
    $worker->processItem($data);
    $worker->processItem($data);
    $queue->deleteItem($item);

    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    $this->assertCount(1, $deliveryStorage->getQuery()->accessCheck(FALSE)->execute());
  }

  public function testEventPublishedShortCircuitsAfterFirstRun(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['user', 'node']);
    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();

    $owner = User::create([
      'name' => 'owner_event',
      'mail' => 'owner_event@example.com',
      'status' => 1,
    ]);
    $owner->save();

    $node = Node::create([
      'type' => 'event',
      'title' => 'Published event',
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    $node->save();

    /** @var \Drupal\myeventlane_notifications\Service\PlatformNotificationTriggerService $trigger */
    $trigger = $this->container->get('myeventlane_notifications.trigger');
    $trigger->onEventPublished($node);
    $trigger->onEventPublished($node);

    $state = $this->container->get('state');
    $this->assertNotEmpty($state->get('myeventlane_notifications.event_published.' . $node->id()));

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    $this->assertSame([], $notifStorage->getQuery()->accessCheck(FALSE)->execute());
  }

  public function testAudienceResolverReturnsUniqueUserIds(): void {
    $this->installEntitySchema('user');
    $this->installConfig(['user']);

    $u1 = User::create(['name' => 'a1', 'mail' => 'a1@example.com', 'status' => 1]);
    $u1->addRole('authenticated');
    $u1->save();
    $u2 = User::create(['name' => 'a2', 'mail' => 'a2@example.com', 'status' => 1]);
    $u2->addRole('authenticated');
    $u2->save();

    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'Role audience',
      'message' => 'Hi',
      'audience_type' => MelNotification::AUDIENCE_ROLE,
      'audience_data' => json_encode(['roles' => ['authenticated']], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_DRAFT,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    /** @var \Drupal\myeventlane_notifications\Service\AudienceResolver $resolver */
    $resolver = $this->container->get('myeventlane_notifications.audience_resolver');
    $flat = [];
    foreach ($resolver->buildUserIdChunks($notification) as $chunk) {
      $flat = array_merge($flat, $chunk);
    }
    $this->assertSame($flat, array_values(array_unique($flat)));
    $this->assertGreaterThanOrEqual(2, count($flat));
  }

  public function testUnreadEndpointReturnsOnlyCurrentUserData(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['user']);

    $u1 = User::create(['name' => 'u1', 'mail' => 'u1@example.com', 'status' => 1]);
    $u1->save();
    $u2 = User::create(['name' => 'u2', 'mail' => 'u2@example.com', 'status' => 1]);
    $u2->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'Shared',
      'message' => 'Body',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [(int) $u1->id(), (int) $u2->id()]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_SENT,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    foreach ([$u1, $u2] as $account) {
      /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $delivery */
      $delivery = $deliveryStorage->create([
        'notification_id' => $notification->id(),
        'recipient_uid' => $account->id(),
        'status' => MelNotificationDelivery::STATUS_SENT,
        'delivered_at' => $this->container->get('datetime.time')->getRequestTime(),
        'surface' => NotificationSurface::TOAST_INBOX,
      ]);
      $delivery->save();
    }

    $this->setCurrentUser($u1);
    $controller = NotificationController::create($this->container);
    $response = $controller->unread();
    $data = json_decode($response->getContent(), TRUE);
    $this->assertCount(1, $data);
    $this->assertArrayHasKey('notification_id', $data[0]);
    $this->assertArrayHasKey('toast_eligible', $data[0]);
    $this->assertArrayHasKey('message_preview', $data[0]);
    $this->assertSame((int) $notification->id(), (int) $data[0]['notification_id']);
  }

  public function testUnreadCountEndpoint(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['user']);

    $user = User::create(['name' => 'uc', 'mail' => 'uc@example.com', 'status' => 1]);
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_TICKET,
      'title' => 'T',
      'message' => 'M',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [(int) $user->id()]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_SENT,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification2 */
    $notification2 = $notifStorage->create([
      'type' => MelNotification::TYPE_TICKET,
      'title' => 'T2',
      'message' => 'M2',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [(int) $user->id()]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_SENT,
    ]);
    $notification2->get('channels')->appendItem('toast');
    $notification2->save();

    foreach ([$notification, $notification2] as $i => $notif) {
      /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $delivery */
      $delivery = $deliveryStorage->create([
        'notification_id' => $notif->id(),
        'recipient_uid' => $user->id(),
        'status' => MelNotificationDelivery::STATUS_SENT,
        'delivered_at' => $this->container->get('datetime.time')->getRequestTime() + $i,
        'surface' => NotificationSurface::TOAST_INBOX,
      ]);
      $delivery->save();
    }

    $this->setCurrentUser($user);
    $controller = NotificationController::create($this->container);
    $response = $controller->unreadCount();
    $payload = json_decode($response->getContent(), TRUE);
    $this->assertSame(2, (int) $payload['unread']);
  }

  public function testMarkAllReadJsonEndpoint(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['user']);

    $user = User::create(['name' => 'mar', 'mail' => 'mar@example.com', 'status' => 1]);
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'T',
      'message' => 'M',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [(int) $user->id()]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_SENT,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $delivery */
    $delivery = $deliveryStorage->create([
      'notification_id' => $notification->id(),
      'recipient_uid' => $user->id(),
      'status' => MelNotificationDelivery::STATUS_SENT,
      'delivered_at' => $this->container->get('datetime.time')->getRequestTime(),
      'surface' => NotificationSurface::BELL_INBOX,
    ]);
    $delivery->save();

    $this->setCurrentUser($user);
    $controller = NotificationController::create($this->container);
    $response = $controller->markAllRead();
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode($response->getContent(), TRUE);
    $this->assertSame('ok', $payload['status']);
    $this->assertSame(1, (int) $payload['updated']);

    $delivery = $deliveryStorage->load($delivery->id());
    $this->assertSame(MelNotificationDelivery::STATUS_READ, $delivery->get('status')->value);
  }

  public function testInboxServiceFilterTickets(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['user']);

    $user = User::create(['name' => 'ft', 'mail' => 'ft@example.com', 'status' => 1]);
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');

    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $ticketNotif */
    $ticketNotif = $notifStorage->create([
      'type' => MelNotification::TYPE_TICKET,
      'title' => 'Ticket',
      'message' => 'T',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [(int) $user->id()]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_SENT,
    ]);
    $ticketNotif->get('channels')->appendItem('toast');
    $ticketNotif->save();

    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $eventNotif */
    $eventNotif = $notifStorage->create([
      'type' => MelNotification::TYPE_EVENT,
      'title' => 'Event',
      'message' => 'E',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [(int) $user->id()]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_SENT,
    ]);
    $eventNotif->get('channels')->appendItem('toast');
    $eventNotif->save();

    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    foreach ([$ticketNotif, $eventNotif] as $n) {
      /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $d */
      $d = $deliveryStorage->create([
        'notification_id' => $n->id(),
        'recipient_uid' => $user->id(),
        'status' => MelNotificationDelivery::STATUS_SENT,
        'delivered_at' => $this->container->get('datetime.time')->getRequestTime(),
        'surface' => NotificationSurface::TOAST_INBOX,
      ]);
      $d->save();
    }

    /** @var \Drupal\myeventlane_notifications\Service\NotificationUserInboxService $inbox */
    $inbox = $this->container->get('myeventlane_notifications.user_inbox');
    $ids = $inbox->getInboxDeliveryIds((int) $user->id(), NotificationFilter::TAB_ALL, NotificationFilter::FILTER_TICKETS, 0);
    $this->assertCount(1, $ids);
  }

  public function testViewBuilderEscapesActionFromInvalidUri(): void {
    $this->installEntitySchema('mel_notification');
    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'X',
      'message' => 'Y',
      'audience_type' => MelNotification::AUDIENCE_ALL,
      'audience_data' => '{}',
      'status' => MelNotification::STATUS_DRAFT,
      'action_label' => 'Go',
      'action_uri' => '//evil.com',
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    /** @var \Drupal\myeventlane_notifications\Service\NotificationViewBuilder $vb */
    $vb = $this->container->get('myeventlane_notifications.view_builder');
    $this->assertNull($vb->buildActionFromNotification($notification));
  }

  public function testDecisionEngineHighPriorityForcesToastSurface(): void {
    $this->installEntitySchema('mel_notification');
    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_TICKET,
      'title' => 'Hi',
      'message' => 'M',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [1]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_DRAFT,
      'priority' => MelNotification::PRIORITY_HIGH,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    /** @var \Drupal\myeventlane_notifications\Service\NotificationDecisionEngine $engine */
    $engine = $this->container->get('myeventlane_notifications.decision_engine');
    $this->assertSame(NotificationSurface::TOAST_INBOX, $engine->resolveSurface($notification, 99));
  }

  public function testEventAttendeeAudienceRequiresModule(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['user']);

    /** @var \Drupal\myeventlane_notifications\Service\NotificationManager $manager */
    $manager = $this->container->get('myeventlane_notifications.notification_manager');

    $notification = $manager->createNotification([
      'type' => MelNotification::TYPE_EVENT,
      'title' => 'Attendee test',
      'message' => 'Hi',
      'audience_type' => MelNotification::AUDIENCE_EVENT_ATTENDEES,
      'audience_data' => ['event_id' => 1],
      'channels' => ['toast'],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $manager->queueNotification($notification);
  }

  public function testMarkReadQueryIsScopedToRecipient(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['user']);

    $user = User::create([
      'name' => 'recipient',
      'mail' => 'recipient@example.com',
      'status' => 1,
    ]);
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'T',
      'message' => 'M',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [(int) $user->id()]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_SENT,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $delivery */
    $delivery = $deliveryStorage->create([
      'notification_id' => $notification->id(),
      'recipient_uid' => $user->id(),
      'status' => MelNotificationDelivery::STATUS_SENT,
      'delivered_at' => $this->container->get('datetime.time')->getRequestTime(),
      'surface' => NotificationSurface::TOAST_INBOX,
    ]);
    $delivery->save();

    $this->setCurrentUser($user);
    /** @var \Drupal\myeventlane_notifications\Controller\NotificationController $controller */
    $controller = NotificationController::create($this->container);
    $response = $controller->markRead((int) $delivery->id());
    $this->assertEquals(200, $response->getStatusCode());

    $delivery = $deliveryStorage->load($delivery->id());
    $this->assertSame(MelNotificationDelivery::STATUS_READ, $delivery->get('status')->value);
  }

  public function testGroupSummariesMergeSameGroupKey(): void {
    /** @var \Drupal\myeventlane_notifications\Service\NotificationViewBuilder $vb */
    $vb = $this->container->get('myeventlane_notifications.view_builder');
    $day = gmdate('Y-m-d', time());
    $rows = [
      [
        'delivery_id' => 1,
        'notification_id' => 10,
        'title' => 'Music',
        'message_preview' => 'a',
        'type' => 'event',
        'icon' => 'calendar',
        'created' => time(),
        'delivered_at' => strtotime($day . ' 12:00:00 UTC'),
        'priority_score' => 0,
        'status' => 'sent',
        'is_unread' => TRUE,
        'toast_eligible' => TRUE,
        'surface' => NotificationSurface::TOAST_INBOX,
        'action' => NULL,
        'group_key' => 'cat:music',
      ],
      [
        'delivery_id' => 2,
        'notification_id' => 11,
        'title' => 'Music',
        'message_preview' => 'b',
        'type' => 'event',
        'icon' => 'calendar',
        'created' => time(),
        'delivered_at' => strtotime($day . ' 13:00:00 UTC'),
        'priority_score' => 0,
        'status' => 'sent',
        'is_unread' => FALSE,
        'toast_eligible' => FALSE,
        'surface' => NotificationSurface::BELL_INBOX,
        'action' => NULL,
        'group_key' => 'cat:music',
      ],
    ];
    $out = $vb->applyGroupSummaries($rows);
    $this->assertCount(1, $out);
    $this->assertNotEmpty($out[0]['is_group_summary']);
  }

  public function testSuppressedDeliveriesExcludedFromInbox(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['user']);

    $user = User::create([
      'name' => 'suppressed_inbox',
      'mail' => 'suppressed_inbox@example.com',
      'status' => 1,
    ]);
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'S',
      'message' => 'M',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [(int) $user->id()]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_SENT,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $delivery */
    $delivery = $deliveryStorage->create([
      'notification_id' => $notification->id(),
      'recipient_uid' => $user->id(),
      'status' => MelNotificationDelivery::STATUS_SENT,
      'delivered_at' => $this->container->get('datetime.time')->getRequestTime(),
      'surface' => NotificationSurface::INBOX_ONLY,
      'suppressed' => TRUE,
      'grouped' => FALSE,
    ]);
    $delivery->save();

    /** @var \Drupal\myeventlane_notifications\Service\NotificationUserInboxService $inbox */
    $inbox = $this->container->get('myeventlane_notifications.user_inbox');
    $ids = $inbox->getInboxDeliveryIds((int) $user->id(), NotificationFilter::TAB_ALL, NotificationFilter::FILTER_ALL, 0);
    $this->assertSame([], $ids);
    $this->assertSame(0, $inbox->countUnread((int) $user->id()));
  }

  public function testPreferenceSuppressesNonTicketCategories(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installConfig(['user']);

    $user = User::create([
      'name' => 'prefs_evt',
      'mail' => 'prefs_evt@example.com',
      'status' => 1,
    ]);
    $user->set(NotificationPreferenceService::FIELD_NAME, json_encode([
      'events' => ['enabled' => FALSE, 'surface' => 'inbox_only'],
    ], JSON_THROW_ON_ERROR));
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_EVENT,
      'title' => 'E',
      'message' => 'M',
      'audience_type' => MelNotification::AUDIENCE_ALL,
      'audience_data' => '{}',
      'status' => MelNotification::STATUS_DRAFT,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    /** @var \Drupal\myeventlane_notifications\Service\NotificationDecisionEngine $engine */
    $engine = $this->container->get('myeventlane_notifications.decision_engine');
    $presentation = $engine->resolveDeliveryPresentation($notification, (int) $user->id());
    $this->assertTrue($presentation['suppressed']);
  }

  public function testTicketPreferenceDisabledIsNotSuppressed(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installConfig(['user']);

    $user = User::create([
      'name' => 'prefs_ticket',
      'mail' => 'prefs_ticket@example.com',
      'status' => 1,
    ]);
    $user->set(NotificationPreferenceService::FIELD_NAME, json_encode([
      'tickets' => ['enabled' => FALSE, 'surface' => 'toast_inbox'],
    ], JSON_THROW_ON_ERROR));
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_TICKET,
      'title' => 'T',
      'message' => 'M',
      'audience_type' => MelNotification::AUDIENCE_ALL,
      'audience_data' => '{}',
      'status' => MelNotification::STATUS_DRAFT,
      'priority' => MelNotification::PRIORITY_HIGH,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    /** @var \Drupal\myeventlane_notifications\Service\NotificationDecisionEngine $engine */
    $engine = $this->container->get('myeventlane_notifications.decision_engine');
    $presentation = $engine->resolveDeliveryPresentation($notification, (int) $user->id());
    $this->assertFalse($presentation['suppressed']);
    $this->assertSame(NotificationSurface::INBOX_ONLY, $presentation['surface']);
  }

  public function testAnalyticsServiceCountsClicks(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['user']);

    $user = User::create([
      'name' => 'analytics_u',
      'mail' => 'analytics_u@example.com',
      'status' => 1,
    ]);
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'A',
      'message' => 'M',
      'audience_type' => MelNotification::AUDIENCE_USER_IDS,
      'audience_data' => json_encode(['user_ids' => [(int) $user->id()]], JSON_THROW_ON_ERROR),
      'status' => MelNotification::STATUS_SENT,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    $now = $this->container->get('datetime.time')->getRequestTime();
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotificationDelivery $delivery */
    $delivery = $deliveryStorage->create([
      'notification_id' => $notification->id(),
      'recipient_uid' => $user->id(),
      'status' => MelNotificationDelivery::STATUS_SENT,
      'delivered_at' => $now,
      'surface' => NotificationSurface::BELL_INBOX,
      'clicked_at' => $now,
      'suppressed' => FALSE,
      'grouped' => FALSE,
    ]);
    $delivery->save();

    /** @var \Drupal\myeventlane_notifications\Service\NotificationAnalyticsService $analytics */
    $analytics = $this->container->get('myeventlane_notifications.analytics');
    $stats = $analytics->getNotificationStats((int) $notification->id());
    $this->assertSame(1, $stats['sent']);
    $this->assertSame(0, $stats['read']);
    $this->assertSame(1, $stats['clicked']);
  }

  public function testCriticalPriorityIsAllowedValue(): void {
    $this->installEntitySchema('mel_notification');
    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'Security alert',
      'message' => 'Account locked',
      'audience_type' => MelNotification::AUDIENCE_ALL,
      'audience_data' => '{}',
      'status' => MelNotification::STATUS_DRAFT,
      'priority' => MelNotification::PRIORITY_CRITICAL,
      'context' => NotificationContext::PLATFORM,
      'domain' => NotificationDomain::PLATFORM,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();
    $this->assertSame(MelNotification::PRIORITY_CRITICAL, (string) $notification->get('priority')->value);
  }

  public function testCriticalPriorityIsNeverPreferenceSuppressed(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installConfig(['user']);

    $user = User::create([
      'name' => 'critical_prefs',
      'mail' => 'critical_prefs@example.com',
      'status' => 1,
    ]);
    $user->set(NotificationPreferenceService::FIELD_NAME, json_encode([
      NotificationPreferenceService::PLATFORM_SECURITY => ['enabled' => FALSE, 'surface' => 'inbox_only'],
    ], JSON_THROW_ON_ERROR));
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'Security',
      'message' => 'Alert',
      'audience_type' => MelNotification::AUDIENCE_ALL,
      'audience_data' => '{}',
      'status' => MelNotification::STATUS_DRAFT,
      'priority' => MelNotification::PRIORITY_CRITICAL,
      'context' => NotificationContext::PLATFORM,
      'domain' => NotificationDomain::PLATFORM,
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    /** @var \Drupal\myeventlane_notifications\Service\NotificationDecisionEngine $engine */
    $engine = $this->container->get('myeventlane_notifications.decision_engine');
    $presentation = $engine->resolveDeliveryPresentation($notification, (int) $user->id());
    $this->assertFalse($presentation['suppressed']);
    $this->assertSame(NotificationSurface::TOAST_INBOX, $presentation['surface']);
  }

  public function testPersonalTabExposesEventUpdatesFilter(): void {
    $filters = NotificationFilter::filtersForTab(NotificationFilter::TAB_PERSONAL);
    $this->assertContains(NotificationFilter::FILTER_EVENT_UPDATES, $filters);
    $this->assertSame(1, count(array_filter($filters, static fn(string $f): bool => $f === NotificationFilter::FILTER_EVENT_UPDATES)));
  }

  public function testViewBuilderPrefersRouteNameOverActionUri(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installConfig(['user']);

    $user = User::create([
      'name' => 'route_test',
      'mail' => 'route_test@example.com',
      'status' => 1,
    ]);
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
    $notification = $notifStorage->create([
      'type' => MelNotification::TYPE_SYSTEM,
      'title' => 'Profile',
      'message' => 'Update',
      'audience_type' => MelNotification::AUDIENCE_ALL,
      'audience_data' => '{}',
      'status' => MelNotification::STATUS_DRAFT,
      'action_label' => 'View profile',
      'action_uri' => '/legacy-should-not-win',
      'route_name' => 'entity.user.canonical',
      'route_parameters' => json_encode(['user' => (int) $user->id()], JSON_THROW_ON_ERROR),
    ]);
    $notification->get('channels')->appendItem('toast');
    $notification->save();

    /** @var \Drupal\myeventlane_notifications\Service\NotificationViewBuilder $vb */
    $vb = $this->container->get('myeventlane_notifications.view_builder');
    $action = $vb->buildActionFromNotification($notification);
    $this->assertNotNull($action);
    $this->assertStringContainsString('/user/' . $user->id(), (string) $action['url']);
    $this->assertStringNotContainsString('legacy-should-not-win', (string) $action['url']);
  }

  public function testBuyerRefundActionContextMapsToPersonalOrders(): void {
    $mapped = NotificationTaxonomy::fromActionContext('refund_completed_buyer');
    $this->assertSame(NotificationContext::PERSONAL, $mapped['context']);
    $this->assertSame(NotificationDomain::ORDERS, $mapped['domain']);
  }

  public function testEmailTemplateClassificationIncludesPriority(): void {
    $buyer = NotificationTaxonomy::emailTemplateClassification('refund_completed_buyer');
    $this->assertSame(NotificationContext::PERSONAL, $buyer['context']);
    $this->assertSame(NotificationDomain::ORDERS, $buyer['domain']);
    $this->assertSame(MelNotification::PRIORITY_HIGH, $buyer['priority']);

    $order = NotificationTaxonomy::emailTemplateClassification('order_confirmation');
    $this->assertSame(MelNotification::PRIORITY_NORMAL, $order['priority']);
  }

  public function testBellContextFilteringScopesUnreadCounts(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('mel_notification');
    $this->installEntitySchema('mel_notification_delivery');
    $this->installConfig(['user']);

    $user = User::create([
      'name' => 'bell_ctx',
      'mail' => 'bell_ctx@example.com',
      'status' => 1,
    ]);
    $user->save();

    $notifStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification');
    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage('mel_notification_delivery');
    $now = $this->container->get('datetime.time')->getRequestTime();

    foreach ([
      [NotificationContext::PERSONAL, NotificationDomain::TICKETS, 'Personal ticket'],
      [NotificationContext::BUSINESS, NotificationDomain::SALES, 'Business sale'],
    ] as [$context, $domain, $title]) {
      /** @var \Drupal\myeventlane_notifications\Entity\MelNotification $notification */
      $notification = $notifStorage->create([
        'type' => MelNotification::TYPE_SYSTEM,
        'title' => $title,
        'message' => 'M',
        'audience_type' => MelNotification::AUDIENCE_USER_IDS,
        'audience_data' => json_encode(['user_ids' => [(int) $user->id()]], JSON_THROW_ON_ERROR),
        'status' => MelNotification::STATUS_SENT,
        'context' => $context,
        'domain' => $domain,
      ]);
      $notification->get('channels')->appendItem('toast');
      $notification->save();

      $deliveryStorage->create([
        'notification_id' => $notification->id(),
        'recipient_uid' => $user->id(),
        'status' => MelNotificationDelivery::STATUS_SENT,
        'delivered_at' => $now,
        'surface' => NotificationSurface::BELL_INBOX,
        'suppressed' => FALSE,
      ])->save();
    }

    /** @var \Drupal\myeventlane_notifications\Service\NotificationUserInboxService $inbox */
    $inbox = $this->container->get('myeventlane_notifications.user_inbox');
    $this->assertSame(1, $inbox->countUnreadForContexts((int) $user->id(), [NotificationContext::PERSONAL]));
    $this->assertSame(1, $inbox->countUnreadForContexts((int) $user->id(), [NotificationContext::BUSINESS]));
    $this->assertSame(2, $inbox->countUnread((int) $user->id()));
  }

  public function testBoostAndFollowerActionContexts(): void {
    $boost = NotificationTaxonomy::fromActionContext('boost_purchased');
    $this->assertSame(NotificationContext::BUSINESS, $boost['context']);
    $this->assertSame(NotificationDomain::BOOSTS, $boost['domain']);

    $follower = NotificationTaxonomy::fromActionContext('follower_gained');
    $this->assertSame(NotificationContext::BUSINESS, $follower['context']);
    $this->assertSame(NotificationDomain::FOLLOWERS, $follower['domain']);
  }

  public function testStage2TriggerActionContexts(): void {
    $eventUpdated = NotificationTaxonomy::fromActionContext('event_updated_schedule');
    $this->assertSame(NotificationContext::PERSONAL, $eventUpdated['context']);
    $this->assertSame(NotificationDomain::EVENT_UPDATES, $eventUpdated['domain']);

    $approved = NotificationTaxonomy::fromActionContext('event_approved');
    $this->assertSame(NotificationContext::BUSINESS, $approved['context']);

    $reminder = NotificationTaxonomy::fromActionContext('event_reminder_1h');
    $this->assertSame(NotificationDomain::REMINDERS, $reminder['domain']);
  }

  public function testRefundTriggerServiceExists(): void {
    $this->assertTrue($this->container->has('myeventlane_notifications.refund_trigger'));
    $service = $this->container->get('myeventlane_notifications.refund_trigger');
    $this->assertInstanceOf(RefundNotificationTriggerService::class, $service);
  }

}
