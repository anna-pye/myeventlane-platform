<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_ai\Service\AiManager;
use Drupal\myeventlane_ai\Service\PromptRegistry;
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

  private const TASK_TO_KEY = [
    'triage' => 'escalation.triage',
    'reply_suggestion' => 'escalation.reply_suggestion',
    'risk_flag' => 'escalation.risk_flag',
    'breach_soon' => 'escalation.breach_soon',
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EscalationAiContextBuilder $contextBuilder,
    private readonly AiManager $aiManager,
    private readonly PromptRegistry $promptRegistry,
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
      $container->get('myeventlane_ai.prompt_registry'),
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

    $prompt_key = self::TASK_TO_KEY[$task] ?? NULL;
    if ($prompt_key === NULL) {
      $this->logger->warning('Unknown escalation AI task: {task}', ['task' => $task]);
      return;
    }

    if ($task === 'breach_soon') {
      $context = $this->contextBuilder->buildWithThread($escalation_id);
    }
    else {
      $context = $this->contextBuilder->build($escalation_id);
    }

    $context_json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $max_context = (int) ($settings->get('ai_options.max_context_chars') ?? 8000);
    if (is_string($context_json) && strlen($context_json) > $max_context) {
      $context_json = substr($context_json, 0, $max_context);
    }
    if (!is_string($context_json)) {
      $context_json = '{"error":"context_encode_failed"}';
    }

    $placeholders = ['context_json' => $context_json];
    if ($task === 'breach_soon') {
      $placeholders['hours_remaining'] = (string) ($data['hours_remaining'] ?? '');
      $placeholders['waiting_on'] = (string) ($data['waiting_on'] ?? '');
    }

    $definition = $this->promptRegistry->render($prompt_key, $placeholders, 'v1');
    if ($definition === NULL) {
      $this->storeInsight($escalation_id, $task, 'error', $prompt_key, 'v1', 'Missing or invalid prompt config for task: ' . $task, '{}', NULL, NULL);
      return;
    }

    $provider_options = (array) ($settings->get('ai_options.provider_options') ?? []);
    $result = $this->aiManager->analyze(
      $definition,
      $provider_options,
      $requested_by_uid,
      'escalation:' . $escalation_id,
      NULL,
    );

    if (!$result->ok) {
      $this->storeInsight($escalation_id, $task, 'error', $prompt_key, 'v1', (string) $result->error, (string) $result->raw, $result->provider, $result->model);
      return;
    }

    $payload = $result->raw;
    if (is_array($result->json)) {
      $payload = json_encode($result->json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $result->raw;
    }

    $this->storeInsight($escalation_id, $task, 'ok', $prompt_key, 'v1', '', (string) $payload, $result->provider, $result->model);
  }

  /**
   * Stores an AI insight entity (append-only).
   */
  private function storeInsight(
    int $escalation_id,
    string $task,
    string $status,
    string $prompt_key,
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
        'prompt_key' => $prompt_key,
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
