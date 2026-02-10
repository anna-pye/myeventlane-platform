<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_escalations_ai\Service\EscalationAiContextBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes AI jobs for escalations.
 *
 * @QueueWorker(
 *   id = "myeventlane_escalations_ai.jobs",
 *   title = @Translation("MyEventLane Escalations AI jobs"),
 *   cron = {"time" = 15}
 * )
 */
final class EscalationAiQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EscalationAiContextBuilder $contextBuilder,
    private readonly AiManager $aiManager,
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
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_escalations_ai.context_builder'),
      $container->get('myeventlane_ai.manager'),
      $container->get('logger.channel.myeventlane_escalations_ai'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $settings = $this->configFactory->get('myeventlane_escalations_ai.settings');
    if (!$settings->get('enabled')) {
      return;
    }

    $escalation_id = (int) ($data['escalation_id'] ?? 0);
    $task = (string) ($data['task'] ?? '');
    $requested_by_uid = isset($data['requested_by_uid']) ? (int) $data['requested_by_uid'] : NULL;

    if ($escalation_id <= 0 || $task === '') {
      $this->logger->warning('AI job missing escalation_id or task.');
      return;
    }

    // Build sanitised context.
    $context = $this->contextBuilder->build($escalation_id);
    $context_json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Truncate if too large.
    $max_context = (int) ($settings->get('ai_options.max_context_chars') ?? 8000);
    if (is_string($context_json) && strlen($context_json) > $max_context) {
      $context_json = substr($context_json, 0, $max_context);
    }
    if (!is_string($context_json)) {
      $context_json = '{"error":"context_encode_failed"}';
    }

    // Load prompt template.
    $prompt_template = (string) ($settings->get('prompts.' . $task . '.template') ?? '');
    $prompt_version = (string) ($settings->get('prompts.' . $task . '.version') ?? 'v1');

    if ($prompt_template === '') {
      $this->storeInsight($escalation_id, $task, 'error', $prompt_version, 'Missing prompt template for task: ' . $task, '{}', NULL, NULL);
      return;
    }

    // Compose prompt.
    $prompt = str_replace('{{context_json}}', $context_json, $prompt_template);

    // Call AI provider via AiManager.
    $provider_options = (array) ($settings->get('ai_options.provider_options') ?? []);
    $result = $this->aiManager->analyze(
      $prompt,
      $provider_options,
      $requested_by_uid,
      'escalation:' . $escalation_id
    );

    if (!$result->ok) {
      $this->storeInsight($escalation_id, $task, 'error', $prompt_version, (string) $result->error, (string) $result->raw, $result->provider, $result->model);
      return;
    }

    // Prefer normalised JSON if available.
    $payload = $result->raw;
    if (is_array($result->json)) {
      $payload = json_encode($result->json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $result->raw;
    }

    $this->storeInsight($escalation_id, $task, 'ok', $prompt_version, '', (string) $payload, $result->provider, $result->model);
  }

  /**
   * Stores an AI insight entity (append-only).
   */
  private function storeInsight(
    int $escalation_id,
    string $task,
    string $status,
    string $prompt_version,
    string $error_message,
    string $payload,
    ?string $provider,
    ?string $model,
  ): void {
    try {
      $storage = $this->entityTypeManager->getStorage('escalation_ai_insight');

      /** @var \Drupal\myeventlane_escalations_ai\Entity\EscalationAiInsight $entity */
      $entity = $storage->create([
        'escalation_id' => $escalation_id,
        'insight_type' => $task,
        'confidence' => '0',
        'is_internal' => TRUE,
        'payload_json' => $payload,
        'prompt_version' => $prompt_version,
        'provider' => trim(($provider ?? '') . ($model ? ':' . $model : '')),
        'status' => $status,
        'error_message' => $error_message,
      ]);

      $entity->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to store AI insight for escalation {id}: {msg}', [
        'id' => $escalation_id,
        'msg' => $e->getMessage(),
      ]);
    }
  }

}
