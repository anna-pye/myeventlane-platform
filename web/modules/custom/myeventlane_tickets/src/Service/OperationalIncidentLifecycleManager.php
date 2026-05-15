<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Uuid\UuidInterface;
use Drupal\myeventlane_tickets\Entity\OperationalIncident;

/**
 * Coordinates lifecycle transitions on operational incident entities only.
 *
 * Does not mutate tickets, orders, redemption logs, or continuity authority.
 */
final class OperationalIncidentLifecycleManager {

  /**
   * @var list<string>
   */
  private const SENSITIVE_SNAPSHOT_KEYS = [
    'replay_token',
    'hmac',
    'hmac_material',
    'device_fingerprint',
    'reconciliation_fingerprint',
    'continuity_descriptor_token',
    'operation_fingerprint',
    'replay_continuity_metadata',
    'deterministic_continuity_descriptor',
    'payload_sha256',
    'venue_operation_integrity',
    'qr_payload',
    'raw_qr',
    'guest_email',
    'holder_email',
    'purchaser_email',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OperationalIncidentWorkflowNormalizer $workflowNormalizer,
    private readonly OperationalIncidentNormalizer $incidentNormalizer,
    private readonly OperationalIncidentAuditBuilder $auditBuilder,
    private readonly TimeInterface $time,
    private readonly LoggerChannelInterface $logger,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * Creates a new coordination row in the detected lifecycle.
   *
   * @param array<string, mixed> $values
   *   Keys: incident_id?, incident_type, severity?, event_id?, order_id?, ticket_id?,
   *   diagnostic_snapshot (array|string)?, operational_descriptors (list<string>|string)?,
   *   resolution_notes? (unused on create).
   */
  public function register(array $values, AccountInterface $account): OperationalIncident {
    $storage = $this->entityTypeManager->getStorage('mel_operational_incident');
    $incident_id = isset($values['incident_id']) ? trim((string) $values['incident_id']) : '';
    if ($incident_id === '') {
      $incident_id = $this->uuid->generate();
    }

    $type = $this->incidentNormalizer->normalizeIncidentType((string) ($values['incident_type'] ?? 'unknown'));
    $severity = $this->incidentNormalizer->normalizeSeverity((string) ($values['severity'] ?? 'info'));

    $snapshot = $this->normalizeDiagnosticInput($values['diagnostic_snapshot'] ?? NULL);
    $descriptors = $this->normalizeDescriptorsInput($values['operational_descriptors'] ?? NULL);

    /** @var \Drupal\myeventlane_tickets\Entity\OperationalIncident $entity */
    $entity = $storage->create([
      'incident_id' => $incident_id,
      'incident_type' => $type,
      'severity' => $severity,
      'lifecycle_state' => OperationalIncidentWorkflowNormalizer::LIFECYCLE_DETECTED,
      'acknowledgement_state' => OperationalIncidentWorkflowNormalizer::ACK_UNASSIGNED,
      'escalation_state' => OperationalIncidentWorkflowNormalizer::ESCALATION_NONE,
      'event_id' => $this->optionalInt($values['event_id'] ?? NULL),
      'order_id' => $this->optionalInt($values['order_id'] ?? NULL),
      'ticket_id' => $this->optionalInt($values['ticket_id'] ?? NULL),
      'diagnostic_snapshot' => $snapshot,
      'operational_descriptors' => $descriptors,
    ]);
    $entity->save();

    $this->logger->info('Operational incident coordination registered: @audit', [
      '@audit' => json_encode($this->auditBuilder->buildTransitionAudit($entity, 'register', (int) $account->id()), JSON_THROW_ON_ERROR),
    ]);

    return $entity;
  }

  public function transitionLifecycle(
    OperationalIncident $entity,
    string $target_lifecycle,
    AccountInterface $account,
    ?string $resolution_notes = NULL,
  ): void {
    $from = (string) $entity->get('lifecycle_state')->value;
    $to = $this->workflowNormalizer->normalizeLifecycleState($target_lifecycle);
    if (!$this->workflowNormalizer->isAllowedLifecycleTransition($from, $to)) {
      $this->logger->error('Blocked disallowed operational lifecycle transition @from -> @to for entity @id.', [
        '@from' => $from,
        '@to' => $to,
        '@id' => (string) $entity->id(),
      ]);
      throw new \InvalidArgumentException('Disallowed lifecycle transition.');
    }
    $entity->set('lifecycle_state', $to);
    if ($to === OperationalIncidentWorkflowNormalizer::LIFECYCLE_RESOLVED) {
      $entity->set('resolved_at', $this->time->getRequestTime());
      if ($resolution_notes !== NULL && $resolution_notes !== '') {
        $entity->set('resolution_notes', $resolution_notes);
      }
    }
    $entity->save();
    $this->logTransition($entity, 'transition_lifecycle', $account, ['target_lifecycle' => $to]);
  }

  public function setEscalationState(
    OperationalIncident $entity,
    string $escalation,
    AccountInterface $account,
  ): void {
    $normalized = $this->workflowNormalizer->normalizeEscalationState($escalation);
    $entity->set('escalation_state', $normalized);
    $entity->save();
    $this->logTransition($entity, 'set_escalation', $account, ['escalation_state' => $normalized]);
  }

  public function setAcknowledgementState(
    OperationalIncident $entity,
    string $ack,
    AccountInterface $account,
  ): void {
    $normalized = $this->workflowNormalizer->normalizeAcknowledgementState($ack);
    $entity->set('acknowledgement_state', $normalized);
    $entity->save();
    $this->logTransition($entity, 'set_acknowledgement', $account, ['acknowledgement_state' => $normalized]);
  }

  public function assignOwner(OperationalIncident $entity, ?int $uid, AccountInterface $account): void {
    if ($uid !== NULL && $uid < 1) {
      throw new \InvalidArgumentException('Assigned uid must be positive or NULL.');
    }
    if ($uid === NULL) {
      $entity->set('assigned_uid', NULL);
      $entity->set('acknowledgement_state', OperationalIncidentWorkflowNormalizer::ACK_UNASSIGNED);
    }
    else {
      $entity->set('assigned_uid', $uid);
      if ((string) $entity->get('acknowledgement_state')->value === OperationalIncidentWorkflowNormalizer::ACK_UNASSIGNED) {
        $entity->set('acknowledgement_state', OperationalIncidentWorkflowNormalizer::ACK_ASSIGNED);
      }
    }
    $entity->save();
    $this->logTransition($entity, 'assign_owner', $account, ['assigned_uid' => $uid]);
  }

  public function appendResolutionNotes(OperationalIncident $entity, string $notes, AccountInterface $account): void {
    $existing = (string) $entity->get('resolution_notes')->value;
    $append = trim($notes);
    if ($append === '') {
      return;
    }
    $merged = $existing === '' ? $append : $existing . "\n" . $append;
    $entity->set('resolution_notes', $merged);
    $entity->save();
    $this->logTransition($entity, 'append_resolution_notes', $account, []);
  }

  /**
   * @param array<string, mixed>|string|null $input
   */
  private function normalizeDiagnosticInput(mixed $input): ?string {
    if ($input === NULL || $input === '') {
      return NULL;
    }
    if (is_string($input)) {
      $decoded = json_decode($input, TRUE);
      if (!is_array($decoded)) {
        return json_encode([], JSON_THROW_ON_ERROR);
      }
      /** @var array<string, mixed> $decoded */
      $input = $decoded;
    }
    if (!is_array($input)) {
      return json_encode([], JSON_THROW_ON_ERROR);
    }
    $clean = $this->stripSensitiveRecursive($input);
    return json_encode($clean, JSON_THROW_ON_ERROR);
  }

  /**
   * @param list<string>|string|null $input
   */
  private function normalizeDescriptorsInput(mixed $input): ?string {
    if ($input === NULL || $input === '') {
      return NULL;
    }
    if (is_string($input)) {
      $decoded = json_decode($input, TRUE);
      $tokens = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $input) ?: [];
    }
    else {
      $tokens = $input;
    }
    $out = [];
    foreach ($tokens as $t) {
      $tok = $this->incidentNormalizer->normalizeIncidentType((string) $t);
      if ($tok !== '') {
        $out[] = $tok;
      }
    }
    return json_encode(array_values(array_unique($out)), JSON_THROW_ON_ERROR);
  }

