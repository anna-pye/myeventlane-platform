<?php

declare(strict_types=1);

namespace Drupal\myeventlane_staff_playbooks\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_escalations\Entity\Escalation;
use Drupal\myeventlane_escalations_refunds\Service\EscalationRefundsResolver;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Matches escalations to relevant staff playbooks.
 *
 * Input: Escalation entity.
 * Output: Array of playbook nodes, ordered by field_priority ASC.
 *
 * Matching logic:
 * - Escalation type match (field_related_escalation_type)
 * - Priority match (field_applicable_priority)
 * - Refund involvement match (field_refund_involved)
 *
 * Fully cacheable. Does not duplicate escalation logic; uses
 * EscalationRefundsResolver for refund involvement determination.
 */
final class PlaybookMatcher {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EscalationRefundsResolver $refundsResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Finds playbook nodes relevant to the given escalation.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Matched playbook nodes, ordered by field_priority ASC.
   */
  public function match(Escalation $escalation): array {
    $escalationType = (string) ($escalation->get('type')->value ?? '');
    $escalationPriority = (string) ($escalation->get('priority')->value ?? 'normal');

    $refundInvolved = $this->hasRefundInvolvement($escalation);

    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'staff_playbook')
      ->condition('status', 1)
      ->sort('field_priority', 'ASC');

    $nids = $query->execute();
    if (empty($nids)) {
      return [];
    }

    $nodes = $this->entityTypeManager
      ->getStorage('node')
      ->loadMultiple($nids);

    $matched = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if ($this->playbookMatches($node, $escalationType, $escalationPriority, $refundInvolved)) {
        $matched[] = $node;
      }
    }

    // Sort by field_priority (query already did, preserve order).
    usort($matched, function (NodeInterface $a, NodeInterface $b): int {
      $pa = $a->hasField('field_priority') && !$a->get('field_priority')->isEmpty()
        ? (int) $a->get('field_priority')->value
        : 0;
      $pb = $b->hasField('field_priority') && !$b->get('field_priority')->isEmpty()
        ? (int) $b->get('field_priority')->value
        : 0;
      return $pa <=> $pb;
    });

    return $matched;
  }

  /**
   * Checks whether the escalation has refund involvement.
   *
   * Uses EscalationRefundsResolver; does not duplicate refund logic.
   */
  private function hasRefundInvolvement(Escalation $escalation): bool {
    $type = (string) ($escalation->get('type')->value ?? '');
    if ($type === 'refund') {
      return TRUE;
    }

    try {
      $resolved = $this->refundsResolver->resolve($escalation);
      return !empty($resolved['logs']) || !empty($resolved['requests']);
    }
    catch (\Throwable $e) {
      $this->logger->error('PlaybookMatcher: could not resolve refund data: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Checks if a playbook matches the escalation context.
   */
  private function playbookMatches(
    NodeInterface $playbook,
    string $escalationType,
    string $escalationPriority,
    bool $refundInvolved,
  ): bool {
    // Escalation type match.
    $relatedTypes = $this->getListValues($playbook, 'field_related_escalation_type');
    if (!empty($relatedTypes) && !in_array($escalationType, $relatedTypes, TRUE)) {
      return FALSE;
    }

    // Priority match.
    $applicablePriorities = $this->getListValues($playbook, 'field_applicable_priority');
    if (!empty($applicablePriorities) && !in_array($escalationPriority, $applicablePriorities, TRUE)) {
      return FALSE;
    }

    // Refund involvement match.
    if ($playbook->hasField('field_refund_involved') && !$playbook->get('field_refund_involved')->isEmpty()) {
      $playbookRequiresRefund = (bool) $playbook->get('field_refund_involved')->value;
      if ($playbookRequiresRefund && !$refundInvolved) {
        return FALSE;
      }
      if (!$playbookRequiresRefund && $refundInvolved) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Extracts list_string values from a field.
   *
   * @return string[]
   */
  private function getListValues(NodeInterface $node, string $fieldName): array {
    if (!$node->hasField($fieldName)) {
      return [];
    }
    $field = $node->get($fieldName);
    $values = [];
    foreach ($field as $item) {
      $v = $item->value;
      if ($v !== NULL && $v !== '') {
        $values[] = (string) $v;
      }
    }
    return $values;
  }

}
