<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Utility;

use Drupal\node\NodeInterface;

/**
 * Ensures revisionable event nodes create a new revision on programmatic save.
 *
 * Saving a node that references paragraphs (entity_reference_revisions) without
 * creating a new host revision can trigger:
 * "An existing default revision of the 'paragraph' entity type can not be
 * changed to a non-default revision" (ContentEntityStorageBase::doPreSave).
 */
final class EventNodeRevisionSave {

  /**
   * Flags the node to save as a new revision before save().
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event (or other) node about to be saved.
   * @param string $log_message
   *   Optional revision log (only set when revision_log field exists).
   */
  public static function prepare(NodeInterface $node, string $log_message = ''): void {
    if ($node->isNew()) {
      return;
    }
    if (!$node->getEntityType()->isRevisionable()) {
      return;
    }
    $node->setNewRevision(TRUE);
    if ($log_message !== '' && $node->hasField('revision_log')) {
      $node->set('revision_log', $log_message);
    }
  }

}
