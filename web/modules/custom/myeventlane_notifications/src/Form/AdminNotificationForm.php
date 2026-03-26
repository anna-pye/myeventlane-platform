<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_notifications\Entity\MelNotification;
use Drupal\myeventlane_notifications\Entity\MelNotificationDelivery;
use Drupal\myeventlane_notifications\Service\NotificationAnalyticsService;
use Drupal\myeventlane_notifications\Service\NotificationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Minimal admin UI to queue a platform notification (no synchronous sends).
 */
final class AdminNotificationForm extends FormBase {

  public function __construct(
    private readonly NotificationManager $notificationManager,
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NotificationAnalyticsService $notificationAnalytics,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get('myeventlane_notifications.notification_manager'),
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_notifications.analytics'),
    );
    $form->setRequestStack($container->get('request_stack'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_notifications_admin';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $nid = (int) ($this->getRequest()?->query->get('notification') ?? 0);
    if ($nid > 0) {
      $form['stats'] = [
        '#type' => 'markup',
        '#markup' => $this->buildStatsMarkup($nid),
        '#weight' => -20,
      ];
    }

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#required' => TRUE,
      '#maxlength' => 512,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
    ];

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#required' => TRUE,
      '#options' => [
        MelNotification::TYPE_SYSTEM => $this->t('System'),
        MelNotification::TYPE_EVENT => $this->t('Event'),
        MelNotification::TYPE_TICKET => $this->t('Ticket'),
        MelNotification::TYPE_PROMO => $this->t('Promo'),
        MelNotification::TYPE_REMINDER => $this->t('Reminder'),
      ],
      '#default_value' => MelNotification::TYPE_SYSTEM,
    ];

