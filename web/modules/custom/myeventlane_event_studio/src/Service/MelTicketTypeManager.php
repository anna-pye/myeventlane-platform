<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_domain_events\Service\DomainEventBus;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_event\Service\TicketTypeManager as CommerceTicketTypeManager;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Event Studio orchestration for mel_ticket_type rows, Commerce variations, and domain signals.
 *
 * Delegates entity lifecycle to TicketTierLifecycleService and paid sync to
 * CommerceTicketTypeManager (myeventlane_event) — no duplication of variation
 * SKU/price/orphan logic.
 */
final class MelTicketTypeManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
    private readonly CommerceTicketTypeManager $commerceTicketTypeManager,
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
    if ($draft) {
      return [];
    }
    if ($event->bundle() !== 'event') {
      return ['Invalid event bundle.'];
    }

    $tiers = $this->normalizeStudioTiersPayload($payload['studio_ticket_tiers'] ?? NULL);

    $eventKind = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : 'rsvp';

    $hasExistingTicketRefs = $event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty();

    if ($eventKind === 'paid' && ($tiers === [] || empty($payload['studio_ticket_tiers'])) && !$hasExistingTicketRefs) {
      return ['You must create at least one ticket type before saving a paid event.'];
    }

    if ($tiers === []) {
      return [];
    }

    $errors = [];
    $rsvpCapSum = 0;

    foreach ($tiers as $index => $row) {
      $n = (int) $index + 1;
      $prefix = 'Ticket tier #' . $n . ': ';
      $row = $this->normalizeStudioTierRow($row);
      $tierKind = $this->resolveTierKind($row, $eventKind);
      if ($tierKind === NULL) {
        $errors[] = $prefix . 'Ticket kind does not match this event type.';
        continue;
      }

      $id = (int) ($row['id'] ?? 0);
      if ($id > 0) {
        $ticket = $this->loadWritableTicket($id, $event, $account);
        if ($ticket === NULL) {
          $errors[] = $prefix . 'Unknown or inaccessible ticket type.';
          continue;
        }
        if (array_key_exists('title', $row) && trim((string) $row['title']) === '') {
          $errors[] = $prefix . 'Ticket title is required.';
        }
      }
      else {
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
          $errors[] = $prefix . 'Ticket title is required.';
        }
      }

      if ($tierKind === 'paid') {
        $priceNum = trim((string) ($row['price_number'] ?? ''));
        if ($priceNum === '' || !is_numeric($priceNum)) {
          $errors[] = $prefix . 'Paid tickets require a price.';
        }
      }
      if ($tierKind === 'external') {
        $uri = trim((string) ($row['external_uri'] ?? $row['external_url'] ?? ''));
        if ($uri === '') {
          $errors[] = $prefix . 'External tickets require a URL.';
        }
      }

      if (array_key_exists('capacity', $row)) {
        $capRaw = $row['capacity'];
        if ($capRaw === '' || $capRaw === NULL) {
          $cap = 0;
        }
        elseif (is_numeric($capRaw)) {
          $cap = (int) $capRaw;
        }
        else {
          $errors[] = $prefix . 'Capacity must be zero or greater.';
          $cap = 0;
        }
        if ($cap < 0) {
          $errors[] = $prefix . 'Capacity must be zero or greater.';
        }
        if ($tierKind === 'rsvp' && $cap > 0) {
          $rsvpCapSum += $cap;
        }
      }
      elseif ($tierKind === 'rsvp') {
        // No capacity key: treat as unlimited for sum.
      }
    }

    $errors = array_merge($errors, $this->validateRsvpCapacityAgainstEvent($event, $eventKind, $rsvpCapSum));

    return $errors;
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

    $tiers = $this->normalizeStudioTiersPayload($payload['studio_ticket_tiers'] ?? NULL);
    if ($tiers !== []) {
      $this->applyStudioTierRows($event, $account, $payload, $tiers);
    }

    $this->syncCommerceAndPublishCatalogSignal($event);
  }

  /**
   * @param array<string, mixed> $payload
   * @param list<array<string, mixed>> $tiers
   */
  private function applyStudioTierRows(NodeInterface $event, AccountInterface $account, array $payload, array $tiers): void {
    $eventKind = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : 'rsvp';

    $currentIds = $this->collectTicketTypeIds($event);
    $finalIds = $currentIds;

    foreach ($tiers as $row) {
      $row = $this->normalizeStudioTierRow($row);
      $tierKind = $this->resolveTierKind($row, $eventKind);
      if ($tierKind === NULL) {
        continue;
      }

      $id = (int) ($row['id'] ?? 0);
      if ($id > 0) {
        $ticket = $this->loadWritableTicket($id, $event, $account);
        if (!$ticket instanceof TicketTypeInterface) {
          $this->logger->warning('Studio skipped ticket @id: not attached to event @nid.', [
            '@id' => (string) $id,
            '@nid' => (string) $event->id(),
          ]);
          continue;
        }
        $this->applyRowToTicket($ticket, $row, $tierKind, $event, $account);
        $this->ticketTierLifecycle->updateTicketType($ticket, $event);
        $finalIds[] = (int) $ticket->id();
        continue;
      }

      $title = trim((string) ($row['title'] ?? ''));
      if ($title === '') {
        continue;
      }

      $values = $this->buildCreateValues($title, $tierKind, $row, $event, $account);
      try {
        $ticket = $this->ticketTierLifecycle->persistNewTicketType($values);
        $finalIds[] = (int) $ticket->id();
      }
      catch (\Throwable $e) {
        $this->logger->error('Studio ticket create failed: @m', ['@m' => $e->getMessage()]);
      }
    }

    if (!$event->hasField('field_ticket_types')) {
      $this->logger->warning('Studio: event @nid has no field_ticket_types; cannot attach MEL tiers.', [
        '@nid' => (string) $event->id(),
      ]);
      return;
    }

    $targetIds = array_values(array_unique(array_filter(array_map(
      static fn (int $id): int => $id > 0 ? $id : 0,
      $finalIds
    ))));
    sort($targetIds);

    if ($targetIds === []) {
      $this->logger->notice('Studio: no ticket tier IDs to attach for event @nid.', [
        '@nid' => (string) $event->id(),
      ]);
      return;
    }

    $current = array_map(
      static fn (array $item): int => (int) ($item['target_id'] ?? 0),
      $event->get('field_ticket_types')->getValue()
    );
    $current = array_values(array_filter($current));
    sort($current);

    if ($current === $targetIds) {
      return;
    }

    $refs = array_map(static fn (int $tid): array => ['target_id' => $tid], $targetIds);
    $event->set('field_ticket_types', $refs);
    EventNodeRevisionSave::prepare($event, 'Event Studio: ticket tiers attached.');
    try {
      $event->save();
      $this->logger->notice('Studio: merged @count ticket type reference(s) on event @nid.', [
        '@count' => (string) count($targetIds),
        '@nid' => (string) $event->id(),
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Studio failed to save merged ticket types: @m', ['@m' => $e->getMessage()]);
    }
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

  private function resolveTierKind(array $row, string $eventKind): ?string {
    $kind = isset($row['ticket_kind']) ? trim((string) $row['ticket_kind']) : '';
    if ($kind === '') {
      $kind = match ($eventKind) {
        'paid', 'both' => 'paid',
        'external' => 'external',
        default => 'rsvp',
      };
    }

    return match ($eventKind) {
      'rsvp' => $kind === 'rsvp' ? 'rsvp' : NULL,
      'paid' => $kind === 'paid' ? 'paid' : NULL,
      'external' => $kind === 'external' ? 'external' : NULL,
      'both' => in_array($kind, ['rsvp', 'paid', 'external'], TRUE) ? $kind : NULL,
      default => NULL,
    };
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function normalizeStudioTierRow(array $row): array {
    if (isset($row['title'])) {
      $row['title'] = trim((string) $row['title']);
    }
    $row['capacity'] = max(1, (int) ($row['capacity'] ?? 0));
    return $row;
  }

  /**
   * @return list<string>
   */
  private function validateRsvpCapacityAgainstEvent(NodeInterface $event, string $eventKind, int $rsvpTierCapSum): array {
    if ($rsvpTierCapSum < 1 || !in_array($eventKind, ['rsvp', 'both'], TRUE)) {
      return [];
    }
    if (!$event->hasField('field_capacity') || $event->get('field_capacity')->isEmpty()) {
      return [];
    }
    $eventCap = (int) $event->get('field_capacity')->value;
    if ($eventCap < 1) {
      return [];
    }
    if ($rsvpTierCapSum > $eventCap) {
      return [
        sprintf(
          'Combined RSVP tier capacity (%d) exceeds the event RSVP capacity (%d).',
          $rsvpTierCapSum,
          $eventCap
        ),
      ];
    }
    return [];
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function buildCreateValues(string $title, string $tierKind, array $row, NodeInterface $event, AccountInterface $account): array {
    $currency = trim((string) ($row['price_currency'] ?? ''));
    if ($currency === '') {
      $currency = $this->commerceTicketTypeManager->getDefaultCurrencyCodeForEvent($event);
    }

    $values = [
      'title' => $title,
      'ticket_kind' => $tierKind,
      'vendor_id' => ['target_id' => (int) $account->id()],
      'status' => 1,
      'is_reusable' => FALSE,
      'event' => ['target_id' => (int) $event->id()],
      'visibility_mode' => trim((string) ($row['visibility_mode'] ?? 'public')) ?: 'public',
    ];

    $cap = (int) ($row['capacity'] ?? 0);
    if ($cap > 0) {
      $values['capacity'] = $cap;
    }

    if ($tierKind === 'paid') {
      $values['price'] = [
        'number' => (string) $row['price_number'],
        'currency_code' => $currency,
      ];
    }

    if ($tierKind === 'external') {
      $uri = trim((string) ($row['external_uri'] ?? $row['external_url'] ?? ''));
      if ($uri !== '') {
        if (!str_starts_with($uri, 'http://') && !str_starts_with($uri, 'https://')) {
          $uri = 'https://' . $uri;
        }
        $values['external_url'] = [['uri' => $uri, 'title' => '']];
      }
    }

    return $values;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function applyRowToTicket(TicketTypeInterface $ticket, array $row, string $tierKind, NodeInterface $event, AccountInterface $account): void {
    if ((int) $ticket->get('vendor_id')->target_id !== (int) $account->id()
      && !$account->hasPermission('administer mel_ticket_type entities')) {
      return;
    }

    if (isset($row['title'])) {
      $t = trim((string) $row['title']);
      if ($t !== '') {
        $ticket->set('title', $t);
      }
    }

    if ($tierKind === 'paid' && $ticket->hasField('price')) {
      $currency = trim((string) ($row['price_currency'] ?? ''));
      if ($currency === '') {
        $currency = $this->commerceTicketTypeManager->getDefaultCurrencyCodeForEvent($event);
      }
      if (isset($row['price_number']) && is_numeric($row['price_number'])) {
        $ticket->set('price', [
          'number' => (string) $row['price_number'],
          'currency_code' => $currency,
        ]);
      }
    }

    if ($ticket->hasField('capacity')) {
      if (array_key_exists('capacity', $row)) {
        $cap = (int) $row['capacity'];
        $ticket->set('capacity', $cap > 0 ? $cap : NULL);
      }
    }

    if ($tierKind === 'external' && $ticket->hasField('external_url')) {
      $uri = trim((string) ($row['external_uri'] ?? $row['external_url'] ?? ''));
      if ($uri !== '') {
        if (!str_starts_with($uri, 'http://') && !str_starts_with($uri, 'https://')) {
          $uri = 'https://' . $uri;
        }
        $ticket->set('external_url', [['uri' => $uri, 'title' => '']]);
      }
    }
  }

  private function loadWritableTicket(int $id, NodeInterface $event, ?AccountInterface $account): ?TicketTypeInterface {
    if (!$this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage('mel_ticket_type')->load($id);
    if (!$entity instanceof TicketTypeInterface) {
      return NULL;
    }
    if (!$this->ticketTierLifecycle->ticketBelongsToEvent($event, $id)) {
      return NULL;
    }
    if ($account !== NULL
      && (int) $entity->get('vendor_id')->target_id !== (int) $account->id()
      && !$account->hasPermission('administer mel_ticket_type entities')) {
      return NULL;
    }
    return $entity;
  }

}
