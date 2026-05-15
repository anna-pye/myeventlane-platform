<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\myeventlane_tickets\Entity\OperationalIncident;

/**
 * Builds structured audit payloads for coordination changes (logging only).
 */
final class OperationalIncidentAuditBuilder {

  /**
   * @param array<string, scalar|null> $extra
   *
   * @return array<string, scalar|null>
   */
  public function buildTransitionAudit(
    OperationalIncident $entity,
    string $operation,
    int $operator_uid,
    array $extra = [],
  ): array {
    $row = [
      'operation' => $operation,
      'operator_uid' => $operator_uid,
      'entity_id' => (int) $entity->id(),
      'incident_id' => (string) $entity->get('incident_id')->value,
      'incident_type' => (string) $entity->get('incident_type')->value,
      'severity' => (string) $entity->get('severity')->value,
      'lifecycle_state' => (string) $entity->get('lifecycle_state')->value,
      'acknowledgement_state' => (string) $entity->get('acknowledgement_state')->value,
      'escalation_state' => (string) $entity->get('escalation_state')->value,
      'assigned_uid' => !$entity->get('assigned_uid')->isEmpty() ? (int) $entity->get('assigned_uid')->value : NULL,
      'event_id' => !$entity->get('event_id')->isEmpty() ? (int) $entity->get('event_id')->value : NULL,
      'order_id' => !$entity->get('order_id')->isEmpty() ? (int) $entity->get('order_id')->value : NULL,
      'ticket_id' => !$entity->get('ticket_id')->isEmpty() ? (int) $entity->get('ticket_id')->value : NULL,
      'suppression_reason_len' => $this->suppressionReasonLength($entity),
      'workflow_metadata_key_count' => $this->workflowMetadataKeyCount($entity),
    ];
    foreach ($extra as $k => $v) {
      if (is_scalar($v) || $v === NULL) {
        $row[(string) $k] = $v;
      }
    }
    return $row;
  }

  /**
   * @return array<string, mixed>
   */
  public function buildCoordinationAuditSummary(OperationalIncident $entity): array {
    $workflow = [
      'lifecycle_state' => (string) $entity->get('lifecycle_state')->value,
      'escalation_state' => (string) $entity->get('escalation_state')->value,
      'acknowledgement_state' => (string) $entity->get('acknowledgement_state')->value,
      'severity' => (string) $entity->get('severity')->value,
    ];
    $assigned = !$entity->get('assigned_uid')->isEmpty() ? (int) $entity->get('assigned_uid')->value : NULL;
    $suppressed = (string) $entity->get('lifecycle_state')->value === 'suppressed';
    return [
      'workflow' => $workflow,
      'ownership' => [
        'assigned_uid' => $assigned,
      ],
      'suppression' => [
        'active' => $suppressed,
        'reason_length' => $this->suppressionReasonLength($entity),
      ],
      'workflow_metadata_keys' => $this->workflowMetadataKeys($entity),
    ];
  }

  private function suppressionReasonLength(OperationalIncident $entity): int {
    $raw = (string) $entity->get('suppression_reason')->value;
    return strlen(trim($raw));
  }

  /**
   * @return list<string>
   */
  private function workflowMetadataKeys(OperationalIncident $entity): array {
    $raw = (string) $entity->get('workflow_metadata')->value;
    if ($raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $keys = array_keys($decoded);
    sort($keys);
    /** @var list<string> $keys */
    return $keys;
  }

  private function workflowMetadataKeyCount(OperationalIncident $entity): int {
    return count($this->workflowMetadataKeys($entity));
  }

}