    $form['action_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Action label (optional)'),
      '#maxlength' => 255,
      '#description' => $this->t('CTA text, e.g. “View ticket”. Requires action path below.'),
    ];

    $form['action_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Action path (optional)'),
      '#maxlength' => 2048,
      '#description' => $this->t('Internal path starting with / (e.g. /my-tickets).'),
    ];

    $form['audience_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Audience'),
      '#required' => TRUE,
      '#options' => [
        MelNotification::AUDIENCE_ALL => $this->t('All active users'),
        MelNotification::AUDIENCE_ROLE => $this->t('Roles'),
        MelNotification::AUDIENCE_USER_IDS => $this->t('Specific user IDs'),
        MelNotification::AUDIENCE_EVENT_ATTENDEES => $this->t('Event attendees'),
        MelNotification::AUDIENCE_VENDOR => $this->t('Vendor (organiser)'),
      ],
      '#default_value' => MelNotification::AUDIENCE_ROLE,
    ];

    $form['roles'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Roles (comma-separated machine names)'),
      '#description' => $this->t('Used when audience is Roles.'),
      '#states' => [
        'visible' => [
          ':input[name="audience_type"]' => ['value' => MelNotification::AUDIENCE_ROLE],
        ],
      ],
      '#default_value' => 'authenticated',
    ];

    $form['user_ids'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User IDs (comma-separated)'),
      '#description' => $this->t('Used when audience is Specific users.'),
      '#states' => [
        'visible' => [
          ':input[name="audience_type"]' => ['value' => MelNotification::AUDIENCE_USER_IDS],
        ],
      ],
    ];

    $form['event_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Event node ID'),
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name="audience_type"]' => ['value' => MelNotification::AUDIENCE_EVENT_ATTENDEES],
        ],
      ],
    ];

    $form['vendor_event_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Event node ID (organiser lookup)'),
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name="audience_type"]' => ['value' => MelNotification::AUDIENCE_VENDOR],
        ],
      ],
    ];

    $form['schedule'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Schedule send'),
      '#description' => $this->t('Leave empty to queue immediately (subject to cron / queue runner).'),
    ];

    $form['channels'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Channels'),
      '#options' => [
        'toast' => $this->t('In-app toast'),
        'email' => $this->t('Email (queued for future transport)'),
        'push_future' => $this->t('Push (future)'),
      ],
      '#default_value' => [
        'toast' => 'toast',
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and queue'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $channels = array_filter((array) $form_state->getValue('channels'));
    if ($channels === []) {
      $form_state->setErrorByName('channels', $this->t('Select at least one channel.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $audienceType = (string) $form_state->getValue('audience_type');
    $audienceData = [];

    switch ($audienceType) {
      case MelNotification::AUDIENCE_ROLE:
        $roles = array_filter(array_map('trim', explode(',', (string) $form_state->getValue('roles'))));
        $audienceData = ['roles' => $roles];
        break;

      case MelNotification::AUDIENCE_USER_IDS:
        $ids = array_filter(array_map('intval', explode(',', (string) $form_state->getValue('user_ids'))));
        $audienceData = ['user_ids' => $ids];
        break;

      case MelNotification::AUDIENCE_EVENT_ATTENDEES:
        $audienceData = ['event_id' => (int) $form_state->getValue('event_id')];
        break;

      case MelNotification::AUDIENCE_VENDOR:
        $audienceData = ['event_id' => (int) $form_state->getValue('vendor_event_id')];
        break;

      case MelNotification::AUDIENCE_ALL:
        $audienceData = [];
        break;
    }

    $scheduledAt = NULL;
    $schedule = $form_state->getValue('schedule');
    if ($schedule instanceof \Drupal\Core\Datetime\DrupalDateTime) {
      $scheduledAt = $schedule->getTimestamp();
    }

    $channels = array_values(array_filter((array) $form_state->getValue('channels')));

    $data = [
      'type' => $form_state->getValue('type'),
      'title' => $form_state->getValue('title'),
      'message' => $form_state->getValue('message'),
      'audience_type' => $audienceType,
      'audience_data' => $audienceData,
      'channels' => $channels,
      'status' => MelNotification::STATUS_DRAFT,
      'scheduled_at' => $scheduledAt,
      'action_label' => $form_state->getValue('action_label'),
      'action_uri' => $form_state->getValue('action_uri'),
    ];

    try {
      $notification = $this->notificationManager->createNotification($data);
      $this->notificationManager->queueNotification($notification);
      $this->messenger()->addStatus($this->t('Notification saved. Delivery jobs were added to the queue.'));
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_notifications.admin', [], [
        'query' => ['notification' => $notification->id()],
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not queue notification: @message', ['@message' => $e->getMessage()]));
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_notifications.admin'));
    }
  }

  private function buildStatsMarkup(int $notificationId): string {
    $storage = $this->entityTypeManager->getStorage('mel_notification');
    $notification = $storage->load($notificationId);
    if (!$notification instanceof MelNotification) {
      return '<p>' . $this->t('Notification @id not found.', ['@id' => (string) $notificationId]) . '</p>';
    }

    $audienceKey = $this->notificationManager->audienceCountKey($notificationId);
    $resolved = $this->state->get($audienceKey);
    if ($resolved === NULL) {
      try {
        $resolved = count($this->notificationManager->resolveAudience($notification));
      }
      catch (\Throwable) {
        $resolved = $this->t('unknown');
      }
    }

    $deliveryStorage = $this->entityTypeManager->getStorage('mel_notification_delivery');
    $total = count($deliveryStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('notification_id', $notificationId)
      ->execute());

    $failed = count($deliveryStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('notification_id', $notificationId)
      ->condition('status', MelNotificationDelivery::STATUS_FAILED)
      ->execute());

    $stats = $this->notificationAnalytics->getNotificationStats($notificationId);

    $lines = [
      $this->t('Notification ID: @id (status: @status)', [
        '@id' => (string) $notificationId,
        '@status' => (string) $notification->get('status')->value,
      ]),
      $this->t('Resolved audience count: @count', ['@count' => (string) $resolved]),
      $this->t('Delivery rows (all statuses): @total', ['@total' => (string) $total]),
      $this->t('Delivered (sent or read): @sent', ['@sent' => (string) $stats['sent']]),
      $this->t('Read: @read', ['@read' => (string) $stats['read']]),
      $this->t('Action clicks: @clicked', ['@clicked' => (string) $stats['clicked']]),
      $this->t('Suppressed by user prefs: @n', ['@n' => (string) $stats['suppressed']]),
      $this->t('Grouped deliveries: @n', ['@n' => (string) $stats['grouped']]),
      $this->t('Read rate: @pct%', ['@pct' => (string) $stats['read_rate']]),
      $this->t('Click rate: @pct%', ['@pct' => (string) $stats['click_rate']]),
      $this->t('Failed deliveries: @failed', ['@failed' => (string) $failed]),
    ];

    return '<div class="messages messages--status"><p>' . implode('</p><p>', $lines) . '</p></div>';
  }

}
