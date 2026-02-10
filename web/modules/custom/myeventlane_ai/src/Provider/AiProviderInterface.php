<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Provider;

use Drupal\myeventlane_ai\Value\AiResult;

/**
 * Provider interface for AI inference calls.
 */
interface AiProviderInterface {

  /**
   * Returns provider machine name.
   */
  public function getName(): string;

  /**
   * Executes an AI request for a prompt.
   *
   * @param string $prompt
   *   Fully rendered prompt text.
   * @param array $options
   *   Provider-specific options (model, temperature, etc).
   *
   * @return \Drupal\myeventlane_ai\Value\AiResult
   *   Result wrapper containing raw and parsed output.
   */
  public function analyze(string $prompt, array $options = []): AiResult;

}
