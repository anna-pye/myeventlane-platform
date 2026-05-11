<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Readiness;

/**
 * Value object returned by future readiness providers.
 */
final class EventReadinessProviderResult {

  /**
   * @param list<string> $errors
   * @param list<string> $warnings
   * @param list<string> $completed
   * @param list<\Drupal\myeventlane_event_studio\Readiness\ReadinessIssue> $issues
   */
  public function __construct(
    public readonly string $providerId,
    public readonly string $domain,
    public readonly array $errors = [],
    public readonly array $warnings = [],
    public readonly array $completed = [],
    public readonly ?string $sectionId = NULL,
    public readonly array $issues = [],
  ) {}

  /**
   * Creates a provider result from readiness item lists.
   *
   * @param list<string> $errors
   * @param list<string> $warnings
   * @param list<string> $completed
   */
  public static function create(string $provider_id, string $domain, array $errors = [], array $warnings = [], array $completed = [], ?string $section_id = NULL): self {
    return new self(
      $provider_id,
      $domain,
      array_values(array_unique($errors)),
      array_values(array_unique($warnings)),
      array_values(array_unique($completed)),
      $section_id,
    );
  }

  /**
   * Creates a provider result from typed readiness issues.
   *
   * @param list<\Drupal\myeventlane_event_studio\Readiness\ReadinessIssue> $issues
   */
  public static function fromIssues(string $provider_id, string $domain, array $issues, ?string $section_id = NULL): self {
    $errors = [];
    $warnings = [];
    $completed = [];
    $typed_issues = [];

    foreach ($issues as $issue) {
      if (!$issue instanceof ReadinessIssue) {
        continue;
      }
      $typed_issues[] = $issue;
      switch ($issue->severity) {
        case ReadinessSeverity::Error:
          $errors[] = $issue->message;
          break;

        case ReadinessSeverity::Warning:
          $warnings[] = $issue->message;
          break;

        case ReadinessSeverity::Completed:
          $completed[] = $issue->message;
          break;
      }
    }

    return new self(
      $provider_id,
      $domain,
      array_values(array_unique($errors)),
      array_values(array_unique($warnings)),
      array_values(array_unique($completed)),
      $section_id,
      $typed_issues,
    );
  }

  /**
   * Returns TRUE when the provider has no blocking errors.
   */
  public function isReady(): bool {
    return $this->errors === [];
  }

}
