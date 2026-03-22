<?php

declare(strict_types=1);

namespace Drupal\myeventlane_automation\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_automation\Service\AutomationJobService;
use Drupal\myeventlane_automation\Service\AutomationMessageBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued organiser automation jobs.
 *
 * For v1: logs intended action, optionally creates message records.
 * Only sends if config explicitly allows delivery.
 *
 * @QueueWorker(
 *   id = "automation_dispatch",
 *   title = @Translation("Organiser automation dispatch"),
 *   cron = {"time" = 30}
 * )
 */
final class AutomationDispatchQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AutomationJobService $jobService,
    private readonly AutomationMessageBuilder $messageBuilder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('myeventlane_automation.job_service'),
      $container->get('myeventlane_automation.message_builder'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('logger.channel.myeventlane_automation'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $automationKey = $data['automation_key'] ?? '';
    $eventId = (int) ($data['event_id'] ?? 0);
    $recipientType = $data['recipient_type'] ?? 'organiser';
    $payload = $data['payload'] ?? [];
    $jobId = (int) ($data['job_id'] ?? 0);

    if ($eventId <= 0 || $automationKey === '') {
      $this->logger->error('Automation dispatch: invalid item (missing event_id or automation_key).');
      if ($jobId > 0) {
        $this->jobService->markSkipped($jobId, 'invalid item');
      }
      return;
    }

    $config = $this->configFactory->get('myeventlane_automation.settings');
    $dryRun = $config->get('dry_run') ?? TRUE;
    $delivery = $config->get('delivery') ?? [];

    // Log intended action (always).
    $this->logger->info('Automation dispatch: @key for event @id, recipient_type=@type, dry_run=@dry', [
      '@key' => $automationKey,
      '@id' => $eventId,
      '@type' => $recipientType,
      '@dry' => $dryRun ? 'yes' : 'no',
    ]);

    // Only send if delivery is enabled for this type.
    $deliveryKey = $this->mapKeyToDelivery($automationKey);
    $canSend = !$dryRun && !empty($delivery[$deliveryKey]);

    if (!$canSend) {
      $this->logger->debug('Automation dispatch: skipping send (dry_run or delivery disabled for @key).', [
        '@key' => $automationKey,
      ]);
      if ($jobId > 0) {
        $this->jobService->markProcessed($jobId);
      }
      return;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event || $event->bundle() !== 'event') {
      $this->logger->warning('Automation dispatch: event @id not found or invalid.', ['@id' => $eventId]);
      if ($jobId > 0) {
        $this->jobService->markSkipped($jobId, 'event not found');
      }
      return;
    }

    // Build and send based on type.
    $recipientIds = $payload['recipient_ids'] ?? [];
    $recipientEmails = $payload['recipient_emails'] ?? [];

    if ($recipientType === 'organiser') {
      $ownerId = (int) $event->getOwnerId();
      if ($ownerId <= 0) {
        if ($jobId > 0) {
          $this->jobService->markSkipped($jobId, 'no organiser');
        }
        return;
      }
      $user = $this->entityTypeManager->getStorage('user')->load($ownerId);
      $email = $user && method_exists($user, 'getEmail') ? $user->getEmail() : NULL;
      if (empty($email)) {
        if ($jobId > 0) {
          $this->jobService->markSkipped($jobId, 'no organiser email');
        }
        return;
      }

      if ($automationKey === 'draft_reminder') {
        $this->sendDraftReminder($event, $email, $payload);
      }
      elseif ($automationKey === 'post_event_followup') {
        $this->sendPostEventFollowup($event, $email, $payload);
      }
      elseif ($automationKey === 'organiser_reminder') {
        $this->sendOrganiserReminder($event, $email, $payload);
      }
    }
    elseif ($recipientType === 'attendee' && $automationKey === 'attendee_reminder') {
      if (empty($recipientEmails) && !empty($recipientIds)) {
        foreach ($recipientIds as $uid) {
          $u = $this->entityTypeManager->getStorage('user')->load((int) $uid);
          if ($u && method_exists($u, 'getEmail')) {
            $recipientEmails[] = $u->getEmail();
          }
        }
      }
      foreach (array_filter($recipientEmails) as $email) {
        $this->sendAttendeeReminder($event, $email, $payload);
      }
    }

    if ($jobId > 0) {
      $this->jobService->markProcessed($jobId);
    }
  }

  private function mapKeyToDelivery(string $key): string {
    $map = [
      'attendee_reminder' => 'attendee_reminder',
      'organiser_reminder' => 'organiser_reminder',
      'post_event_followup' => 'post_event_followup',
      'draft_reminder' => 'draft_reminder',
    ];
    return $map[$key] ?? $key;
  }

  private function sendDraftReminder($event, string $email, array $payload): void {
    $context = [
      'event_title' => $event->label(),
      'edit_url' => $payload['edit_url'] ?? $event->toUrl('edit-form', ['absolute' => TRUE])->toString(),
    ];
    $msg = $this->messageBuilder->buildDraftReminder($context);
    $this->queueOrSend($email, 'draft_reminder', $msg, $payload);
  }

  private function sendPostEventFollowup($event, string $email, array $payload): void {
    $context = [
      'event_title' => $event->label(),
      'attendee_count' => $payload['attendee_count'] ?? 0,
      'attendees_url' => $payload['attendees_url'] ?? NULL,
      'duplicate_url' => $payload['duplicate_url'] ?? NULL,
    ];
    $msg = $this->messageBuilder->buildPostEventFollowup($context);
    $this->queueOrSend($email, 'post_event_followup', $msg, $payload);
  }

  private function sendOrganiserReminder($event, string $email, array $payload): void {
    $context = [
      'event_title' => $event->label(),
      'event_date' => $payload['event_date'] ?? NULL,
      'attendee_count' => $payload['attendee_count'] ?? 0,
      'event_url' => $payload['event_url'] ?? $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];
    $msg = $this->messageBuilder->buildOrganiserReminder($context);
    $this->queueOrSend($email, 'organiser_reminder', $msg, $payload);
  }

  private function sendAttendeeReminder($event, string $email, array $payload): void {
    $context = [
      'event_title' => $event->label(),
      'event_date' => $payload['event_date'] ?? NULL,
      'event_time' => $payload['event_time'] ?? NULL,
      'event_location' => $payload['event_location'] ?? NULL,
      'event_url' => $payload['event_url'] ?? $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];
    $msg = $this->messageBuilder->buildAttendeeReminder($context);
    $this->queueOrSend($email, 'attendee_reminder', $msg, $payload);
  }

  private function queueOrSend(string $email, string $type, array $msg, array $payload): void {
    $params = [
      'subject' => $msg['subject'],
      'body' => $msg['body'],
    ];
    try {
      $result = $this->mailManager->mail('myeventlane_automation', 'automation_' . $type, $email, 'en', $params, NULL, TRUE);
      if ($result['result'] !== TRUE) {
        $this->logger->error('Automation mail failed for @type to @email.', [
          '@type' => $type,
          '@email' => $email,
        ]);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Automation mail exception: @m', ['@m' => $e->getMessage()]);
    }
  }

}
