<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Cron;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cron handler to expire boosted events and notify vendors.
 *
 * Uses canonical BoostManager to find expired boosts.
 */
final class BoostExpiryCron implements ContainerInjectionInterface {

  /**
   * Constructs a BoostExpiryCron.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\myeventlane_boost\BoostManager $boostManager
   *   The boost manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly MailManagerInterface $mailManager,
    private readonly BoostManager $boostManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_boost'),
      $container->get('plugin.manager.mail'),
      $container->get('myeventlane_boost.manager'),
    );
  }

  /**
   * Process expired boosts.
   *
   * Uses canonical BoostManager to find expired boosts, then clears them.
   */
  public function process(): void {
    // Use canonical API to get expired boosted events.
    $nids = $this->boostManager->getExpiredBoostedEventIdsForStore(NULL, [
      'access_check' => FALSE,
      'limit' => 500,
    ]);

    if (empty($nids)) {
      return;
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadMultiple($nids);
    $count = 0;

    foreach ($nodes as $node) {
      // Clear boost status.
      $node->set('field_promoted', 0);
      $node->set('field_promo_expires', NULL);
      $node->save();

      // Notify vendor.
      $this->notifyVendor($node);
      $count++;
    }

    if ($count > 0) {
      $this->logger->notice('Unboosted @count expired event(s) via cron.', ['@count' => $count]);
    }
  }

  /**
   * Send expiration notification to the event owner.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   */
  private function notifyVendor(NodeInterface $node): void {
    $owner = $node->getOwner();
    if ($owner === NULL) {
      return;
    }

    $email = $owner->getEmail();
    if (empty($email)) {
      return;
    }

    $params = [
      'node' => $node,
      'vendor_name' => $owner->getDisplayName(),
    ];

    $langcode = $owner->getPreferredLangcode() ?: 'en';

    $this->mailManager->mail(
      'myeventlane_boost',
      'boost_expired',
      $email,
      $langcode,
      $params,
      NULL,
      TRUE
    );
  }

}
