<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_ai\Provider\AiProviderInterface;
use Drupal\myeventlane_ai\Value\AiResult;
use Drupal\myeventlane_ai\Value\PromptDefinition;
use Psr\Log\LoggerInterface;

/**
 * High-level AI manager with kill switch, rate limiting, and cost guardrails.
 */
final class AiManager {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly AiRateLimiter $rateLimiter,
    private readonly AiUsageTracker $usageTracker,
    private readonly AiCircuitBreaker $circuitBreaker,
    private readonly AiProviderInterface $provider,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Perform an AI analysis call using a PromptDefinition.
   *
   * @param \Drupal\myeventlane_ai\Value\PromptDefinition $definition
   *   The rendered prompt (system + user messages).
   * @param array $options
   *   Provider-specific options.
   * @param int|null $requested_by_uid
   *   UID of the user who triggered the call, for rate limiting.
   * @param string|null $scope_id
   *   Scope identifier for rate limiting (e.g. "vendor_ai:escalation:42").
   * @param int|null $vendor_id
   *   Vendor ID when scope indicates vendor context. Resolved server-side by
   *   caller. Never trust request values.
   *
   * @return \Drupal\myeventlane_ai\Value\AiResult
   *   The AI result wrapper.
   */
  public function analyze(
    PromptDefinition $definition,
    array $options = [],
    ?int $requested_by_uid = NULL,
    ?string $scope_id = NULL,
    ?int $vendor_id = NULL,
  ): AiResult {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    if (!$config->get('enabled')) {
      return AiResult::error('AI is disabled by configuration.', '', $this->provider->getName(), $options['model'] ?? NULL);
    }

    $provider_name = $this->provider->getName();

    // Vendor opt-in: if vendor_id provided, vendor must have AI enabled.
    if ($vendor_id !== NULL) {
      try {
        if ($this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
          $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor_id);
          if (!$vendor || !$vendor->isAiEnabled()) {
            return AiResult::error('AI is disabled for this vendor.', '', $provider_name, $options['model'] ?? NULL);
          }
        }
      }
      catch (\Throwable $e) {
        return AiResult::error('AI is disabled for this vendor.', '', $provider_name, $options['model'] ?? NULL);
      }
    }

    if ($this->circuitBreaker->isTripped($provider_name)) {
      return AiResult::error('AI service temporarily unavailable. Please try again later.', '', $provider_name, $options['model'] ?? NULL);
    }

    if ($requested_by_uid !== NULL && !$this->rateLimiter->checkAndRecordUser($requested_by_uid)) {
      return AiResult::error('Rate limit exceeded (user).', '', $provider_name, $options['model'] ?? NULL);
    }

    if ($scope_id !== NULL && !$this->rateLimiter->checkAndRecordScope($scope_id)) {
      return AiResult::error('Rate limit exceeded (scope).', '', $provider_name, $options['model'] ?? NULL);
    }

    $max_tokens = (int) ($options['max_tokens'] ?? $config->get('openai.max_tokens') ?? 600);
    $reservation = max($max_tokens + 200, 100);

    if ($requested_by_uid !== NULL) {
      if ($this->usageTracker->wouldExceedUserLimit($requested_by_uid, $reservation)) {
        $this->logger->notice('AI request blocked: user quota exceeded uid={uid}', ['uid' => $requested_by_uid]);
        return AiResult::error('Daily AI usage limit reached. Please try again tomorrow.', '', $provider_name, $options['model'] ?? NULL);
      }
    }

    if ($vendor_id !== NULL) {
      if ($this->usageTracker->wouldExceedVendorLimit($vendor_id, $reservation)) {
        $this->logger->notice('AI request blocked: vendor quota exceeded vendor_id={vendor_id}', ['vendor_id' => $vendor_id]);
        return AiResult::error('Daily AI usage limit for this vendor reached. Please try again tomorrow.', '', $provider_name, $options['model'] ?? NULL);
      }
    }

    $sig = substr($definition->getPromptHash(), 0, 12);
    $this->logger->debug('AI call start provider={provider} key={key} sig={sig}', [
      'provider' => $provider_name,
      'key' => $definition->getKey(),
      'sig' => $sig,
    ]);

    $options_for_provider = $options;
    $options_for_provider['_messages'] = [
      ['role' => 'system', 'content' => $definition->getSystemMessage()],
      ['role' => 'user', 'content' => $definition->getUserMessage()],
    ];
    if ($requested_by_uid !== NULL) {
      $options_for_provider['_log_uid'] = $requested_by_uid;
    }
    if ($scope_id !== NULL) {
      $options_for_provider['_log_scope'] = $scope_id;
    }

    $fallback_prompt = $definition->getSystemMessage() . "\n\n" . $definition->getUserMessage();
    $result = $this->provider->analyze($fallback_prompt, $options_for_provider);

    $this->logger->debug('AI call end provider={provider} ok={ok} sig={sig}', [
      'provider' => $provider_name,
      'ok' => $result->ok ? '1' : '0',
      'sig' => $sig,
    ]);

    if ($result->ok) {
      $this->circuitBreaker->recordSuccess($provider_name);

      $total_tokens = $result->token_counts['total_tokens'] ?? 0;
      if ($total_tokens > 0 && $requested_by_uid !== NULL) {
        $this->usageTracker->recordUsage($requested_by_uid, $vendor_id, $total_tokens);
      }

      if ($result->estimated_cost_usd !== null && $result->estimated_cost_usd > 0) {
        $this->circuitBreaker->recordCost($provider_name, $result->estimated_cost_usd);
      }
    }
    else {
      $this->circuitBreaker->recordFailure($provider_name);
    }

    return $result;
  }

}
