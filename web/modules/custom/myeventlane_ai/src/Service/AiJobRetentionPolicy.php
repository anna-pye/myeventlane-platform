<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_ai\Entity\AiJob;

/**
 * Calculates expiry timestamps for terminal AI job states.
 */
final class AiJobRetentionPolicy {

  private const SECONDS_PER_DAY = 86400;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns an expiry timestamp, or zero for a non-terminal/disabled policy.
   */
  public function expiryFor(string $status, int $created): int {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $retention_days = match ($status) {
      AiJob::STATUS_DONE => (int) ($config->get('ai_job_retention_days') ?? 7),
      AiJob::STATUS_ERROR => (int) ($config->get('ai_job_error_retention_days') ?? 14),
      default => 0,
    };

    return $retention_days > 0
      ? $created + ($retention_days * self::SECONDS_PER_DAY)
      : 0;
  }

}
