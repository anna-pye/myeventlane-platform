<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_ai\Provider\AiProviderInterface;
use Drupal\myeventlane_ai\Value\AiResult;
use Psr\Log\LoggerInterface;

/**
 * High-level AI manager with kill switch + rate limiting.
 */
final class AiManager {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly AiRateLimiter $rateLimiter,
    private readonly AiProviderInterface $provider,
  ) {}

  /**
   * Perform an AI analysis call.
   *
   * @param string $prompt
   *   The fully composed prompt.
   * @param array $options
   *   Provider-specific options.
   * @param int|null $requested_by_uid
   *   UID of the staff member who triggered the call, for rate limiting.
   * @param string|null $scope_id
   *   Scope identifier for rate limiting (e.g. "escalation:42").
   *
   * @return \Drupal\myeventlane_ai\Value\AiResult
   *   The AI result wrapper.
   */
  public function analyze(string $prompt, array $options = [], ?int $requested_by_uid = NULL, ?string $scope_id = NULL): AiResult {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    if (!$config->get('enabled')) {
      return AiResult::error('AI is disabled by configuration.', '', $this->provider->getName(), $options['model'] ?? NULL);
    }

    if ($requested_by_uid !== NULL && !$this->rateLimiter->checkAndRecordUser($requested_by_uid)) {
      return AiResult::error('Rate limit exceeded (user).', '', $this->provider->getName(), $options['model'] ?? NULL);
    }

    if ($scope_id !== NULL && !$this->rateLimiter->checkAndRecordScope($scope_id)) {
      return AiResult::error('Rate limit exceeded (scope).', '', $this->provider->getName(), $options['model'] ?? NULL);
    }

    // Never log raw prompts. Log a short signature only.
    $sig = substr(hash('sha256', $prompt), 0, 12);
    $this->logger->info('AI call start provider={provider} sig={sig}', [
      'provider' => $this->provider->getName(),
      'sig' => $sig,
    ]);

    $result = $this->provider->analyze($prompt, $options);

    $this->logger->info('AI call end provider={provider} ok={ok} sig={sig}', [
      'provider' => $this->provider->getName(),
      'ok' => $result->ok ? '1' : '0',
      'sig' => $sig,
    ]);

    return $result;
  }

}
