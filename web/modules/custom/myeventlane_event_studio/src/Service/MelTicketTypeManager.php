<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_domain_events\Service\DomainEventBus;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Event Studio orchestration for mel_ticket_type rows, Commerce variations, and domain signals.
 *
 * Delegates entity lifecycle and paid sync to TicketTierLifecycleService, so
 * Studio and the vendor ticket builder share one ticket persistence path.
 */
final class MelTicketTypeManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
    private readonly ?DomainEventBus $domainEventBus,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Validates optional `studio_ticket_tiers` before the node is persisted.
   *
   * @param array<string, mixed> $payload
   *
   * @return list<string>
   */
  public function validateStudioTicketDefinitions(NodeInterface $event, AccountInterface $account, array $payload, bool $draft): array {
    $tiers = $this->normalizeStudioTiersPayload($payload['studio_ticket_tiers'] ?? NULL);
    return $this->ticketTierLifecycle->validateTicketInputRowsForEvent($event, $account, $tiers, $draft);
  }

  /**
   * After Event Studio persists the node: create/update tiers, merge references, Commerce sync, domain event.
   *
   * @param array<string, mixed> $payload
   */
  public function onEventStudioSaveComplete(NodeInterface $event, AccountInterface $account, array $payload, bool $draft): void {
    if ($draft || $event->id() === NULL || $event->bundle() !== 'event') {
      return;
    }

    // Align node.field_ticket_types before tier payload merge (ticket builder uses inverse event refs).
    $this->ticketTierLifecycle->reconcileEventTicketReferences($event);
    $reloaded = $this->entityTypeManager->getStorage('node')->load($event->id());
    if ($reloaded instanceof NodeInterface) {
      $event = $reloaded;
    }

    $tiers = $this->normalizeStudioTiersPayload($payload['studio_ticket_tiers'] ?? NULL);
    if ($tiers !== []) {
      $this->applyStudioTierRows($event, $account, $tiers);
    }

    $this->syncCommerceAndPublishCatalogSignal($event);
  }

  /**
   * @param list<array<string, mixed>> $tiers
   */
  private function applyStudioTierRows(NodeInterface $event, AccountInterface $account, array $tiers): void {
    foreach ($tiers as $row) {
      $id = (int) ($row['id'] ?? 0);
      if ($id > 0) {
        $ticket = $this->ticketTierLifecycle->loadWritableTicketForEvent($event, $id, $account);
        if (!$ticket instanceof TicketTypeInterface) {
          $this->logger->warning('Studio skipped ticket @id: not attached to event @nid.', [
            '@id' => (string) $id,
            '@nid' => (string) $event->id(),
          ]);
          continue;
        }
        try {
          $values = $this->ticketTierLifecycle->buildTicketUpdateValuesFromInput($event, $ticket, $account, $row);
          $this->ticketTierLifecycle->updateTicketType($ticket, $event, $values);
        }
        catch (\Throwable $e) {
          $this->logger->error('Studio ticket update failed for ticket @id on event @nid: @m', [
            '@id' => (string) $id,
            '@nid' => (string) $event->id(),
            '@m' => $e->getMessage(),
          ]);
        }
        continue;
      }

      try {
        $values = $this->ticketTierLifecycle->buildTicketValuesFromInput($event, $account, $row);
        $this->ticketTierLifecycle->createAttachAndSync($event, $values);
      }
      catch (\Throwable $e) {
        $this->logger->error('Studio ticket create failed: @m', ['@m' => $e->getMessage()]);
      }
    }

    $this->ticketTierLifecycle->reconcileEventTicketReferences($event);
  }

  /**
   * Commerce variation sync (paid) + durable catalog signal for projections / cache.
   */
  public function syncCommerceAndPublishCatalogSignal(NodeInterface $event): void {
    if ($event->id() === NULL) {
      return;
    }

    $eventType = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';

    if (in_array($eventType, ['paid', 'both'], TRUE)) {
      try {
        $this->ticketTierLifecycle->syncPaidTiers($event);
      }
      catch (\Throwable $e) {
        $this->logger->error('Studio Commerce ticket sync failed for nid @nid: @m', [
          '@nid' => (string) $event->id(),
          '@m' => $e->getMessage(),
        ]);
      }
    }

    if ($this->domainEventBus instanceof DomainEventBus) {
      try {
        $this->domainEventBus->publish(
          'event.ticket_catalog.updated',
          'node',
          (string) $event->id(),
          [
            'event_node_id' => (int) $event->id(),
            'tier_count' => count($this->collectTicketTypeIds($event)),
          ],
          ['source_module' => 'myeventlane_event_studio'],
        );
      }
      catch (\Throwable $e) {
        $this->logger->error('Studio domain event publish failed for nid @nid: @m', [
          '@nid' => (string) $event->id(),
          '@m' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * @return list<int>
   */
  private function collectTicketTypeIds(NodeInterface $event): array {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return [];
    }
    $ids = [];
    foreach ($event->get('field_ticket_types')->getValue() as $row) {
      $tid = (int) ($row['target_id'] ?? 0);
      if ($tid > 0) {
        $ids[] = $tid;
      }
    }
    return $ids;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function normalizeStudioTiersPayload(mixed $raw): array {
    if (!is_array($raw) || $raw === []) {
      return [];
    }
    $out = [];
    foreach ($raw as $row) {
      if (is_array($row)) {
        $out[] = $row;
      }
    }
    return $out;
  }

}
