<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Readiness;

/**
 * Base contract for future domain-owned readiness providers.
 *
 * Providers are not wired into EventReadinessService in this phase. This base
 * class only standardizes metadata and result construction for future domains.
 */
abstract class AbstractEventReadinessProvider implements EventReadinessProviderInterface {

  public function __construct(
    private readonly string $providerId,
    private readonly string $domain,
    private readonly ?string $sectionId = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return $this->providerId;
  }

  /**
   * {@inheritdoc}
   */
  public function domain(): string {
    return $this->domain;
  }

  /**
   * {@inheritdoc}
   */
  public function sectionId(): ?string {
    return $this->sectionId;
  }

  /**
   * Creates a provider result from typed readiness issues.
   *
   * @param list<\Drupal\myeventlane_event_studio\Readiness\ReadinessIssue> $issues
   *   Typed readiness issues owned by this provider.
   */
  protected function resultFromIssues(array $issues): EventReadinessProviderResult {
    return EventReadinessProviderResult::fromIssues(
      $this->id(),
      $this->domain(),
      $issues,
      $this->sectionId(),
    );
  }

}
