<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\node\NodeInterface;

/**
 * Builds the shared public metadata facts for an event.
 */
interface EventMetadataBuilderInterface {

  /**
   * Builds metadata from canonical event fields and public booking rules.
   *
   * @return array<string, mixed>
   *   Normalised event metadata for page tags, previews, and JSON-LD.
   */
  public function build(NodeInterface $event, ?string $canonicalUrl = NULL): array;

}
