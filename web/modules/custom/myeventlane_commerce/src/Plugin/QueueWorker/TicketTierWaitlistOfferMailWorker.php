<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Url;
use Drupal\mel_ticket\Entity\TicketWaitlistEntry;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends tier waitlist offer emails outside the promotion transaction.
 *
 * @QueueWorker(
 *   id = "mel_ticket_waitlist_offer_mail",
 *   title = @Translation("Ticket tier waitlist offer email"),
 *   cron = {"time" = 45}
 * )
 */
final class TicketTierWaitlistOfferMailWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessagingManager $messagingManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly LockBackendInterface $lock,
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
      $container->get('entity_type.manager'),
      $container->get('myeventlane_messaging.manager'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('logger.factory'),
      $container->get('lock'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $entryId = isset($data['entry_id']) ? (int) $data['entry_id'] : 0;
    if ($entryId < 1) {
      $this->loggerFactory->get('myeventlane_commerce')->error(
        'Waitlist offer mail worker: missing entry_id in queue item.'
      );
      return;
    }

    /** @var \Drupal\mel_ticket\Entity\TicketWaitlistEntry|null $entry */
    $entry = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry')->load($entryId);
    if (!$entry instanceof TicketWaitlistEntry) {
      return;
    }

    $lock_id = 'mel_waitlist_offer_mail:' . $entryId;
    if (!$this->lock->acquire($lock_id, 30)) {
      $this->loggerFactory->get('myeventlane_commerce')->notice(
        'Waitlist offer mail deferred: could not acquire lock for entry @id (another worker may be processing it).',
        ['@id' => (string) $entryId]
      );
      throw new \RuntimeException('Lock not acquired for waitlist offer mail; item will be retried.');
    }

    try {
      if ($entry->get('status')->value !== TicketWaitlistEntry::STATUS_OFFERED) {
        return;
      }

      if ($entry->get('offer_mail_sent')->value) {
        return;
      }

      if ($entry->get('offer_expires')->isEmpty() || $entry->get('offer_token')->isEmpty()) {
        $this->loggerFactory->get('myeventlane_commerce')->notice(
          'Waitlist offer mail skipped for entry @id: missing expiry or token.',
          ['@id' => (string) $entryId]
        );
        return;
      }

      $expires = (int) $entry->get('offer_expires')->value;
      if ($expires <= $this->time->getRequestTime()) {
        return;
      }

      $mail = trim((string) ($entry->get('mail')->value ?? ''));
      if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $this->loggerFactory->get('myeventlane_commerce')->error(
          'Waitlist offer mail skipped for entry @id: invalid recipient.',
          ['@id' => (string) $entryId]
        );
        return;
      }

      $event = $entry->get('event')->entity;
      $tier = $entry->get('ticket_type')->entity;
      if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
        $this->loggerFactory->get('myeventlane_commerce')->error(
          'Waitlist offer mail skipped for entry @id: event missing.',
          ['@id' => (string) $entryId]
        );
        return;
      }

      if ($event->hasField('field_event_state')) {
        $state = (string) ($event->get('field_event_state')->value ?? '');
        if (in_array($state, ['cancelled', 'ended'], TRUE)) {
          return;
        }
      }

      if (!$tier instanceof \Drupal\mel_ticket\Entity\TicketTypeInterface) {
        $this->loggerFactory->get('myeventlane_commerce')->error(
          'Waitlist offer mail skipped for entry @id: tier missing.',
          ['@id' => (string) $entryId]
        );
        return;
      }

      $token = trim((string) ($entry->get('offer_token')->value ?? ''));
      if ($token === '') {
        return;
      }

      $claimUrl = Url::fromRoute(
        'myeventlane_commerce.event_ticket_waitlist_claim',
        ['node' => $event->id(), 'token' => $token],
        ['absolute' => TRUE]
      )->toString(TRUE)->getGeneratedUrl();

      $qty = (int) ($entry->get('offer_reserved')->value ?? 0);
      if ($qty < 1) {
        $qty = (int) ($entry->get('quantity')->value ?? 1);
      }

      $context = [
        'event_id' => (int) $event->id(),
        'waitlist_entry_id' => (int) $entry->id(),
        'offer_token' => $token,
        'event_title' => $event->label(),
        'tier_title' => $tier->label(),
        'reserved_quantity' => $qty,
        'expires_at' => $this->dateFormatter->format($expires, 'long'),
        'expires_at_short' => $this->dateFormatter->format($expires, 'short'),
        'claim_url' => $claimUrl,
        'event_url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      ];

      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        $startDate = $event->get('field_event_start')->date;
        if ($startDate) {
          $context['event_start'] = $this->dateFormatter->format($startDate->getTimestamp(), 'long');
        }
      }

      $logger = $this->loggerFactory->get('myeventlane_commerce');
      try {
        $messageId = $this->messagingManager->queue('ticket_tier_waitlist_offer', $mail, $context);
        if ($messageId !== NULL) {
          $logger->info(
            'Queued tier waitlist offer email for entry @id to @mail.',
            ['@id' => (string) $entryId, '@mail' => $mail]
          );
        }
        else {
          $logger->notice(
            'Tier waitlist offer email MessagingManager::queue returned null for entry @id (likely duplicate context; offer is still considered notified).',
            ['@id' => (string) $entryId]
          );
        }
      }
      catch (\Throwable $e) {
        $logger->error(
          'Tier waitlist offer email failed for entry @id: @message',
          ['@id' => (string) $entryId, '@message' => $e->getMessage()]
        );
        return;
      }

      // Mark sent after a successful queue attempt so cron does not re-dispatch the
      // same item; MessagingManager dedupes identical template+recipient+context.
      $entry->set('offer_mail_sent', TRUE);
      $entry->save();
    }
    finally {
      $this->lock->release($lock_id);
    }
  }

}
