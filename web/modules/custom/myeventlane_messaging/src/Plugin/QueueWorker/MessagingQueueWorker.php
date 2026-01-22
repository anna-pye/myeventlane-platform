<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued MyEventLane messages.
 *
 * @QueueWorker(
 *   id = "myeventlane_messaging",
 *   title = @Translation("MyEventLane Messaging queue"),
 *   cron = {"time" = 60}
 * )
 */
final class MessagingQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs MessagingQueueWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\myeventlane_messaging\Service\MessagingManager $messagingManager
   *   The messaging manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MessagingManager $messagingManager,
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
      $container->get('myeventlane_messaging.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $this->messagingManager->sendNow($data);
  }

}
