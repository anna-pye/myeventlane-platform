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

  /**
   * Generates an event title and short summary from optional user context.
   *
   * @param array<string, string> $context
   *   Keys: title, summary, category, tags, tone, audience (plain text; may be empty).
   * @param int|null $requested_by_uid
   *   User ID for rate limiting and usage accounting.
   * @param int|null $vendor_id
   *   Optional vendor ID for opt-in and quota (resolved server-side by caller).
   *
   * @return array{title: string, summary: string}
   *   Generated strings; may be empty if the model returns incomplete data.
   *
   * @throws \RuntimeException
   *   When the AI call fails or the response is not usable JSON.
   */
  public function generateEventCopy(array $context, ?int $requested_by_uid = NULL, ?int $vendor_id = NULL): array {
    $category = (string) ($context['category'] ?? '');
    $title = (string) ($context['title'] ?? '');
    $summary = (string) ($context['summary'] ?? '');
    $tags = (string) ($context['tags'] ?? '');
    $toneKey = (string) ($context['tone'] ?? 'community');
    $audienceKey = (string) ($context['audience'] ?? 'general');

    $toneDesc = $this->mapToneForEventCopy($toneKey);
    $audienceDesc = $this->mapAudienceForEventCopy($audienceKey);
    $categoryLine = $category !== '' ? $category : 'general';
    $tagsLine = $tags !== '' ? $tags : '(none)';

    $system = 'You are an event marketing assistant for MyEventLane. Respond with ONLY valid JSON (no markdown fences): {"title":"...","summary":"..."}. Title: max ~12 words, clear and engaging. Summary: 1–2 sentences on value and vibe, max ~300 characters. Match the requested tone and audience; avoid generic filler. Make it feel local, real, and specific.';
    $user = "Write a compelling event title and short summary.\n\nContext:\n- Category: {$categoryLine}\n- Tags / keywords: {$tagsLine}\n- Audience: {$audienceDesc}\n- Tone: {$toneDesc}\n\nExisting draft (may be empty):\n- Title: {$title}\n- Summary notes: {$summary}\n\nInstructions:\n- Reflect tone and audience in word choice.\n- Avoid generic phrases.\n";

    $hash = hash('sha256', $system . "\n" . $user);
    $definition = new PromptDefinition('event_studio.generate_event_copy', 'v2', $system, $user, $hash);

    $scopeId = 'event_studio:generate_copy:' . substr($hash, 0, 20);

    $config = $this->configFactory->get('myeventlane_ai.settings');
    $maxTokens = (int) ($config->get('openai.max_tokens') ?? 600);
    $maxTokens = max(200, min($maxTokens, 600));

    $result = $this->analyze(
      $definition,
      [
        'model' => (string) ($config->get('openai.model') ?: 'gpt-4o-mini'),
        'temperature' => (float) ($config->get('openai.temperature') ?? 0.35),
        'max_tokens' => $maxTokens,
        'timeout_seconds' => (int) ($config->get('openai.timeout_seconds') ?? 20),
      ],
      $requested_by_uid,
      $scopeId,
      $vendor_id,
    );

    if (!$result->ok) {
      throw new \RuntimeException($result->error ?? 'AI request failed');
    }

    $decoded = $result->json;
    if (!is_array($decoded)) {
      $trimmed = trim($result->raw);
      if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $try = json_decode($trimmed, TRUE);
        $decoded = is_array($try) ? $try : NULL;
      }
    }

    if (!is_array($decoded)) {
      $this->logger->notice('Event Studio AI: response was not valid JSON.');
      throw new \RuntimeException('AI response was not valid JSON');
    }

    return [
      'title' => trim((string) ($decoded['title'] ?? '')),
      'summary' => trim((string) ($decoded['summary'] ?? '')),
    ];
  }

  /**
   * Maps Event Studio tone key to prompt instructions.
   */
  private function mapToneForEventCopy(string $tone): string {
    return match ($tone) {
      'fun' => 'playful, energetic, exciting',
      'professional' => 'clear, structured, polished',
      'community' => 'inclusive, welcoming, friendly',
      'urgent' => 'high energy, time-sensitive, compelling',
      'luxury' => 'premium, exclusive, refined',
      default => 'friendly and engaging',
    };
  }

  /**
   * Maps Event Studio audience key to prompt instructions.
   */
  private function mapAudienceForEventCopy(string $audience): string {
    return match ($audience) {
      'lgbtq' => 'LGBTQIA+ community',
      'students' => 'young adults and students',
      'professionals' => 'working professionals',
      'families' => 'families and parents',
      default => 'general public',
    };
  }

}
