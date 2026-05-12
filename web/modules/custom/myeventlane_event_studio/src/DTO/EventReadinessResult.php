<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\DTO;

/**
 * Publish readiness result for Event Studio.
 */
final class EventReadinessResult {

  /**
   * @param list<string> $errors
   * @param list<string> $warnings
   * @param list<string> $completed
   * @param list<string> $recommendations
   */
  public function __construct(
    public readonly bool $ready,
    public readonly array $errors,
    public readonly array $warnings,
    public readonly array $completed = [],
    public readonly array $recommendations = [],
  ) {}

  /**
   * @param list<string> $errors
   * @param list<string> $warnings
   * @param list<string> $completed
   * @param list<string> $recommendations
   */
  public static function create(array $errors = [], array $warnings = [], array $completed = [], array $recommendations = []): self {
    return new self($errors === [], $errors, $warnings, $completed, $recommendations);
  }

}
