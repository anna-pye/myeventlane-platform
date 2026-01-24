<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Scheduler;

use Drupal\Component\Datetime\TimeInterface as DrupalTimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\BoostManager;
use Psr\Log\LoggerInterface;

/**
 * Finds events expiring in the next 24h and queues reminder emails.
 */
final class BoostReminderScheduler {

  /**
   * Constructs a BoostReminderScheduler.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly DrupalTimeInterface $time,
    private readonly EntityTypeManagerInterface $etm,
    private readonly QueueFactory $queue,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly BoostManager $boostManager,
  ) {}

  /**
   * Scans for boosted events nearing expiry and queues reminder emails.
   *
   * Uses canonical BoostManager to find events expiring within 24 hours.
   */
  public function scan(): void {
    // Use canonical API to get events expiring within 24 hours.
    $nids = $this->boostManager->getExpiringBoostedEventIdsForStore(NULL, 24 * 3600, [
      'access_check' => FALSE,
    ]);

    if (empty($nids)) {
      $this->logger->notice('Boost reminder scan: no candidates in next 24h.');
      return;
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $storage = $this->etm->getStorage('node');
    $nodes = $storage->loadMultiple($nids);

    foreach ($nodes as $node) {
      $nid = (int) $node->id();
      $owner = $node->getOwner();
      $to = (string) $owner->getEmail();

      if (!$to) {
        $this->logger->warning('Boost reminder skipped for event @nid (no owner email).', ['@nid' => $nid]);
        continue;
      }

      // Get expiry date from boost status for consistent formatting.
      $boostStatus = $this->boostManager->getBoostStatusForEvent($node);
      $expiresTs = $boostStatus['end_timestamp'];

      $extendUrl = Url::fromUri('internal:/boost/' . $nid, ['absolute' => TRUE])->toString();

      $ctx = [
        'entity_id' => $nid,
        'title' => $node->label(),
        'extend_url' => $extendUrl,
      ];

      if ($expiresTs) {
        $ctx['expires_at'] = $this->dateFormatter->format($expiresTs, 'custom', 'j M Y, g:ia T');
      }

      \Drupal::service('myeventlane_messaging.manager')->queue('boost_reminder', $to, $ctx);
    }

    $this->logger->info('Boost reminder scan queued @count messages.', ['@count' => count($nids)]);
  }

}
