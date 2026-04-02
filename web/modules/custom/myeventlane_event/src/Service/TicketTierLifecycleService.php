<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\node\NodeInterface;
use InvalidArgumentException;

/**
 * Single pipeline for mel_ticket_type create/update, event attachment, and sync.
 *
 * Forms and controllers must not call mel_ticket_type storage->create() directly;
 * use this service so Commerce projection stays consistent.
 */
final class TicketTierLifecycleService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketTypeManager $ticketTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Creates and saves a new ticket type without attaching it to an event.
   *
   * Used by the event wizard partial submits (IDs are written to the event on
   * step save).
   */
  public function persistNewTicketType(array $values): TicketTypeInterface {
    /** @var \Drupal\mel_ticket\Entity\TicketTypeInterface $ticket */
    $ticket = $this->entityTypeManager->getStorage('mel_ticket_type')->create($values);
    $ticket->save();
    return $ticket;
  }

  /**
   * Clones a reusable template into a new non-reusable row for an event.
   *
   * Does not update the event node; caller appends the new ID as needed.
   *
   * @throws \InvalidArgumentException
   */
  public function cloneFromReusableTemplate(
    TicketTypeInterface $template,
    NodeInterface $event,
    AccountInterface $account,
  ): TicketTypeInterface {
    if ($event->bundle() !== 'event') {
      throw new InvalidArgumentException('Clone target must be an event node.');
    }
    if (!$template->isReusable()) {
      throw new InvalidArgumentException('Only reusable tickets can be used as templates.');
    }
    if ((int) $template->get('vendor_id')->target_id !== (int) $account->id()) {
      throw new InvalidArgumentException('Templates must be owned by the current user.');
    }
    $kind = $template->getTicketKind();
    if ($kind === 'paid') {
      throw new InvalidArgumentException('Paid ticket templates are not supported; create paid tiers per event.');
    }
    if (!in_array($kind, ['rsvp', 'external'], TRUE)) {
      throw new InvalidArgumentException('Unsupported ticket kind for template clone.');
    }

    $values = [
      'title' => $template->getTitle(),
      'ticket_kind' => $kind,
      'vendor_id' => $template->get('vendor_id')->getValue(),
      'is_reusable' => FALSE,
      'event' => ['target_id' => $event->id()],
      'status' => 1,
    ];

    if (!$template->get('capacity')->isEmpty()) {
      $values['capacity'] = (int) $template->get('capacity')->value;
    }
    if (!$template->get('rsvp_limit')->isEmpty()) {
      $values['rsvp_limit'] = (int) $template->get('rsvp_limit')->value;
    }
    if (!$template->get('sale_start')->isEmpty()) {
      $values['sale_start'] = $template->get('sale_start')->getValue();
    }
    if (!$template->get('sale_end')->isEmpty()) {
      $values['sale_end'] = $template->get('sale_end')->getValue();
    }
    if (!$template->get('external_url')->isEmpty()) {
      $values['external_url'] = $template->get('external_url')->getValue();
    }

    if ($template->hasField('visibility_mode') && !$template->get('visibility_mode')->isEmpty()) {
      $values['visibility_mode'] = (string) $template->get('visibility_mode')->value;
    }
    if ($template->hasField('hidden_label') && !$template->get('hidden_label')->isEmpty()) {
      $values['hidden_label'] = (string) $template->get('hidden_label')->value;
    }
    if ($template->hasField('short_description') && !$template->get('short_description')->isEmpty()) {
      $values['short_description'] = (string) $template->get('short_description')->value;
    }
    if ($template->hasField('waitlist_enabled')) {
      $values['waitlist_enabled'] = $template->get('waitlist_enabled')->value ? 1 : 0;
    }
    if ($template->hasField('waitlist_capacity') && !$template->get('waitlist_capacity')->isEmpty()) {
      $values['waitlist_capacity'] = (int) $template->get('waitlist_capacity')->value;
    }
    if ($template->hasField('auto_promote_waitlist')) {
      $values['auto_promote_waitlist'] = $template->get('auto_promote_waitlist')->value ? 1 : 0;
    }
    if ($template->hasField('group_sale_mode') && !$template->get('group_sale_mode')->isEmpty()) {
      $values['group_sale_mode'] = (string) $template->get('group_sale_mode')->value;
    }
    if ($template->hasField('group_min_size') && !$template->get('group_min_size')->isEmpty()) {
      $values['group_min_size'] = (int) $template->get('group_min_size')->value;
    }
    if ($template->hasField('group_bundle_size') && !$template->get('group_bundle_size')->isEmpty()) {
      $values['group_bundle_size'] = (int) $template->get('group_bundle_size')->value;
    }

    $values['price'] = NULL;
    $values['commerce_variation'] = NULL;
    $values['template_source'] = ['target_id' => (int) $template->id()];

    return $this->persistNewTicketType($values);
  }

  /**
   * Creates a ticket, appends it to the event, saves the event, and syncs paid.
   */
  public function createAttachAndSync(NodeInterface $event, array $values): TicketTypeInterface {
    $ticket = $this->persistNewTicketType($values);
    $this->appendTicketToEvent($event, (int) $ticket->id(), TRUE);
    return $ticket;
  }

  /**
   * Appends a ticket reference to field_ticket_types (deduped).
   */
  public function appendTicketToEvent(NodeInterface $event, int $ticketId, bool $save = TRUE): void {
    if (!$event->hasField('field_ticket_types')) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'appendTicketToEvent: event @nid missing field_ticket_types.',
        ['@nid' => (string) $event->id()]
      );
      return;
    }
    $refs = $event->get('field_ticket_types')->isEmpty()
      ? []
      : $event->get('field_ticket_types')->getValue();
    $ids = [];
    foreach ($refs as $row) {
      if (!empty($row['target_id'])) {
        $ids[] = (int) $row['target_id'];
      }
    }
    $ids[] = $ticketId;
    $ids = array_values(array_unique($ids));
    $event->set('field_ticket_types', array_map(static fn (int $id) => ['target_id' => $id], $ids));
    if ($save) {
      EventNodeRevisionSave::prepare($event, 'Ticket types updated on event.');
      $event->save();
      $this->syncPaidTiers($event);
    }
  }

  /**
   * Removes a ticket reference from the event and optionally saves.
   */
  public function detachTicketFromEvent(NodeInterface $event, int $ticketId, bool $save = TRUE): void {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return;
    }
    $refs = array_filter(
      $event->get('field_ticket_types')->getValue(),
      static fn (array $item): bool => (int) ($item['target_id'] ?? 0) !== $ticketId
    );
    $event->set('field_ticket_types', array_values($refs));
    if ($save) {
      EventNodeRevisionSave::prepare($event, 'Ticket types updated on event.');
      $event->save();
      $this->syncPaidTiers($event);
    }
  }

  /**
   * Reorders ticket references: $orderedTicketIds first (intersecting current),
   * then any remaining attachments in their previous order.
   */
  public function reorderTicketsOnEvent(NodeInterface $event, array $orderedTicketIds): void {
    if (!$event->hasField('field_ticket_types')) {
      return;
    }
    $orderedTicketIds = array_values(array_unique(array_map('intval', $orderedTicketIds)));
    $current = [];
    if (!$event->get('field_ticket_types')->isEmpty()) {
      foreach ($event->get('field_ticket_types')->getValue() as $row) {
        if (!empty($row['target_id'])) {
          $current[] = (int) $row['target_id'];
        }
      }
    }
    $orderedSet = array_flip($orderedTicketIds);
    $new = [];
    foreach ($orderedTicketIds as $id) {
      if (in_array($id, $current, TRUE)) {
        $new[] = $id;
      }
    }
    foreach ($current as $id) {
      if (!isset($orderedSet[$id])) {
        $new[] = $id;
      }
    }
    $event->set('field_ticket_types', array_map(static fn (int $id) => ['target_id' => $id], $new));
    EventNodeRevisionSave::prepare($event, 'Ticket order updated on event.');
    $event->save();
    $this->syncPaidTiers($event);
  }

  /**
   * Detaches from event, unpublishes the tier, syncs Commerce orphans.
   */
  public function archiveTicketOnEvent(NodeInterface $event, TicketTypeInterface $ticket): void {
    $this->detachTicketFromEvent($event, (int) $ticket->id(), TRUE);
    $ticket->set('status', 0);
    $ticket->save();
    $this->syncPaidTiers($event);
  }

  /**
   * Persists field changes on an existing ticket and syncs paid projections.
   */
  public function updateTicketType(TicketTypeInterface $ticket, NodeInterface $event): void {
    $ticket->save();
    if ($this->ticketBelongsToEvent($event, (int) $ticket->id())) {
      $this->syncPaidTiers($event);
    }
  }

  public function syncPaidTiers(NodeInterface $event): void {
    $eventType = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    if (!in_array($eventType, ['paid', 'both'], TRUE)) {
      return;
    }
    $this->ticketTypeManager->syncTicketTypesToVariations($event);
  }

  /**
   * Whether the ticket ID is referenced on the event.
   */
  public function ticketBelongsToEvent(NodeInterface $event, int $ticketId): bool {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return FALSE;
    }
    foreach ($event->get('field_ticket_types')->getValue() as $row) {
      if ((int) ($row['target_id'] ?? 0) === $ticketId) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Loads ticket types for an event in field order.
   *
   * @return \Drupal\mel_ticket\Entity\TicketTypeInterface[]
   */
  public function loadOrderedTicketsForEvent(NodeInterface $event): array {
    $out = [];
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return $out;
    }
    foreach ($event->get('field_ticket_types')->referencedEntities() as $entity) {
      if ($entity instanceof TicketTypeInterface) {
        $out[] = $entity;
      }
    }
    return $out;
  }

}
