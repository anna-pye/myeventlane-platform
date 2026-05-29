<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\node\NodeInterface;

/**
 * Derives default `mel` values for workspace wizard forms (node-backed, no monolith).
 */
final class EventStudioWizardMelBaseline {

  /**
   * Per-request cache: building mel defaults is expensive.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $baselineMelByNodeRevision = [];

  public function __construct(
    private readonly EventStudioMelDefaultsProvider $melDefaultsProvider,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function getBaselineMel(NodeInterface $node): array {
    $cacheKey = (string) $node->id() . ':' . (string) $node->getRevisionId();
    if (array_key_exists($cacheKey, $this->baselineMelByNodeRevision)) {
      return $this->baselineMelByNodeRevision[$cacheKey];
    }

    $normalized = $this->melDefaultsProvider->buildNormalizedBaselineMel($node);
    $this->baselineMelByNodeRevision[$cacheKey] = $normalized;
    return $normalized;
  }

}
