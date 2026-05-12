<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Support;

use Drupal\node\NodeInterface;

/**
 * Resolves optional MEL platform support presentation for Event Studio.
 */
interface MelSupportResolverInterface {

  /**
   * Builds a render array for a contextual platform support card.
   *
   * Returns NULL when platform support is disabled or inaccessible for the
   * current account. This resolver owns the URL/copy decision so Twig and
   * shell JavaScript never embed donation routing or payment logic.
   *
   * @return array<string, mixed>|null
   */
  public function buildCard(NodeInterface $event, string $placement): ?array;

}