  private function optionalInt(mixed $v): ?int {
    if ($v === NULL || $v === '') {
      return NULL;
    }
    $i = (int) $v;
    return $i > 0 ? $i : NULL;
  }

  /**
   * @param array<string, mixed> $value
   *
   * @return array<string, mixed>
   */
  private function stripSensitiveRecursive(array $value): array {
    $out = [];
    foreach ($value as $k => $v) {
      $key = (string) $k;
      if ($this->isSensitiveKey($key)) {
        continue;
      }
      if (is_array($v)) {
        /** @var array<string, mixed> $v */
        $out[$k] = $this->stripSensitiveRecursive($v);
      }
      elseif (is_scalar($v) || $v === NULL) {
        $out[$k] = $v;
      }
    }
    return $out;
  }

  private function isSensitiveKey(string $key): bool {
    foreach (self::SENSITIVE_SNAPSHOT_KEYS as $blocked) {
      if ($key === $blocked) {
        return TRUE;
      }
    }
    $lower = strtolower($key);
    if (str_contains($lower, 'fingerprint')) {
      return TRUE;
    }
    if (str_contains($lower, 'replay_token')) {
      return TRUE;
    }
    if (str_contains($lower, 'email')) {
      return TRUE;
    }
    return FALSE;
  }

  private function logTransition(
    OperationalIncident $entity,
    string $operation,
    AccountInterface $account,
    array $extra,
  ): void {
    $audit = $this->auditBuilder->buildTransitionAudit($entity, $operation, (int) $account->id(), $extra);
    $this->logger->info('Operational incident coordination: @audit', [
      '@audit' => json_encode($audit, JSON_THROW_ON_ERROR),
    ]);
  }

}
