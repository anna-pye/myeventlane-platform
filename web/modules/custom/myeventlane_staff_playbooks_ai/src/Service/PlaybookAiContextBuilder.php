<?php

declare(strict_types=1);

namespace Drupal\myeventlane_staff_playbooks_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds plain-text context from a staff_playbook for AI summarisation.
 *
 * Includes title + body only. Excludes internal notes if present.
 */
final class PlaybookAiContextBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds plain-text context from a staff_playbook node.
   *
   * @param int $node_id
   *   The staff_playbook node ID.
   *
   * @return string
   *   Plain text suitable for the AI prompt.
   */
  public function build(int $node_id): string {
    $storage = $this->entityTypeManager->getStorage('node');
    $node = $storage->load($node_id);

    if (!$node instanceof NodeInterface || $node->bundle() !== 'staff_playbook') {
      return '';
    }

    $title = (string) $node->getTitle();
    $body = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = (string) $node->get('body')->value;
    }

    $body_plain = strip_tags($body);
    $body_plain = preg_replace('/\s+/', ' ', $body_plain);
    $body_plain = trim($body_plain);

    $parts = [];
    if ($title !== '') {
      $parts[] = 'Title: ' . $title;
    }
    if ($body_plain !== '') {
      $parts[] = 'Content: ' . $body_plain;
    }

    return implode("\n\n", $parts);
  }

}
