<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Value;

/**
 * Value object for a rendered prompt definition.
 *
 * Does NOT expose full prompt string for logging. Only key, version, hash,
 * and the structured messages needed for provider calls.
 */
final class PromptDefinition {

  public function __construct(
    private readonly string $key,
    private readonly string $version,
    private readonly string $systemMessage,
    private readonly string $userMessage,
    private readonly string $promptHash,
  ) {}

  public function getKey(): string {
    return $this->key;
  }

  public function getVersion(): string {
    return $this->version;
  }

  public function getSystemMessage(): string {
    return $this->systemMessage;
  }

  public function getUserMessage(): string {
    return $this->userMessage;
  }

  public function getPromptHash(): string {
    return $this->promptHash;
  }

}
