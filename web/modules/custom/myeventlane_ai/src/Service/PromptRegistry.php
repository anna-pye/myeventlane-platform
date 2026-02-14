<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\myeventlane_ai\Value\PromptDefinition;
use Psr\Log\LoggerInterface;

/**
 * Loads and renders prompts from config. No prompts in database.
 */
final class PromptRegistry {

  /**
   * Maps prompt key to config name suffix (key with dots -> underscores + _vN).
   */
  private const KEY_TO_CONFIG = [
    'vendor_ai.answer' => 'vendor_ai_answer_v1',
    'help_centre.answer' => 'help_centre_answer_v1',
    'escalation.draft' => 'escalation_draft_v1',
    'escalation.triage' => 'escalation_triage_v1',
    'escalation.reply_suggestion' => 'escalation_reply_suggestion_v1',
    'escalation.risk_flag' => 'escalation_risk_flag_v1',
    'escalation.breach_soon' => 'escalation_breach_soon_v1',
    'playbook.summary' => 'playbook_summary_v1',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Renders a prompt with placeholders and returns a PromptDefinition.
   *
   * @param string $key
   *   Prompt key (e.g. vendor_ai.answer).
   * @param array<string, string> $placeholders
   *   Map of placeholder name => value (e.g. ['question' => '...', 'context' => '...']).
   * @param string|null $version
   *   Version (e.g. v1). If NULL, defaults to v1.
   *
   * @return \Drupal\myeventlane_ai\Value\PromptDefinition|null
   *   The definition, or NULL if config missing/invalid.
   */
  public function render(string $key, array $placeholders, ?string $version = NULL): ?PromptDefinition {
    $version = $version ?? 'v1';
    $config_name = self::KEY_TO_CONFIG[$key] ?? $this->keyToConfigName($key, $version);
    if ($config_name === NULL) {
      $this->logger->error('PromptRegistry: unknown key {key}', ['key' => $key]);
      return NULL;
    }

    $config = $this->configFactory->get('myeventlane_ai.prompt.' . $config_name);
    $cfg_key = (string) $config->get('key');
    $cfg_version = (string) $config->get('version');
    $system = (string) $config->get('system');
    $template = (string) $config->get('template');
    $required = (array) $config->get('placeholders');

    if ($cfg_key === '' || $system === '' || $template === '') {
      $this->logger->error('PromptRegistry: invalid config for {name}', ['name' => $config_name]);
      return NULL;
    }

    if ($config->get('status') !== 'active') {
      $this->logger->warning('PromptRegistry: prompt {key} not active', ['key' => $key]);
      return NULL;
    }

    foreach ($required as $p) {
      $p = (string) $p;
      if (!isset($placeholders[$p])) {
        $placeholders[$p] = '';
      }
    }

    $user_message = $this->replacePlaceholders($template, $placeholders);
    $system_message = $this->replacePlaceholders($system, $placeholders);

    $prompt_hash = substr(hash('sha256', $key . '|' . $cfg_version . '|' . $system_message . '|' . $user_message), 0, 64);

    return new PromptDefinition(
      $cfg_key,
      $cfg_version,
      $system_message,
      $user_message,
      $prompt_hash,
    );
  }

  /**
   * Replaces {{name}} placeholders in text.
   *
   * @param string $text
   *   Text with {{placeholder}} tokens.
   * @param array<string, string> $placeholders
   *   Map of name => value.
   */
  private function replacePlaceholders(string $text, array $placeholders): string {
    foreach ($placeholders as $name => $value) {
      $text = str_replace('{{' . $name . '}}', (string) $value, $text);
    }
    return $text;
  }

  private function keyToConfigName(string $key, string $version): ?string {
    $suffix = str_replace('.', '_', $key) . '_' . $version;
    return $suffix ?: NULL;
  }

}
