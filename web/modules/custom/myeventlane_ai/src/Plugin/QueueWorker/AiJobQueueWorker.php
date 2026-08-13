<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_ai\Entity\AiJob;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_ai\Service\AiJobRetentionPolicy;
use Drupal\myeventlane_ai\Service\PromptRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executes AI jobs asynchronously.
 *
 * @QueueWorker(
 *   id = "myeventlane_ai.jobs",
 *   title = @Translation("MyEventLane AI jobs"),
 *   cron = {"time" = 30}
 * )
 */
final class AiJobQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiManager $aiManager,
    private readonly PromptRegistry $promptRegistry,
    private readonly AiJobRetentionPolicy $retentionPolicy,
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
      $container->get('entity_type.manager'),
      $container->get('myeventlane_ai.manager'),
      $container->get('myeventlane_ai.prompt_registry'),
      $container->get('myeventlane_ai.job_retention_policy'),
      $container->get('logger.channel.myeventlane_ai'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $job_id = (int) ($data['job_id'] ?? 0);
    $prompt_key = (string) ($data['prompt_key'] ?? '');
    $prompt_version = isset($data['prompt_version']) ? (string) $data['prompt_version'] : 'v1';
    $placeholders = (array) ($data['placeholders'] ?? []);
    $options = (array) ($data['options'] ?? []);
    $requested_by_uid = isset($data['requested_by_uid']) ? (int) $data['requested_by_uid'] : NULL;
    $scope_id = isset($data['scope_id']) ? (string) $data['scope_id'] : NULL;
    $vendor_id = isset($data['vendor_id']) ? (int) $data['vendor_id'] : NULL;

    if ($job_id <= 0 || $prompt_key === '') {
      $this->logger->warning('AI job queue item missing job_id or prompt_key.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('ai_job');
    $job = $storage->load($job_id);
    if (!$job instanceof AiJob) {
      $this->logger->warning('AI job not found: {id}', ['id' => $job_id]);
      return;
    }

    $definition = $this->promptRegistry->render($prompt_key, $placeholders, $prompt_version);
    if ($definition === NULL) {
      $job->set('status', AiJob::STATUS_ERROR);
      $job->set('error_message', 'Prompt config missing or invalid.');
      $this->setExpiresAt($job);
      $job->save();
      return;
    }

    $job->set('status', AiJob::STATUS_RUNNING);
    $job->save();

    $result = $this->aiManager->analyze(
      $definition,
      $options,
      $requested_by_uid,
      $scope_id,
      $vendor_id ?: NULL,
    );

    if ($result->ok) {
      $job->set('status', AiJob::STATUS_DONE);
      $job->set('result_text', trim($result->raw));
      $job->set('model', $result->model ?? '');
      $job->set('prompt_hash', $definition->getPromptHash());
      if ($result->token_counts !== NULL) {
        $job->set('token_counts', json_encode($result->token_counts));
      }
      if ($result->estimated_cost_usd !== NULL) {
        $job->set('estimated_cost_usd', $result->estimated_cost_usd);
      }
    }
    else {
      $job->set('status', AiJob::STATUS_ERROR);
      $job->set('error_message', substr($result->error ?? 'Unknown error', 0, 255));
    }

    $this->setExpiresAt($job);

    $job->save();
  }

  /**
   * Sets expires_at when job completes (done or error).
   */
  private function setExpiresAt(AiJob $job): void {
    $expires_at = $this->retentionPolicy->expiryFor(
      (string) $job->get('status')->value,
      (int) $job->get('created')->value,
    );
    if ($expires_at > 0) {
      $job->set('expires_at', $expires_at);
    }
  }

}
