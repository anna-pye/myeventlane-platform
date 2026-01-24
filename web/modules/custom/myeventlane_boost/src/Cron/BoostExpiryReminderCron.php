<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Cron;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\BoostManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cron handler to send boost expiry reminder emails.
 */
final class BoostExpiryReminderCron implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a BoostExpiryReminderCron.
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
   * Process expiry reminders.
   *
   * Uses canonical BoostManager to find events expiring within 24 hours.
   */
  public function process(): void {
    // Use canonical API to get events expiring within 24 hours.
    $nids = $this->boostManager->getExpiringBoostedEventIdsForStore(NULL, 24 * 3600, [
      'access_check' => FALSE,
    ]);

    if (empty($nids)) {
      return;
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadMultiple($nids);
    $count = 0;

    foreach ($nodes as $node) {
      $owner = $node->getOwner();
      if ($owner === NULL) {
        continue;
      }

      $email = $owner->getEmail();
      if (empty($email)) {
        continue;
      }

      $boostUrl = Url::fromRoute('myeventlane_boost.boost_page', ['node' => $node->id()], ['absolute' => TRUE])
        ->toString();

      $params = [
        'subject' => $this->t('Your event boost expires soon'),
        'message' => $this->t('Heads up! The boost for "@title" expires in ~24 hours. Extend here: @url', [
          '@title' => $node->label(),
          '@url' => $boostUrl,
        ]),
      ];

      $langcode = $owner->getPreferredLangcode() ?: 'en';

      $this->mailManager->mail(
        'myeventlane_boost',
        'boost_expiring',
        $email,
        $langcode,
        $params,
        NULL,
        TRUE
      );

      $count++;
    }

    if ($count > 0) {
      $this->logger->notice('Sent boost expiry reminders for @count events.', ['@count' => $count]);
    }
  }

}
