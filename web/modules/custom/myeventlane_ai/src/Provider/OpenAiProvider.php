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
          'messages' => [
            ['role' => 'user', 'content' => $prompt],
          ],
        ],
      ]);

      $body = (string) $response->getBody();
      $decoded = json_decode($body, TRUE);

      $content = '';
      if (is_array($decoded) && isset($decoded['choices'][0]['message']['content'])) {
        $content = (string) $decoded['choices'][0]['message']['content'];
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

      return AiResult::ok($content !== '' ? $content : $body, $json, $this->getName(), $model);
    }
    catch (\Throwable $e) {
      $this->logger->error('OpenAI provider error: {msg}', ['msg' => $e->getMessage()]);
      return AiResult::error('Provider request failed: ' . $e->getMessage(), '', $this->getName(), $model);
    }
  }

}
