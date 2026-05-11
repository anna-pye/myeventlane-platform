<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Readiness;

/**
 * Describes one future readiness signal without changing current orchestration.
 */
final class ReadinessIssue {

  public function __construct(
    public readonly ReadinessSeverity $severity,
    public readonly string $message,
    public readonly string $code = '',
    public readonly ?string $sectionId = NULL,
  ) {}

  /**
   * Creates a blocking readiness issue.
   */
  public static function error(string $message, string $code = '', ?string $section_id = NULL): self {
    return new self(ReadinessSeverity::Error, $message, $code, $section_id);
  }

  /**
   * Creates a non-blocking readiness warning.
   */
  public static function warning(string $message, string $code = '', ?string $section_id = NULL): self {
    return new self(ReadinessSeverity::Warning, $message, $code, $section_id);
  }

  /**
   * Creates a completed readiness signal.
   */
  public static function completed(string $message, string $code = '', ?string $section_id = NULL): self {
    return new self(ReadinessSeverity::Completed, $message, $code, $section_id);
  }

  /**
   * Returns TRUE when this issue blocks publishing.
   */
  public function isBlocking(): bool {
    return $this->severity->isBlocking();
  }

}
