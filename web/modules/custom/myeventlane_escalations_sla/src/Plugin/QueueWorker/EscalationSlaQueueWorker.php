<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_sla\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_escalations_ai\Service\EscalationAiJobEnqueuer;
use Drupal\myeventlane_escalations_sla\Service\SlaEnforcer;
use Drupal\myeventlane_escalations_sla\Service\SlaRiskDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes SLA checks for escalations during cron.
 *
 * After enforcement, evaluates risk and conditionally enqueues AI
 * breach_soon jobs if the myeventlane_escalations_ai module is enabled.
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
    private readonly SlaRiskDetector $riskDetector,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly ?EscalationAiJobEnqueuer $aiEnqueuer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    // Soft dependency: AI enqueuer is only available when
    // myeventlane_escalations_ai module is enabled.
    $ai_enqueuer = $container->has('myeventlane_escalations_ai.job_enqueuer')
      ? $container->get('myeventlane_escalations_ai.job_enqueuer')
      : NULL;

    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_escalations_sla.enforcer'),
      $container->get('myeventlane_escalations_sla.risk_detector'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.myeventlane_escalations_sla'),
      $ai_enqueuer,
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

    // Phase 1: SLA enforcement (breaches, level bumps).
    try {
      $this->enforcer->enforce($escalation_id);
    }
    catch (\Throwable $e) {
      $this->logger->error('SLA enforcement failed for escalation {id}: {msg}', [
        'id' => $escalation_id,
        'msg' => $e->getMessage(),
      ]);
    }

    // Phase 2: Risk detection + AI enqueue (non-blocking, advisory only).
    $this->evaluateAndEnqueueBreachSoonHint($escalation_id);
  }

  /**
   * Evaluates risk and enqueues a breach_soon AI job if eligible.
   *
   * Safety: This method is non-blocking. It does NOT change any SLA fields,
   * escalation level, or status. It does NOT send notifications.
   * All errors are caught and logged.
   *
   * @param int $escalation_id
   *   The escalation entity ID.
   */
  private function evaluateAndEnqueueBreachSoonHint(int $escalation_id): void {
    // Skip if AI enqueuer is not available.
    if (!$this->aiEnqueuer) {
      return;
    }

    try {
      $escalation = $this->entityTypeManager->getStorage('escalation')->load($escalation_id);
      if (!$escalation) {
        return;
      }

      /** @var \Drupal\myeventlane_escalations\Entity\Escalation $escalation */
      $risk = $this->riskDetector->evaluate($escalation);
      if (!$risk['eligible']) {
        return;
      }

      // Check if an unresolved breach_soon insight already exists.
      if ($this->hasExistingBreachSoonInsight($escalation_id)) {
        return;
      }

      // Enqueue AI job with risk metadata.
      $this->aiEnqueuer->enqueue($escalation_id, 'breach_soon', NULL, [
        'hours_remaining' => $risk['hours_remaining'],
        'waiting_on' => $risk['waiting_on'],
      ]);
    }
    catch (\Throwable $e) {
      // Non-blocking: log and continue.
      $this->logger->warning('Breach-soon risk evaluation failed for escalation {id}: {msg}', [
        'id' => $escalation_id,
        'msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Checks if an OK breach_soon AI insight already exists for this escalation.
   *
   * @param int $escalation_id
   *   The escalation entity ID.
   *
   * @return bool
   *   TRUE if an existing insight is found.
   */
  private function hasExistingBreachSoonInsight(int $escalation_id): bool {
    if (!$this->entityTypeManager->hasDefinition('escalation_ai_insight')) {
      return FALSE;
    }

    $ids = $this->entityTypeManager->getStorage('escalation_ai_insight')
      ->getQuery()
      ->condition('escalation_id', $escalation_id)
      ->condition('insight_type', 'breach_soon')
      ->condition('status', 'ok')
      ->accessCheck(FALSE)
      ->execute();

    return !empty($ids);
  }

}
