<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Entity\OperationalIncident;
use Drupal\node\NodeInterface;

/**
 * Builds Twig-safe workspace projections for stored operational incidents.
 */
final class OperationalIncidentProjectionBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalIncidentWorkflowNormalizer $workflowNormalizer,
    private readonly OperationalIncidentNormalizer $incidentNormalizer,
  ) {}

  /**
   * Builds a workspace section for coordination rows.
   *
   * @return array<string, mixed>
   */
  public function buildWorkspaceSection(
    ?NodeInterface $event,
    ?string $lifecycle_filter,
    AccountInterface $account,
  ): array {
    $storage = $this->entityTypeManager->getStorage('mel_operational_incident');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('changed', 'DESC')
      ->range(0, 50);

    if ($event) {
      $query->condition('event_id', (int) $event->id());
    }

    if ($lifecycle_filter !== NULL && $lifecycle_filter !== '') {
      $normalized = $this->workflowNormalizer->normalizeLifecycleState($lifecycle_filter);
      $query->condition('lifecycle_state', $normalized);
    }

    $ids = $query->execute();
    if (!$ids) {
      return [
        'id' => 'operational_coordination_incidents',
        'title' => 'Operational coordination incidents',
        'cards' => [
          [
            'label' => 'Stored coordination rows',
            'value' => '0',
          ],
        ],
        'rows' => [],
        'links' => $this->buildToolbarLinks($account),
      ];
    }

    $entities = $storage->loadMultiple($ids);
    $rows = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof OperationalIncident) {
        continue;
      }
      $access = $entity->access('view', $account, TRUE);
      if (!$access->isAllowed()) {
        continue;
      }
      $rows[] = $this->buildRowProjection($entity, $account);
    }

    $cards = [
      [
        'label' => 'Stored coordination rows (sample)',
        'value' => (string) count($rows),
      ],
    ];

    return [
      'id' => 'operational_coordination_incidents',
      'title' => 'Operational coordination incidents',
      'cards' => $cards,
      'rows' => $rows,
      'links' => $this->buildToolbarLinks($account),
    ];
  }

  /**
   * @return list<array{title: string, href: string}>
   */
  private function buildToolbarLinks(AccountInterface $account): array {
    if (!$account->hasPermission('manage mel operational incidents')) {
      return [];
    }
    return [
      [
        'title' => 'Register coordination incident',
        'href' => Url::fromRoute('myeventlane_tickets.operational_incident_add')->toString(),
      ],
      [
        'title' => 'Coordination list',
        'href' => Url::fromRoute('entity.mel_operational_incident.collection')->toString(),
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function buildRowProjection(OperationalIncident $entity, AccountInterface $account): array {
    $lifecycle = $this->workflowNormalizer->normalizeLifecycleState((string) $entity->get('lifecycle_state')->value);
    $escalation = $this->workflowNormalizer->normalizeEscalationState((string) $entity->get('escalation_state')->value);
    $ack = $this->workflowNormalizer->normalizeAcknowledgementState((string) $entity->get('acknowledgement_state')->value);
    $severity = $this->incidentNormalizer->normalizeCoordinationSeverity((string) $entity->get('severity')->value);
    $assigned = $entity->get('assigned_uid')->value;
    $assigned_uid = ($assigned === NULL || $assigned === '') ? NULL : (int) $assigned;

    $manage = $account->hasPermission('manage mel operational incidents');
    $manage_url = NULL;
    if ($manage && $entity->access('update', $account, TRUE)->isAllowed()) {
      $manage_url = Url::fromRoute('myeventlane_tickets.operational_incident_coordination', [
        'mel_operational_incident' => $entity->id(),
      ], ['absolute' => FALSE])->toString();
    }

    $descriptor_tokens = $this->decodeDescriptors((string) $entity->get('operational_descriptors')->value);
    $suppressed = $this->workflowNormalizer->isSuppressedLifecycle($lifecycle);
    $suppression_reason = trim((string) $entity->get('suppression_reason')->value);
    $workflow_keys = $this->decodeWorkflowMetadataKeys((string) $entity->get('workflow_metadata')->value);

    return [
      'entity_id' => (int) $entity->id(),
      'incident_correlation' => (string) $entity->get('incident_id')->value,
      'incident_type' => (string) $entity->get('incident_type')->value,
      'severity' => $severity,
      'lifecycle_state' => $lifecycle,
      'escalation_state' => $escalation,
      'acknowledgement_state' => $ack,
      'assigned_uid' => $assigned_uid,
      'assigned_display' => $assigned_uid === NULL ? 'unassigned' : 'uid:' . (string) $assigned_uid,
      'event_id' => $this->optionalIntField($entity, 'event_id'),
      'order_id' => $this->optionalIntField($entity, 'order_id'),
      'ticket_id' => $this->optionalIntField($entity, 'ticket_id'),
      'suppressed' => $suppressed,
      'suppression_visible' => $suppressed && $suppression_reason !== '',
      'suppression_reason_excerpt' => $this->truncateTokenSafe($suppression_reason, 160),
      'workflow_metadata_keys' => $workflow_keys,
      'resolved_at' => $entity->get('resolved_at')->value ? (int) $entity->get('resolved_at')->value : NULL,
      'changed' => (int) $entity->getChangedTime(),
      'badges' => [
        'lifecycle' => $lifecycle,
        'escalation' => $escalation,
        'acknowledgement' => $ack,
        'severity' => $severity,
      ],
      'descriptor_tokens' => $descriptor_tokens,
      'manage_url' => $manage_url,
    ];
  }

  private function optionalIntField(OperationalIncident $entity, string $field): ?int {
    $v = $entity->get($field)->value;
    if ($v === NULL || $v === '') {
      return NULL;
    }
    $i = (int) $v;
    return $i > 0 ? $i : NULL;
  }

  /**
   * @return list<string>
   */
  private function decodeDescriptors(string $raw): array {
    if ($raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      return [];
    }
    $out = [];
    foreach ($decoded as $item) {
      if (is_string($item) && $item !== '') {
        $out[] = $item;
      }
    }
    return $out;
  }

  /**
   * @return list<string>
   */
  private function decodeWorkflowMetadataKeys(string $raw): array {
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

  private function truncateTokenSafe(string $value, int $max): string {
    if ($value === '') {
      return '';
    }
    if (strlen($value) <= $max) {
      return $value;
    }
    return substr($value, 0, $max) . '…';
  }

}
