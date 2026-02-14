<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\myeventlane_ai\Entity\AiJob;

/**
 * Creates AI jobs and enqueues them.
 *
 * Prompt content stays in queue item only (placeholders); worker re-renders
 * from PromptRegistry. No prompt text stored in ai_job entity.
 */
final class AiJobEnqueueService {

  public const QUEUE_NAME = 'myeventlane_ai.jobs';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * Creates an ai_job (queued) and enqueues for async execution.
   *
   * @param string $prompt_key
   *   Prompt registry key (e.g. vendor_ai.answer).
   * @param string|null $prompt_version
   *   Version (e.g. v1). NULL defaults to v1.
   * @param array<string, string> $placeholders
   *   Placeholder values for template rendering.
   * @param array $options
   *   Provider options (max_tokens, etc.).
   * @param int $requested_by_uid
   *   User who triggered the request.
   * @param string|null $scope_id
   *   Scope for rate limiting.
   * @param int|null $vendor_id
   *   Vendor ID when in vendor context.
   *
   * @return \Drupal\myeventlane_ai\Entity\AiJob
   *   The created job (status queued).
   */
  public function enqueue(
    string $prompt_key,
    ?string $prompt_version,
    array $placeholders,
    array $options,
    int $requested_by_uid,
    ?string $scope_id = NULL,
    ?int $vendor_id = NULL,
  ): AiJob {
    $prompt_version = $prompt_version ?? 'v1';
    $prompt_hash = substr(hash('sha256', $prompt_key . '|' . $prompt_version . '|' . json_encode($placeholders)), 0, 64);

    $storage = $this->entityTypeManager->getStorage('ai_job');
    $values = [
      'uid' => $requested_by_uid,
      'scope' => $scope_id,
      'status' => AiJob::STATUS_QUEUED,
      'prompt_key' => $prompt_key,
      'prompt_version' => $prompt_version,
      'prompt_hash' => $prompt_hash,
    ];
    if ($vendor_id !== null) {
      $values['vendor_id'] = $vendor_id;
    }
    /** @var \Drupal\myeventlane_ai\Entity\AiJob $job */
    $job = $storage->create($values);
    $job->save();

    $item = [
      'job_id' => (int) $job->id(),
      'prompt_key' => $prompt_key,
      'prompt_version' => $prompt_version,
      'placeholders' => $placeholders,
      'options' => $options,
      'requested_by_uid' => $requested_by_uid,
      'scope_id' => $scope_id,
      'vendor_id' => $vendor_id,
    ];

    $this->queueFactory->get(self::QUEUE_NAME)->createItem($item);

    return $job;
  }

}
