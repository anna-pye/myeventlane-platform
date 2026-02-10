<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_sla\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_escalations_sla\Service\SlaEnforcer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes SLA checks for escalations during cron.
 *
 * @QueueWorker(
 *   id = "myeventlane_escalations_sla.check",
 *   title = @Translation("MyEventLane Escalations SLA check"),
 *   cron = {"time" = 30}
 * )
 */
final class EscalationSlaQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SlaEnforcer $enforcer,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_escalations_sla.enforcer'),
      $container->get('logger.channel.myeventlane_escalations_sla'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $escalation_id = (int) ($data['escalation_id'] ?? 0);
    if ($escalation_id <= 0) {
      $this->logger->warning('SLA queue item missing escalation_id.');
      return;
    }

    try {
      $this->enforcer->enforce($escalation_id);
    }
    catch (\Throwable $e) {
      $this->logger->error('SLA enforcement failed for escalation {id}: {msg}', [
        'id' => $escalation_id,
        'msg' => $e->getMessage(),
      ]);
    }
  }

}
