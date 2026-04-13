<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
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
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $timeoutSeconds = (int) ($config->get('openai.timeout_seconds') ?? 20);
    return $this->performEventCopyGeneration($context, $requested_by_uid, $vendor_id, $timeoutSeconds, 1);
  }

  /**
   * Same generation as generateEventCopy with retries, timeout, and mock fallback.
   *
   * Returns ranked variants with scores; title/summary reflect the best-ranked variant.
   *
   * @param array<string, mixed> $payload
   *   Keys: title, summary, category, tags, tone, audience.
   * @param int|null $requested_by_uid
   *   User ID for rate limiting and usage accounting.
   * @param int|null $vendor_id
   *   Optional vendor ID for opt-in and quota.
   *
   * @return array{ok: bool, title: string, summary: string, error: string, variants?: array<int, array<string, mixed>>}
   *   Always a safe structure for JSON responses.
   */
  public function generateEventCopyReliable(array $payload, ?int $requested_by_uid = NULL, ?int $vendor_id = NULL): array {
    return $this->generateEventCopyVariantsReliable($payload, $requested_by_uid, $vendor_id);
  }

  /**
   * Generates multiple variants with deterministic scoring and a marked best option.
   *
   * @param array<string, mixed> $payload
   *   Keys: title, summary, category, tags, tone, audience.
   * @param int|null $requested_by_uid
   *   User ID for rate limiting and usage accounting.
   * @param int|null $vendor_id
   *   Optional vendor ID for opt-in and quota.
   *
   * @return array{ok: bool, title: string, summary: string, error: string, variants?: array<int, array<string, mixed>>}
   */
  public function generateEventCopyVariantsReliable(array $payload, ?int $requested_by_uid = NULL, ?int $vendor_id = NULL): array {
    $attempt = 0;
    $lastError = NULL;

    while ($attempt <= $this->maxRetries()) {
      try {
        return $this->buildVariantsForProvider($this->primaryProvider(), $payload, $requested_by_uid, $vendor_id);
      }
      catch (\Throwable $e) {
        $lastError = $e->getMessage();
        if (!$this->isRetryable($e)) {
          break;
        }
        $this->logger->notice('AI event copy retry: attempt=@attempt error=@msg', [
          '@attempt' => (string) $attempt,
          '@msg' => $lastError,
        ]);
        if ($attempt < $this->maxRetries()) {
          $this->sleepBackoff($attempt);
        }
        $attempt++;
      }
    }

    try {
      return $this->buildVariantsForProvider($this->fallbackProvider(), $payload, $requested_by_uid, $vendor_id);
    }
    catch (\Throwable $e) {
      $this->logger->error('AI event copy fallback failed: @msg', ['@msg' => $e->getMessage()]);
      return [
        'ok' => FALSE,
        'error' => 'AI unavailable',
        'title' => '',
        'summary' => '',
        'variants' => [],
      ];
    }
  }

  /**
   * Rewrites About + What to expect with retries and mock fallback (unchanged text).
   *
   * @param array<string, mixed> $payload
   *   Keys: about, what_to_expect, category, tone, audience.
   *
   * @return array{ok: bool, about: string, what_to_expect: string, error: string}
   */
  public function rewriteEventContentReliable(array $payload, ?int $requested_by_uid = NULL, ?int $vendor_id = NULL): array {
    $aboutIn = trim((string) ($payload['about'] ?? ''));
    $expectIn = trim((string) ($payload['what_to_expect'] ?? ''));

    if ($aboutIn === '' && $expectIn === '') {
      return [
        'ok' => FALSE,
        'error' => 'No content to rewrite',
        'about' => '',
        'what_to_expect' => '',
      ];
    }

    $attempt = 0;
    $lastError = NULL;

    while ($attempt <= $this->maxRetries()) {
      try {
        return $this->rewriteEventContentForProvider($this->primaryProvider(), $payload, $requested_by_uid, $vendor_id);
      }
      catch (\Throwable $e) {
        $lastError = $e->getMessage();
        if (!$this->isRetryable($e)) {
          break;
        }
        $this->logger->notice('AI content rewrite retry: attempt=@attempt error=@msg', [
          '@attempt' => (string) $attempt,
          '@msg' => $lastError,
        ]);
        if ($attempt < $this->maxRetries()) {
          $this->sleepBackoff($attempt);
        }
        $attempt++;
      }
    }

    try {
      return $this->rewriteEventContentForProvider($this->fallbackProvider(), $payload, $requested_by_uid, $vendor_id);
    }
    catch (\Throwable $e) {
      $this->logger->error('AI content rewrite fallback failed: @msg', ['@msg' => $e->getMessage()]);
      return [
        'ok' => FALSE,
        'error' => 'AI unavailable',
        'about' => $aboutIn,
        'what_to_expect' => $expectIn,
      ];
    }
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return array{ok: bool, about: string, what_to_expect: string, error: string}
   */
  private function rewriteEventContentForProvider(string $provider, array $payload, ?int $requested_by_uid, ?int $vendor_id): array {
    if ($provider === 'openai') {
      if ($this->apiKey() === '') {
        throw new \RuntimeException('missing api key');
      }
      return $this->rewriteEventContent($payload, $requested_by_uid, $vendor_id);
    }

    if ($provider === 'mock') {
      $aboutIn = trim((string) ($payload['about'] ?? ''));
      $expectIn = trim((string) ($payload['what_to_expect'] ?? ''));
      return [
        'ok' => TRUE,
        'about' => $aboutIn,
        'what_to_expect' => $expectIn,
        'error' => '',
      ];
    }

    throw new \RuntimeException('Unknown provider');
  }

  /**
   * Single-provider rewrite using the shared analyze() pipeline.
   *
   * @param array<string, mixed> $payload
   *   Keys: about, what_to_expect, category, tone, audience.
   *
   * @return array{ok: bool, about: string, what_to_expect: string, error: string}
   */
  private function rewriteEventContent(array $payload, ?int $requested_by_uid = NULL, ?int $vendor_id = NULL): array {
    $category = (string) ($payload['category'] ?? 'Community');
    $tone = $this->mapToneForEventCopy($payload['tone'] ?? 'community');
    $audience = $this->mapAudienceForEventCopy($payload['audience'] ?? 'general');

    $about = trim((string) ($payload['about'] ?? ''));
    $expect = trim((string) ($payload['what_to_expect'] ?? ''));

    $system = <<<SYS
You improve event descriptions.

Rules:
- Do NOT change meaning
- Do NOT invent details
- Keep factual accuracy
- Improve clarity and readability
- Improve flow and structure
- Optimise for attendance and engagement
- No emojis or hashtags

Output JSON:
{
  "about": "...",
  "what_to_expect": "..."
}
SYS;

    $user = <<<USR
Rewrite the following event content.

Category: {$category}
Tone: {$tone}
Audience: {$audience}

About:
{$about}

What to expect:
{$expect}

Return improved versions only.
USR;

    $hash = hash('sha256', $system . "\n" . $user);
    $definition = new PromptDefinition('event_studio.rewrite_content', 'v1', $system, $user, $hash);

    $scopeId = 'event_studio:rewrite:' . substr($hash, 0, 20);

    $config = $this->configFactory->get('myeventlane_ai.settings');
    $timeoutSeconds = (int) max(1, (int) round($this->reliableTimeoutMs() / 1000));
    $maxTokens = (int) ($config->get('openai.max_tokens') ?? 600);
    $maxTokens = max(400, min($maxTokens, 1200));

    $result = $this->analyze(
      $definition,
      [
        'model' => (string) ($config->get('openai.model') ?: 'gpt-4o-mini'),
        'temperature' => (float) ($config->get('openai.temperature') ?? 0.35),
        'max_tokens' => $maxTokens,
        'timeout_seconds' => $timeoutSeconds,
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
      $this->logger->notice('Event Studio AI rewrite: response was not valid JSON.');
      throw new \RuntimeException('AI response was not valid JSON');
    }

    $aboutOut = trim((string) ($decoded['about'] ?? ''));
    $expectOut = trim((string) ($decoded['what_to_expect'] ?? ''));

    if ($aboutOut === '') {
      $aboutOut = $about;
    }

    if ($expectOut === '') {
      $expectOut = $expect;
    }

    return [
      'ok' => TRUE,
      'about' => $aboutOut,
      'what_to_expect' => $expectOut,
      'error' => '',
    ];
  }

  /**
   * OpenAI API key (same resolution as OpenAiProvider).
   */
  private function apiKey(): string {
    $api_key = (string) (getenv('MEL_OPENAI_API_KEY') ?: '');
    if ($api_key === '') {
      $from_settings = Settings::get('myeventlane_ai');
      if (is_array($from_settings)) {
        $api_key = (string) ($from_settings['api_key'] ?? '');
      }
    }
    return $api_key;
  }

  /**
   * Reliable-layer primary provider (config or default openai).
   */
  private function primaryProvider(): string {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $p = $config->get('event_copy_reliable.primary');
    if (is_string($p) && $p !== '') {
      return $p;
    }
    $mel = Settings::get('myeventlane_ai');
    if (is_array($mel) && isset($mel['event_copy_reliable_primary']) && is_string($mel['event_copy_reliable_primary']) && $mel['event_copy_reliable_primary'] !== '') {
      return $mel['event_copy_reliable_primary'];
    }
    return 'openai';
  }

  /**
   * Reliable-layer fallback provider (config or default mock).
   */
  private function fallbackProvider(): string {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $p = $config->get('event_copy_reliable.fallback');
    if (is_string($p) && $p !== '') {
      return $p;
    }
    $mel = Settings::get('myeventlane_ai');
    if (is_array($mel) && isset($mel['event_copy_reliable_fallback']) && is_string($mel['event_copy_reliable_fallback']) && $mel['event_copy_reliable_fallback'] !== '') {
      return $mel['event_copy_reliable_fallback'];
    }
    return 'mock';
  }

  /**
   * Timeout for reliable OpenAI attempts (ms), from config or defaults.
   */
  private function reliableTimeoutMs(): int {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $ms = $config->get('event_copy_reliable.timeout_ms');
    if ($ms !== NULL && $ms !== '') {
      return max(500, (int) $ms);
    }
    $mel = Settings::get('myeventlane_ai');
    if (is_array($mel) && isset($mel['event_copy_reliable_timeout_ms'])) {
      return max(500, (int) $mel['event_copy_reliable_timeout_ms']);
    }
    return 8000;
  }

  private function maxRetries(): int {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $v = $config->get('event_copy_reliable.max_retries');
    if ($v !== NULL && $v !== '') {
      return max(0, (int) $v);
    }
    $mel = Settings::get('myeventlane_ai');
    if (is_array($mel) && isset($mel['event_copy_reliable_max_retries'])) {
      return max(0, (int) $mel['event_copy_reliable_max_retries']);
    }
    return 2;
  }

  private function baseDelayMs(): int {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $v = $config->get('event_copy_reliable.base_delay_ms');
    if ($v !== NULL && $v !== '') {
      return max(50, (int) $v);
    }
    $mel = Settings::get('myeventlane_ai');
    if (is_array($mel) && isset($mel['event_copy_reliable_base_delay_ms'])) {
      return max(50, (int) $mel['event_copy_reliable_base_delay_ms']);
    }
    return 300;
  }

  private function isRetryable(\Throwable $e): bool {
    $msg = strtolower($e->getMessage());

    // Drupal Flood quota (per user / per scope): retrying immediately never helps.
    if (str_contains($msg, 'rate limit exceeded (user)') || str_contains($msg, 'rate limit exceeded (scope)')) {
      return FALSE;
    }

    return str_contains($msg, 'timeout')
      || str_contains($msg, '429')
      || str_contains($msg, 'rate limit')
      || str_contains($msg, 'temporarily')
      || str_contains($msg, 'connection');
  }

  private function sleepBackoff(int $attempt): void {
    $base = $this->baseDelayMs();
    $delay = (int) ($base * (2 ** $attempt));
    usleep($delay * 1000);
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return array{ok: bool, title: string, summary: string, error: string, variants: array<int, array<string, mixed>>}
   */
  private function buildVariantsForProvider(string $provider, array $payload, ?int $requested_by_uid, ?int $vendor_id): array {
    $timeout = (int) max(1, (int) round($this->reliableTimeoutMs() / 1000));

    if ($provider === 'openai') {
      if ($this->apiKey() === '') {
        throw new \RuntimeException('missing api key');
      }

      $context = $this->normalizeEventCopyPayload($payload);
      if (array_key_exists('tags', $payload)) {
        $context['tags'] = $payload['tags'];
      }

      // One OpenAI request returns all variants so we only consume one per-user rate limit slot.
      $pairs = $this->performEventCopyTripleVariantGeneration($context, $requested_by_uid, $vendor_id, $timeout);

      if ($pairs === []) {
        throw new \RuntimeException('all variants failed');
      }

      return $this->finalizeScoredVariants($pairs, $payload);
    }

    if ($provider === 'mock') {
      $mock = $this->mockResponse($payload);
      if (empty($mock['ok'])) {
        throw new \RuntimeException('mock unavailable');
      }
      $pairs = [
        [
          'title' => $mock['title'],
          'summary' => $mock['summary'],
        ],
      ];
      return $this->finalizeScoredVariants($pairs, $payload);
    }

    throw new \RuntimeException('Unknown provider');
  }

  /**
   * Dedupe, score, sort (stable on ties), renumber ids, set best, log.
   *
   * @param array<int, array{title: string, summary: string}> $pairs
   * @param array<string, mixed> $payload
   *
   * @return array{ok: true, title: string, summary: string, error: string, variants: array<int, array<string, mixed>>}
   */
  private function finalizeScoredVariants(array $pairs, array $payload): array {
    $seen = [];
    $deduped = [];
    foreach ($pairs as $p) {
      $key = strtolower(trim($p['title'])) . '|' . strtolower(trim($p['summary']));
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $deduped[] = $p;
    }

    $variants = [];
    foreach ($deduped as $idx => $p) {
      $variants[] = [
        'id' => 'v' . ($idx + 1),
        'title' => $p['title'],
        'summary' => $p['summary'],
      ];
    }

    foreach ($variants as &$variant) {
      $variant['score'] = $this->scoreVariant($variant, (string) ($payload['category'] ?? ''));
    }
    unset($variant);

    foreach ($variants as $i => &$variant) {
      $variant['_ord'] = $i;
    }
    unset($variant);

    usort($variants, function (array $a, array $b): int {
      $c = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
      if ($c !== 0) {
        return $c;
      }
      return ($a['_ord'] ?? 0) <=> ($b['_ord'] ?? 0);
    });

    foreach ($variants as &$v) {
      unset($v['_ord']);
    }
    unset($v);

    foreach ($variants as $idx => &$v) {
      $v['id'] = 'v' . ($idx + 1);
      $v['best'] = ($idx === 0);
    }
    unset($v);

    \Drupal::logger('myeventlane_ai')->notice('AI variant scores: @scores', [
      '@scores' => json_encode(array_map(static fn(array $v): int => (int) ($v['score'] ?? 0), $variants)),
    ]);

    return [
      'ok' => TRUE,
      'title' => $variants[0]['title'],
      'summary' => $variants[0]['summary'],
      'error' => '',
      'variants' => $variants,
    ];
  }

  private function optimiseTitleForConversion(string $title, string $category): string {
    $title = trim($title);

    // Ensure strong structure
    if (!preg_match('/\b(workshop|party|meetup|class|session|event)\b/i', $title)) {
      $title .= ' ' . $category . ' Event';
    }

    // Remove weak words
    $title = (string) preg_replace('/\b(amazing|great|nice|fun)\b/i', '', $title);

    // Collapse whitespace
    $title = (string) preg_replace('/\s+/', ' ', $title);

    return trim($title);
  }

  /**
   * Splits "Title: subtitle" style strings for short titles + what-to-expect merge.
   *
   * @return array{title: string, extra: string}
   */
  private function splitTitleForConversion(string $title): array {
    $parts = explode(':', $title, 2);

    if (count($parts) < 2) {
      return [
        'title' => trim($title),
        'extra' => '',
      ];
    }

    return [
      'title' => trim($parts[0]),
      'extra' => trim($parts[1]),
    ];
  }

  /**
   * Enforces a single leading action verb for conversion-style headlines.
   */
  private function enforceHeadlinePattern(string $title, string $category): string {
    $title = trim($title);

    if (preg_match('/^(join|discover|experience|learn|celebrate|explore)\b/i', $title)) {
      $title = (string) preg_replace('/\s+/', ' ', $title);
      $title = trim($title);

      return mb_substr($title, 0, 60);
    }

    $verbMap = [
      'Music' => 'Experience',
      'Workshop' => 'Learn',
      'Community' => 'Join',
      'LGBT' => 'Celebrate',
      'Food' => 'Discover',
    ];

    $verb = 'Join';
    $catTrim = trim($category);
    foreach ($verbMap as $key => $v) {
      if ($catTrim !== '' && stripos($catTrim, $key) !== FALSE) {
        $verb = $v;
        break;
      }
    }

    $title = (string) preg_replace('/^(the|a|an)\s+/i', '', $title);
    $title = (string) preg_replace('/^(join|discover|experience|learn|celebrate|explore)\s+/i', '', $title);
    $title = trim($title);

    if ($title === '') {
      $fallback = $catTrim !== '' ? $catTrim : 'Event';
      $title = $verb . ' ' . $fallback;
    }
    else {
      $title = $verb . ' ' . ucfirst($title);
    }

    $title = (string) preg_replace('/\s+/', ' ', $title);
    $title = trim($title);

    return mb_substr($title, 0, 60);
  }

  /**
   * @param array<string, mixed> $variant
   */
  private function scoreVariant(array $variant, string $category): int {
    $score = 0;

    $title = strtolower($variant['title'] ?? '');
    $summary = strtolower($variant['summary'] ?? '');

    // Length sweet spot
    $len = strlen($title);
    if ($len >= 20 && $len <= 65) {
      $score += 20;
    }

    // Keyword relevance
    if (str_contains($title, strtolower($category))) {
      $score += 15;
    }

    // Actionability
    if (preg_match('/\b(join|learn|discover|experience|build)\b/', $title)) {
      $score += 20;
    }

    // Specificity (numbers, time, or detail)
    if (preg_match('/\d/', $title)) {
      $score += 10;
    }

    // Summary completeness
    if (strlen($summary) > 80) {
      $score += 15;
    }

    // Penalty for vague words
    if (preg_match('/\b(amazing|great|fun|nice)\b/', $title)) {
      $score -= 10;
    }

    return $score;
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return array<string, string>
   */
  private function normalizeEventCopyPayload(array $payload): array {
    return [
      'title' => (string) ($payload['title'] ?? ''),
      'summary' => (string) ($payload['summary'] ?? ''),
      'category' => (string) ($payload['category'] ?? ''),
      'tags' => (string) ($payload['tags'] ?? ''),
      'tone' => (string) ($payload['tone'] ?? 'community'),
      'audience' => (string) ($payload['audience'] ?? 'general'),
    ];
  }

  /**
   * Normalises one title/summary pair from the model (fallbacks, length, cleanup, conversion optimiser).
   *
   * @return array{title: string, summary: string}
   */
  private function finalizeEventCopyPair(string $category, string $audienceDesc, string $title, string $summary): array {
    $title = trim($title);
    $summary = trim($summary);

    if ($title === '' || strlen($title) < 5) {
      $title = ucfirst($category) . ' Event';
    }

    if ($summary === '' || strlen($summary) < 20) {
      $summary = 'Join us for an engaging ' . strtolower($category) . ' event designed for ' . strtolower($audienceDesc) . '.';
    }

    $title = mb_substr($title, 0, 80);
    $summary = mb_substr($summary, 0, 200);

    $title = (string) preg_replace('/[#@]/', '', $title);
    $summary = (string) preg_replace('/[#@]/', '', $summary);

    $title = $this->optimiseTitleForConversion($title, $category);
    $title = mb_substr($title, 0, 80);

    $split = $this->splitTitleForConversion($title);

    $title = $split['title'];
    $title = $this->enforceHeadlinePattern($title, $category);
    $titleExtra = $split['extra'];

    if ($titleExtra !== '') {
      $summary = $titleExtra . '. ' . $summary;
    }

    $summary = (string) preg_replace('/\.\s*\./', '.', $summary);
    $summary = trim($summary);

    $summary = (string) preg_replace('/\b(food & drink,?\s*)+/i', '', $summary);
    $summary = trim($summary);

    $summary = mb_substr($summary, 0, 200);

    return [
      'title' => $title,
      'summary' => $summary,
    ];
  }

  /**
   * One HTTP request: three distinct title/summary variants (single rate-limit + usage slot).
   *
   * @param array<string, mixed> $context
   *
   * @return array<int, array{title: string, summary: string}>
   */
  private function performEventCopyTripleVariantGeneration(array $context, ?int $requested_by_uid, ?int $vendor_id, int $timeoutSeconds): array {
    $payload = $context;

    $category = $payload['category'] ?? 'Community';
    $category = (string) $category;

    $rawTags = $payload['tags'] ?? '';
    if (is_array($rawTags)) {
      $tags = implode(', ', array_filter(array_map('trim', $rawTags)));
    }
    else {
      $tags = trim((string) $rawTags);
    }

    $tone = $this->mapToneForEventCopy($payload['tone'] ?? 'community');
    $audience = $this->mapAudienceForEventCopy($payload['audience'] ?? 'general');

    $contextParts = array_filter([
      trim((string) ($payload['title'] ?? '')),
      trim((string) ($payload['summary'] ?? '')),
    ]);
    $contextText = implode('. ', $contextParts);

    $variantSeed = substr(md5($contextText), 0, 6);

    $system = <<<SYS
You generate high-quality event listings for a community event platform.

Rules:
- Output must be concise, clear, and engaging
- No emojis
- No hashtags
- No fluff or repetition
- Match tone and audience exactly
- Always return structured JSON

Produce exactly 3 distinct title/summary pairs (different angles or emphasis). Each pair must follow:
- title: max 80 characters
- summary: 1–2 sentences, max 200 characters
- Optimise titles for click-through and attendance
- Prefer clear, specific, benefit-driven language
- Avoid vague wording like "amazing" or "great"
- Use concrete hooks (who, what, when, why)
- Keep titles under 80 characters

Output JSON only:
{
  "variants": [
    {"title":"...","summary":"..."},
    {"title":"...","summary":"..."},
    {"title":"...","summary":"..."}
  ]
}
SYS;

    $user = <<<USR
Create three distinct event listing options using:

Category: {$category}
Tags: {$tags}
Tone: {$tone}
Audience: {$audience}

Context:
{$contextText}

Variant seed: {$variantSeed}

Return JSON only with the variants array as specified.
USR;

    \Drupal::logger('myeventlane_ai')->notice('AI input category=@c tone=@t audience=@a', [
      '@c' => $category,
      '@t' => $tone,
      '@a' => $audience,
    ]);

    $hash = hash('sha256', $system . "\n" . $user);
    $definition = new PromptDefinition('event_studio.generate_event_copy', 'v4-batch', $system, $user, $hash);

    $scopeId = 'event_studio:generate_copy_batch:' . substr($hash, 0, 20);

    $config = $this->configFactory->get('myeventlane_ai.settings');
    $baseMax = (int) ($config->get('openai.max_tokens') ?? 600);
    $baseMax = max(200, min($baseMax, 600));
    $maxTokens = max(800, min(2000, 3 * $baseMax));

    $batchTimeout = (int) max($timeoutSeconds, 20);

    $result = $this->analyze(
      $definition,
      [
        'model' => (string) ($config->get('openai.model') ?: 'gpt-4o-mini'),
        'temperature' => (float) ($config->get('openai.temperature') ?? 0.35),
        'max_tokens' => $maxTokens,
        'timeout_seconds' => $batchTimeout,
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
      $this->logger->notice('Event Studio AI: batch response was not valid JSON.');
      throw new \RuntimeException('AI response was not valid JSON');
    }

    $variantsRaw = [];
    if (isset($decoded['variants']) && is_array($decoded['variants'])) {
      foreach ($decoded['variants'] as $item) {
        if (!is_array($item)) {
          continue;
        }
        $variantsRaw[] = [
          'title' => trim((string) ($item['title'] ?? '')),
          'summary' => trim((string) ($item['summary'] ?? '')),
        ];
      }
    }
    elseif (isset($decoded['title']) || isset($decoded['summary'])) {
      $variantsRaw[] = [
        'title' => trim((string) ($decoded['title'] ?? '')),
        'summary' => trim((string) ($decoded['summary'] ?? '')),
      ];
    }

    $variantsRaw = array_slice($variantsRaw, 0, 3);

    $pairs = [];
    foreach ($variantsRaw as $item) {
      $pairs[] = $this->finalizeEventCopyPair($category, $audience, $item['title'], $item['summary']);
    }

    if ($pairs === []) {
      throw new \RuntimeException('AI response contained no variants');
    }

    return $pairs;
  }

  /**
   * @param array<string, mixed> $context
   *   Event copy payload (title, summary, category, tags, tone, audience). Tags may be string or list of strings.
   *
   * @return array{title: string, summary: string}
   */
  private function performEventCopyGeneration(array $context, ?int $requested_by_uid, ?int $vendor_id, int $timeoutSeconds, int $variantOrdinal = 1): array {
    $payload = $context;

    $category = $payload['category'] ?? 'Community';

    $rawTags = $payload['tags'] ?? '';
    if (is_array($rawTags)) {
      $tags = implode(', ', array_filter(array_map('trim', $rawTags)));
    }
    else {
      $tags = trim((string) $rawTags);
    }

    $tone = $this->mapToneForEventCopy($payload['tone'] ?? 'community');
    $audience = $this->mapAudienceForEventCopy($payload['audience'] ?? 'general');

    $contextParts = array_filter([
      trim((string) ($payload['title'] ?? '')),
      trim((string) ($payload['summary'] ?? '')),
    ]);
    $contextText = implode('. ', $contextParts);

    $variantSeed = substr(md5($contextText), 0, 6);

    $system = <<<SYS
You generate high-quality event listings for a community event platform.

Rules:
- Output must be concise, clear, and engaging
- No emojis
- No hashtags
- No fluff or repetition
- Match tone and audience exactly
- Always return structured JSON

Fields:
- title: max 80 characters
- summary: 1–2 sentences, max 200 characters
- Optimise titles for click-through and attendance
- Prefer clear, specific, benefit-driven language
- Avoid vague wording like "amazing" or "great"
- Use concrete hooks (who, what, when, why)
- Keep titles under 80 characters
SYS;

    $user = <<<USR
Create an event listing using:

Category: {$category}
Tags: {$tags}
Tone: {$tone}
Audience: {$audience}

Context:
{$contextText}

Return JSON:
{
  "title": "...",
  "summary": "..."
}
USR;

    $user .= "\nVariant seed: {$variantSeed}";
    $user .= "\nDistinct variant number: {$variantOrdinal}";

    \Drupal::logger('myeventlane_ai')->notice('AI input category=@c tone=@t audience=@a', [
      '@c' => (string) $category,
      '@t' => $tone,
      '@a' => $audience,
    ]);

    $hash = hash('sha256', $system . "\n" . $user);
    $definition = new PromptDefinition('event_studio.generate_event_copy', 'v3', $system, $user, $hash);

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
        'timeout_seconds' => $timeoutSeconds,
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

    $data = $decoded;
    $category = (string) $category;

    return $this->finalizeEventCopyPair(
      $category,
      $audience,
      trim((string) ($data['title'] ?? '')),
      trim((string) ($data['summary'] ?? ''))
    );
  }

  /**
   * Deterministic fallback so UX never breaks.
   *
   * @param array<string, mixed> $payload
   *
   * @return array{ok: bool, title: string, summary: string, error: string}
   */
  private function mockResponse(array $payload): array {
    $category = (string) ($payload['category'] ?? 'Event');
    if ($category === '') {
      $category = 'Event';
    }

    // When OpenAI is unavailable, do not overwrite vendor drafts with canned text.
    $draftTitle = trim((string) ($payload['title'] ?? ''));
    $draftSummary = trim((string) ($payload['summary'] ?? ''));

    if ($draftTitle !== '') {
      $title = mb_substr($draftTitle, 0, 80);
    }
    else {
      $title = $category . ' Event — Join Us';
    }

    if ($draftSummary !== '') {
      $summary = mb_substr($draftSummary, 0, 200);
    }
    else {
      $summary = 'An engaging event designed for your audience. Stay tuned for more details.';
    }

    return [
      'ok' => TRUE,
      'title' => $title,
      'summary' => $summary,
      'error' => '',
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
