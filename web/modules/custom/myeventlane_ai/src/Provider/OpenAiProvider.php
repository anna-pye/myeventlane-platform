<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Provider;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\myeventlane_ai\Value\AiResult;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal OpenAI-compatible Chat Completions provider.
 *
 * NOTE: Secrets are read from settings.php ONLY:
 * $settings['myeventlane_ai']['api_key'] = '...';
 */
final class OpenAiProvider implements AiProviderInterface {

  /**
   * Estimated cost per 1K tokens (USD). Used for minimal observability only.
   */
  private const MODEL_COSTS = [
    'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
    'gpt-4o' => ['input' => 0.0025, 'output' => 0.01],
    'gpt-4' => ['input' => 0.03, 'output' => 0.06],
    'gpt-3.5-turbo' => ['input' => 0.0005, 'output' => 0.0015],
    'default' => ['input' => 0.0002, 'output' => 0.0008],
  ];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'openai';
  }

  /**
   * {@inheritdoc}
   */
  public function analyze(string $prompt, array $options = []): AiResult {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $endpoint = (string) ($options['endpoint'] ?? $config->get('openai.endpoint'));
    $model = (string) ($options['model'] ?? $config->get('openai.model'));
    $timeout = (int) ($options['timeout_seconds'] ?? $config->get('openai.timeout_seconds') ?? 20);
    $max_tokens = (int) ($options['max_tokens'] ?? $config->get('openai.max_tokens') ?? 600);
    $temperature = (float) ($options['temperature'] ?? $config->get('openai.temperature') ?? 0.2);

    $api_key = (string) (Settings::get('myeventlane_ai')['api_key'] ?? '');
    if ($api_key === '') {
      return AiResult::error('Missing myeventlane_ai.api_key in settings.php', '', $this->getName(), $model);
    }

    $messages = $options['_messages'] ?? NULL;
    if (!is_array($messages) || empty($messages)) {
      $messages = [['role' => 'user', 'content' => $prompt]];
    }

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'timeout' => $timeout,
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => $model,
          'temperature' => $temperature,
          'max_tokens' => $max_tokens,
          'messages' => $messages,
        ],
      ]);

      $body = (string) $response->getBody();
      $decoded = json_decode($body, TRUE);

      $content = '';
      if (is_array($decoded) && isset($decoded['choices'][0]['message']['content'])) {
        $content = (string) $decoded['choices'][0]['message']['content'];
      }

      $token_counts = NULL;
      $estimated_cost_usd = NULL;

      if (is_array($decoded) && isset($decoded['usage'])) {
        $usage = $decoded['usage'];
        $prompt_tokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completion_tokens = (int) ($usage['completion_tokens'] ?? 0);
        $total_tokens = (int) ($usage['total_tokens'] ?? $prompt_tokens + $completion_tokens);

        if ($total_tokens > 0) {
          $token_counts = [
            'prompt_tokens' => $prompt_tokens,
            'completion_tokens' => $completion_tokens,
            'total_tokens' => $total_tokens,
          ];
          $costs = self::MODEL_COSTS[$model] ?? self::MODEL_COSTS['default'];
          $estimated_cost_usd = ($prompt_tokens / 1000 * $costs['input']) + ($completion_tokens / 1000 * $costs['output']);
          $this->logUsage($token_counts, $estimated_cost_usd, $model, $options);
        }
      }

      // Attempt JSON parse if the response looks like JSON.
      $json = NULL;
      $trimmed = trim($content);
      if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $json = json_decode($trimmed, TRUE);
        if (!is_array($json)) {
          $json = NULL;
        }
      }

      return AiResult::ok($content !== '' ? $content : $body, $json, $this->getName(), $model, $token_counts, $estimated_cost_usd);
    }
    catch (\Throwable $e) {
      $this->logger->error('OpenAI provider error: {msg}', ['msg' => $e->getMessage()]);
      return AiResult::error('Provider request failed: ' . $e->getMessage(), '', $this->getName(), $model);
    }
  }

  /**
   * Logs token usage and estimated cost at NOTICE level.
   *
   * Does not log prompt content or AI output.
   */
  private function logUsage(array $token_counts, float $estimated_cost_usd, string $model, array $options): void {
    $context = [
      'model' => $model,
      'prompt_tokens' => $token_counts['prompt_tokens'],
      'completion_tokens' => $token_counts['completion_tokens'],
      'total_tokens' => $token_counts['total_tokens'],
      'estimated_cost_usd' => round($estimated_cost_usd, 6),
    ];
    if (isset($options['_log_uid'])) {
      $context['user_id'] = $options['_log_uid'];
    }
    if (isset($options['_log_scope'])) {
      $context['scope'] = $options['_log_scope'];
    }

    $this->logger->notice('AI usage: model={model} prompt_tokens={prompt_tokens} completion_tokens={completion_tokens} total_tokens={total_tokens} estimated_cost_usd={estimated_cost_usd}', $context);
  }

}
