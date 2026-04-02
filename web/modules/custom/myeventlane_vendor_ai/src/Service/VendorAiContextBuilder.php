<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_escalations\Entity\Escalation;
use Drupal\myeventlane_escalations_sla\Service\EscalationSlaBadgeResolver;
use Psr\Log\LoggerInterface;

/**
 * Builds SAFE context for vendor AI prompts.
 *
 * NEVER includes: thread text, notes, customer PII, internal escalation details.
 * ONLY includes: escalation type, priority, generic labels, help article excerpts.
 */
final class VendorAiContextBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EscalationSlaBadgeResolver $badgeResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds safe context for vendor AI assistant.
   *
   * @param int $escalation_id
   *   The escalation entity ID.
   * @param array $helpExcerpts
   *   Help article excerpts from UnifiedHelpRetriever (legacy shape:
   *   nid, title, url, excerpt).
   *
   * @return array
   *   Safe context array for prompt building.
   */
  public function build(int $escalation_id, array $helpExcerpts = []): array {
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $this->entityTypeManager->getStorage('escalation')->load($escalation_id);

    if (!$escalation) {
      $this->logger->warning('Vendor AI context: escalation not found id={id}', ['id' => $escalation_id]);
      return [
        'error' => 'escalation_not_found',
        'escalation_id' => $escalation_id,
      ];
    }

    $typeLabel = $this->getTypeLabel((string) $escalation->get('type')->value);
    $priorityLabel = $this->getPriorityLabel((string) $escalation->get('priority')->value);
    $statusLabel = $this->getStatusLabel((string) $escalation->get('status')->value);
    $waitingLabel = $this->resolveWaitingLabel($escalation);

    $badge = $this->badgeResolver->resolve($escalation);

    return [
      'escalation_id' => (int) $escalation->id(),
      'type' => $typeLabel,
      'priority' => $priorityLabel,
      'status' => $statusLabel,
      'waiting_on' => $waitingLabel,
      'sla_label' => $badge['label'] ?? 'On track',
      'help_excerpts' => $helpExcerpts,
    ];
  }

  private function getTypeLabel(string $value): string {
    $map = [
      'payment' => 'Payment Issue',
      'refund' => 'Refund Request',
      'ticket' => 'Ticket Issue',
      'event' => 'Event Related',
      'vendor' => 'Vendor Dispute',
      'customer' => 'Customer Complaint',
      'technical' => 'Technical Issue',
      'other' => 'Other',
    ];
    return $map[$value] ?? $value;
  }

  private function getPriorityLabel(string $value): string {
    $map = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
    return $map[$value] ?? $value;
  }

  private function getStatusLabel(string $value): string {
    $map = [
      'new' => 'New',
      'in_progress' => 'In Progress',
      'waiting_vendor' => 'Waiting for Vendor',
      'waiting_customer' => 'Waiting for Customer',
      'resolved' => 'Resolved',
      'closed' => 'Closed',
    ];
    return $map[$value] ?? $value;
  }

  private function resolveWaitingLabel(Escalation $escalation): string {
    if (!$escalation->hasField('field_waiting_on') || $escalation->get('field_waiting_on')->isEmpty()) {
      return '-';
    }
    $map = [
      'vendor' => 'Waiting on you',
      'customer' => 'Waiting on customer',
      'staff' => 'Waiting on MyEventLane support',
    ];
    $v = (string) $escalation->get('field_waiting_on')->value;
    return $map[$v] ?? '-';
  }

}
