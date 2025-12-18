<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Scheduler;

use Drupal\Component\Datetime\TimeInterface as DrupalTimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
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
  ) {}

  /**
   * Scans for boosted events nearing expiry and queues reminder emails.
   */
  public function scan(): void {
    $now = $this->time->getRequestTime();
    $upper = $now + 86400;

    // Query events where promo is active and ends within 24h.
    $storage = $this->etm->getStorage('node');
    $query = $storage->getQuery()
      ->condition('status', 1)
      ->condition('type', 'event')
      ->condition('field_promoted', 1)
      ->condition('field_promo_expires', gmdate('Y-m-d\\TH:i:s', $now), '>')
      ->condition('field_promo_expires', gmdate('Y-m-d\\TH:i:s', $upper), '<=')
      ->accessCheck(FALSE);

    $nids = $query->execute();
    if (empty($nids)) {
      $this->logger->notice('Boost reminder scan: no candidates in next 24h.');
      return;
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $storage->loadMultiple($nids);
    foreach ($nodes as $node) {
      $nid = (int) $node->id();
      $owner = $node->getOwner();
      $to = (string) $owner->getEmail();

      if (!$to) {
        $this->logger->warning('Boost reminder skipped for event @nid (no owner email).', ['@nid' => $nid]);
        continue;
      }

      $expires = (string) $node->get('field_promo_expires')->value;
      $expiresTs = $expires ? strtotime($expires . ' UTC') : NULL;

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
