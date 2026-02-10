<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Value;

/**
 * Lightweight result wrapper for AI calls.
 */
final class AiResult {

  public function __construct(
    public readonly bool $ok,
    public readonly string $raw,
    public readonly ?array $json = NULL,
    public readonly ?string $error = NULL,
    public readonly ?string $provider = NULL,
    public readonly ?string $model = NULL,
  ) {}

  /**
   * Creates a successful result.
   */
  public static function ok(string $raw, ?array $json = NULL, ?string $provider = NULL, ?string $model = NULL): self {
    return new self(TRUE, $raw, $json, NULL, $provider, $model);
  }

  /**
   * Creates an error result.
   */
  public static function error(string $message, string $raw = '', ?string $provider = NULL, ?string $model = NULL): self {
    return new self(FALSE, $raw, NULL, $message, $provider, $model);
  }

}
