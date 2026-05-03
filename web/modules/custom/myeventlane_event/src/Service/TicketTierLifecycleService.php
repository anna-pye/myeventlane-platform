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

  public const CURRENCY_MISMATCH_MESSAGE = 'All tickets for an event must use the same currency.';

  private const SHORT_DESCRIPTION_MAX_LENGTH = 320;

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
  public function persistNewTicketType(array $values, ?NodeInterface $event = NULL, bool $sync = TRUE): TicketTypeInterface {
    $values = $this->normalizePaidTicketPriceCurrency($values);
    $event ??= $this->resolveEventFromTicketValues($values);
    if ($event instanceof NodeInterface) {
      $this->assertNewTicketCurrencyMatchesEvent($event, $values);
    }

    /** @var \Drupal\mel_ticket\Entity\TicketTypeInterface $ticket */
    $ticket = $this->entityTypeManager->getStorage('mel_ticket_type')->create($values);
    $ticket->save();
    if ($sync && $event instanceof NodeInterface) {
      $this->ticketTypeManager->normalizeDefaultTicketSelection($event, $ticket);
      $this->ticketTypeManager->normalizeBestValueTicketSelection($event, $ticket);
      $this->syncPaidTiers($event);
    }
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

    if ($template->hasField('field_use_ticket_attendee_questions')) {
      $values['field_use_ticket_attendee_questions'] = $template->get('field_use_ticket_attendee_questions')->value ? 1 : 0;
    }
    if ($template->hasField('field_attendee_questions') && !$template->get('field_attendee_questions')->isEmpty()) {
      $refs = [];
      foreach ($template->get('field_attendee_questions')->referencedEntities() as $paragraph) {
        if (!$paragraph instanceof \Drupal\paragraphs\ParagraphInterface) {
          continue;
        }
        $dup = $paragraph->createDuplicate();
        $dup->save();
        $refs[] = [
          'target_id' => (int) $dup->id(),
          'target_revision_id' => (int) $dup->getRevisionId(),
        ];
      }
      if ($refs !== []) {
        $values['field_attendee_questions'] = $refs;
      }
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
    $ticket = $this->persistNewTicketType($values, $event, FALSE);
    $this->appendTicketToEvent($event, (int) $ticket->id(), TRUE);
    $this->ticketTypeManager->normalizeDefaultTicketSelection($event, $ticket);
    $this->ticketTypeManager->normalizeBestValueTicketSelection($event, $ticket);
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
      if ($save) {
        $this->syncPaidTiers($event);
      }
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
   * Removes a ticket reference from both sides of the event relationship.
   *
   * @return bool
   *   TRUE when the event field or ticket event reference changed.
   */
  public function detachTicketFromEvent(NodeInterface $event, int $ticketId, bool $save = TRUE): bool {
    $eventFieldChanged = FALSE;
    if (!$event->hasField('field_ticket_types')) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'detachTicketFromEvent: event @nid missing field_ticket_types.',
        ['@nid' => (string) $event->id()]
      );
    }
    else {
      $refs = $event->get('field_ticket_types')->isEmpty()
        ? []
        : $event->get('field_ticket_types')->getValue();
      $filtered = array_values(array_filter(
        $refs,
        static fn (array $item): bool => (int) ($item['target_id'] ?? 0) !== $ticketId
      ));
      if ($filtered !== $refs) {
        $event->set('field_ticket_types', $filtered);
        $eventFieldChanged = TRUE;
      }
    }

    $ticketReferenceChanged = $this->clearTicketEventReference($event, $ticketId);

    if ($save) {
      if ($eventFieldChanged) {
        EventNodeRevisionSave::prepare($event, 'Ticket types updated on event.');
        $event->save();
      }
      $this->ticketTypeManager->normalizeDefaultTicketSelection($event);
      $this->ticketTypeManager->normalizeBestValueTicketSelection($event);
      $this->syncPaidTiers($event);
    }

    return $eventFieldChanged || $ticketReferenceChanged;
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
    $this->ticketTypeManager->normalizeDefaultTicketSelection($event);
    $this->ticketTypeManager->normalizeBestValueTicketSelection($event);
    $this->syncPaidTiers($event);
  }

  /**
   * Unpublishes the tier, detaches it from the event, and syncs Commerce.
   */
  public function archiveTicketOnEvent(NodeInterface $event, TicketTypeInterface $ticket): void {
    $changed = FALSE;
    if ($ticket->isPublished()) {
      $ticket->set('status', 0);
      $changed = TRUE;
    }
    if (!$ticket->isArchived()) {
      $ticket->set('lifecycle_status', TicketTypeInterface::LIFECYCLE_ARCHIVED);
      $changed = TRUE;
    }
    if ($ticket->hasField('field_is_default_ticket') && $ticket->isDefaultTicket()) {
      $ticket->set('field_is_default_ticket', FALSE);
      $changed = TRUE;
    }
    if ($ticket->hasField('field_is_best_value') && $ticket->isBestValueTicket()) {
      $ticket->set('field_is_best_value', FALSE);
      $changed = TRUE;
    }
    if ($changed) {
      try {
        $ticket->save();
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('myeventlane_event')->error(
          'archiveTicketOnEvent: failed to archive ticket @tid for event @nid: @message',
          [
            '@tid' => (string) $ticket->id(),
            '@nid' => (string) $event->id(),
            '@message' => $e->getMessage(),
          ]
        );
        throw $e;
      }
    }

    $this->detachTicketFromEvent($event, (int) $ticket->id(), TRUE);
  }

  /**
   * Persists field changes on an existing ticket and syncs paid projections.
   *
   * @param array<string, mixed> $values
   *   Optional field values to apply before lifecycle validation and save.
   */
  public function updateTicketType(TicketTypeInterface $ticket, ?NodeInterface $event = NULL, array $values = []): void {
    $event ??= $this->resolveEventFromTicket($ticket);
    if ($values !== []) {
      $this->applyValuesToTicket($ticket, $values);
    }
    $this->validateTicketTypeForPersist($ticket, $event);
    $ticket->save();
    if ($event instanceof NodeInterface) {
      $this->ticketTypeManager->normalizeDefaultTicketSelection($event, $ticket);
      $this->ticketTypeManager->normalizeBestValueTicketSelection($event, $ticket);
      $this->syncPaidTiers($event);
    }
  }

  /**
   * Converts ticket-builder style input into lifecycle-owned create values.
   *
   * Event Studio and EventTicketsBuilder intentionally share this path so a
   * "new ticket" row produces the same mel_ticket_type entity values anywhere.
   *
   * @param array<string, mixed> $values
   *
   * @return array<string, mixed>
   */
  public function buildTicketValuesFromInput(NodeInterface $event, AccountInterface $account, array $values): array {
    $kind = $this->normalizeTicketKind($values['ticket_kind'] ?? 'paid');
    $title = trim((string) ($values['title'] ?? ''));
    if ($title === '') {
      throw new InvalidArgumentException('Ticket title is required.');
    }

    $payload = [
      'title' => $title,
      'ticket_kind' => $kind,
      'vendor_id' => ['target_id' => (int) $account->id()],
      'status' => array_key_exists('status', $values)
        ? (!empty($values['status']) ? 1 : 0)
        : 1,
      'lifecycle_status' => TicketTypeInterface::LIFECYCLE_ACTIVE,
      'is_reusable' => FALSE,
    ];

    if ($event->id() !== NULL) {
      $payload['event'] = ['target_id' => (int) $event->id()];
    }

    $shortDescription = $this->normalizeShortDescription((string) ($values['short_description'] ?? ''));
    if ($shortDescription !== NULL) {
      $payload['short_description'] = $shortDescription;
    }

    if (array_key_exists('visibility_mode', $values)) {
      $visibility = trim((string) $values['visibility_mode']);
      $payload['visibility_mode'] = $visibility !== '' ? $visibility : 'public';
    }
    if (array_key_exists('field_is_default_ticket', $values)) {
      $payload['field_is_default_ticket'] = !empty($values['field_is_default_ticket']) ? 1 : 0;
    }
    if (array_key_exists('field_is_best_value', $values)) {
      $payload['field_is_best_value'] = !empty($values['field_is_best_value']) ? 1 : 0;
    }

    if (in_array($kind, ['paid', 'rsvp'], TRUE)) {
      $payload['capacity'] = $this->normalizeOptionalPositiveInteger(
        $values['capacity'] ?? NULL,
        'Capacity must be empty for unlimited or at least 1.',
      );
    }

    if ($kind === 'paid') {
      $amount = $this->extractPriceAmount($values);
      if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        throw new InvalidArgumentException('Paid tickets require a price greater than zero.');
      }
      $currency = $this->extractPriceCurrency($values, $event);
      $payload['price'] = [
        'number' => $amount,
        'currency_code' => $currency,
      ];
    }

    if ($kind === 'external') {
      $uri = $this->extractExternalUri($values);
      if ($uri === '' || !str_starts_with(strtolower($uri), 'https://')) {
        throw new InvalidArgumentException('External tickets require a valid https URL.');
      }
      $payload['external_url'] = [
        'uri' => $uri,
        'title' => '',
      ];
    }

    return $payload;
  }

  /**
   * Converts ticket-builder style input into lifecycle-owned update values.
   *
   * @param array<string, mixed> $values
   *
   * @return array<string, mixed>
   */
  public function buildTicketUpdateValuesFromInput(
    NodeInterface $event,
    TicketTypeInterface $ticket,
    AccountInterface $account,
    array $values,
  ): array {
    if ((int) $ticket->get('vendor_id')->target_id !== (int) $account->id()
      && !$account->hasPermission('administer mel_ticket_type entities')) {
      throw new InvalidArgumentException('Ticket not found on this event.');
    }

    $kind = $ticket->getTicketKind();
    if (array_key_exists('ticket_kind', $values) && $this->normalizeTicketKind($values['ticket_kind']) !== $kind) {
      throw new InvalidArgumentException('Ticket kind cannot be changed here.');
    }

    $payload = [];
    if (array_key_exists('title', $values)) {
      $title = trim((string) $values['title']);
      if ($title === '') {
        throw new InvalidArgumentException('Ticket title is required.');
      }
      $payload['title'] = $title;
    }

    if (in_array($kind, ['paid', 'rsvp'], TRUE) && array_key_exists('capacity', $values)) {
      $payload['capacity'] = $this->normalizeOptionalPositiveInteger(
        $values['capacity'],
        'Capacity must be empty for unlimited or at least 1.',
      );
    }
    elseif (array_key_exists('capacity', $values)) {
      $payload['capacity'] = $this->normalizeOptionalPositiveInteger(
        $values['capacity'],
        'Capacity must be empty for unlimited or at least 1.',
      );
    }

    if ($kind === 'paid' && ($this->hasPriceAmount($values) || array_key_exists('price', $values))) {
      $amount = $this->extractPriceAmount($values);
      if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        throw new InvalidArgumentException('Paid tickets require a price greater than zero.');
      }
      $payload['price'] = [
        'number' => $amount,
        'currency_code' => $this->extractPriceCurrency($values, $event, $ticket),
      ];
    }

    if ($kind === 'external' && (array_key_exists('external_uri', $values) || array_key_exists('external_url', $values))) {
      $uri = $this->extractExternalUri($values);
      if ($uri === '' || !str_starts_with(strtolower($uri), 'https://')) {
        throw new InvalidArgumentException('External tickets require a valid https URL.');
      }
      $payload['external_url'] = [
        'uri' => $uri,
        'title' => '',
      ];
    }

    if (array_key_exists('status_published', $values) && !array_key_exists('status', $values)) {
      $payload['status'] = !empty($values['status_published']) ? 1 : 0;
    }

    foreach (['status', 'visibility_mode', 'group_sale_mode'] as $key) {
      if (array_key_exists($key, $values)) {
        $payload[$key] = $key === 'status'
          ? (!empty($values[$key]) ? 1 : 0)
          : (string) $values[$key];
      }
    }
    if (array_key_exists('field_is_default_ticket', $values)) {
      $payload['field_is_default_ticket'] = !empty($values['field_is_default_ticket']) ? 1 : 0;
    }
    if (array_key_exists('field_is_best_value', $values)) {
      $payload['field_is_best_value'] = !empty($values['field_is_best_value']) ? 1 : 0;
    }

    if (array_key_exists('hidden_label', $values)) {
      $hiddenLabel = trim((string) $values['hidden_label']);
      $payload['hidden_label'] = $hiddenLabel !== '' ? $hiddenLabel : NULL;
    }
    if (array_key_exists('short_description', $values)) {
      $payload['short_description'] = $this->normalizeShortDescription((string) $values['short_description']);
    }
    if (array_key_exists('waitlist_enabled', $values)) {
      $payload['waitlist_enabled'] = !empty($values['waitlist_enabled']) ? 1 : 0;
    }
    if (array_key_exists('waitlist_capacity', $values)) {
      $payload['waitlist_capacity'] = $this->normalizeOptionalNonNegativeInteger(
        $values['waitlist_capacity'],
        'Waitlist capacity must be zero or greater.',
      );
    }
    if (array_key_exists('auto_promote_waitlist', $values)) {
      $payload['auto_promote_waitlist'] = !empty($values['auto_promote_waitlist']) ? 1 : 0;
    }
    if (array_key_exists('capacity', $payload) && $payload['capacity'] === NULL) {
      $payload['waitlist_enabled'] = 0;
      $payload['waitlist_capacity'] = NULL;
      $payload['auto_promote_waitlist'] = 0;
    }
    if (array_key_exists('group_min_size', $values)) {
      $payload['group_min_size'] = $this->normalizeOptionalNonNegativeInteger(
        $values['group_min_size'],
        'Minimum group size must be zero or greater.',
      );
    }
    if (array_key_exists('group_bundle_size', $values)) {
      $payload['group_bundle_size'] = $this->normalizeOptionalNonNegativeInteger(
        $values['group_bundle_size'],
        'Bundle / block size must be zero or greater.',
      );
    }

    return $payload;
  }

  /**
   * Validates Studio-submitted rows using the same lifecycle input builders.
   *
   * @param list<array<string, mixed>> $rows
   *
   * @return list<string>
   */
  public function validateTicketInputRowsForEvent(NodeInterface $event, AccountInterface $account, array $rows, bool $draft): array {
    if ($draft) {
      return [];
    }
    if ($event->bundle() !== 'event') {
      return ['Invalid event bundle.'];
    }

    $eventKind = $this->resolveEventKind($event);
    $hasExistingTicketRefs = $event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty();
    if ($eventKind === 'paid' && $rows === [] && !$hasExistingTicketRefs) {
      return ['You must create at least one ticket type before saving a paid event.'];
    }

    $errors = [];
    $paidCurrenciesByTicketId = $this->ticketTypeManager->getPaidTicketCurrenciesByTicketIdForEvent($event);
    $rsvpCapSum = 0;

    foreach ($rows as $index => $row) {
      $n = (int) $index + 1;
      $prefix = 'Ticket tier #' . $n . ': ';
      $ticketId = (int) ($row['id'] ?? 0);

      try {
        if ($ticketId > 0) {
          $ticket = $this->loadWritableTicketForEvent($event, $ticketId, $account);
          if (!$ticket instanceof TicketTypeInterface) {
            $errors[] = $prefix . 'Unknown or inaccessible ticket type.';
            continue;
          }
          $values = $this->buildTicketUpdateValuesFromInput($event, $ticket, $account, $row);
          if (($ticket->getTicketKind() === 'paid') && isset($values['price']['currency_code'])) {
            unset($paidCurrenciesByTicketId[$ticketId]);
            $paidCurrenciesByTicketId[$ticketId] = (string) $values['price']['currency_code'];
          }
          if ($ticket->getTicketKind() === 'rsvp' && array_key_exists('capacity', $values) && $values['capacity'] !== NULL) {
            $rsvpCapSum += (int) $values['capacity'];
          }
          continue;
        }

        $values = $this->buildTicketValuesFromInput($event, $account, $row);
        if (($values['ticket_kind'] ?? NULL) === 'paid' && isset($values['price']['currency_code'])) {
          $paidCurrenciesByTicketId[-1 * $n] = (string) $values['price']['currency_code'];
        }
        if (($values['ticket_kind'] ?? NULL) === 'rsvp' && array_key_exists('capacity', $values) && $values['capacity'] !== NULL) {
          $rsvpCapSum += (int) $values['capacity'];
        }
      }
      catch (InvalidArgumentException $e) {
        $errors[] = $prefix . $e->getMessage();
      }
    }

    if (count(array_unique(array_values($paidCurrenciesByTicketId))) > 1) {
      $errors[] = self::CURRENCY_MISMATCH_MESSAGE;
    }

    return array_merge($errors, $this->validateRsvpCapacityAgainstEvent($event, $eventKind, $rsvpCapSum));
  }

  /**
   * Applies lifecycle-owned validation/normalisation before ticket persistence.
   */
  public function validateTicketTypeForPersist(TicketTypeInterface $ticket, ?NodeInterface $event = NULL): void {
    $event ??= $this->resolveEventFromTicket($ticket);
    $this->normalizeExistingTicketPriceCurrency($ticket);
    if ($event instanceof NodeInterface) {
      $this->assertExistingTicketCurrencyMatchesEvent($event, $ticket);
    }
  }

  public function syncPaidTiers(NodeInterface $event): void {
    $eventType = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    if (!in_array($eventType, ['paid', 'both'], TRUE)) {
      return;
    }
    if (!$this->ticketTypeManager->syncTicketTypesToVariations($event)) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'Ticket lifecycle Commerce sync failed for event @nid.',
        ['@nid' => (string) $event->id()],
      );
    }
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
        $ticket = $this->entityTypeManager->getStorage('mel_ticket_type')->load($ticketId);
        return $ticket instanceof TicketTypeInterface && !$ticket->isArchived();
      }
    }
    return FALSE;
  }

  /**
   * Ensures node field_ticket_types lists every mel_ticket_type targeting this event.
   *
   * Ticket builder paths set ticket.event but historically some saves omitted the
   * bidirectional node reference. Without this, loadOrderedTicketsForEvent() is
   * empty while ticket entities still exist (inverse lookup).
   *
   * Merge rule: preserve current field order for IDs still valid on ticket entities,
   * then append any ticket IDs found via event reference that were missing.
   */
  public function reconcileEventTicketReferences(NodeInterface $event): void {
    $nid = $event->id();
    if ($nid === NULL || !$event->hasField('field_ticket_types')) {
      return;
    }

    $loaded = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$loaded instanceof NodeInterface) {
      return;
    }
    $event = $loaded;

    $ticketStorage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $candidateIds = array_values(array_map(
      'intval',
      array_values($ticketStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $nid)
        ->sort('id')
        ->execute()),
    ));
    $inverseIds = [];
    foreach ($ticketStorage->loadMultiple($candidateIds) as $ticket) {
      if ($ticket instanceof TicketTypeInterface && !$ticket->isArchived()) {
        $inverseIds[] = (int) $ticket->id();
      }
    }

    if ($inverseIds === []) {
      return;
    }

    $fieldIds = [];
    if (!$event->get('field_ticket_types')->isEmpty()) {
      foreach ($event->get('field_ticket_types')->getValue() as $row) {
        if (!empty($row['target_id'])) {
          $fieldIds[] = (int) $row['target_id'];
        }
      }
    }
    $fieldIds = array_values(array_unique($fieldIds));

    $inverseSet = array_flip($inverseIds);
    $merged = [];
    foreach ($fieldIds as $id) {
      if (isset($inverseSet[$id])) {
        $merged[] = $id;
      }
    }
    foreach ($inverseIds as $id) {
      if (!in_array($id, $merged, TRUE)) {
        $merged[] = $id;
      }
    }

    if ($merged === $fieldIds) {
      return;
    }

    $event->set(
      'field_ticket_types',
      array_map(static fn (int $id): array => ['target_id' => $id], $merged),
    );
    EventNodeRevisionSave::prepare($event, 'Synced ticket type references from ticket entities.');
    try {
      $event->save();
      $this->loggerFactory->get('myeventlane_event')->notice(
        'Reconciled field_ticket_types on event @nid: @ids.',
        [
          '@nid' => (string) $nid,
          '@ids' => implode(',', array_map('strval', $merged)),
        ],
      );
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'reconcileEventTicketReferences save failed for nid @nid: @message',
        [
          '@nid' => (string) $nid,
          '@message' => $e->getMessage(),
        ],
      );
      return;
    }

    $this->syncPaidTiers($event);
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
      if ($entity instanceof TicketTypeInterface && !$entity->isArchived()) {
        $out[] = $entity;
      }
    }
    return $out;
  }

  public function loadWritableTicketForEvent(NodeInterface $event, int $ticketId, AccountInterface $account): ?TicketTypeInterface {
    if (!$this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage('mel_ticket_type')->load($ticketId);
    if (!$entity instanceof TicketTypeInterface) {
      return NULL;
    }
    if (!$this->ticketBelongsToEvent($event, $ticketId)) {
      return NULL;
    }
    if ((int) $entity->get('vendor_id')->target_id !== (int) $account->id()
      && !$account->hasPermission('administer mel_ticket_type entities')) {
      return NULL;
    }
    return $entity;
  }

  private function clearTicketEventReference(NodeInterface $event, int $ticketId): bool {
    $ticket = $this->entityTypeManager->getStorage('mel_ticket_type')->load($ticketId);
    if (!$ticket instanceof TicketTypeInterface) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'clearTicketEventReference: ticket @tid could not be loaded for event @nid.',
        [
          '@tid' => (string) $ticketId,
          '@nid' => (string) $event->id(),
        ]
      );
      return FALSE;
    }

    if (!$ticket->hasField('event') || $ticket->get('event')->isEmpty()) {
      return FALSE;
    }

    $eventId = $event->id();
    if ($eventId === NULL || (int) $ticket->get('event')->target_id !== (int) $eventId) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'clearTicketEventReference: ticket @tid event reference did not match event @nid.',
        [
          '@tid' => (string) $ticketId,
          '@nid' => (string) ($eventId ?? 'new'),
        ]
      );
      return FALSE;
    }

    $ticket->set('event', NULL);
    try {
      $ticket->save();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'clearTicketEventReference: failed to clear ticket @tid event reference for event @nid: @message',
        [
          '@tid' => (string) $ticketId,
          '@nid' => (string) $eventId,
          '@message' => $e->getMessage(),
        ]
      );
      throw $e;
    }

    return TRUE;
  }

  /**
   * @param array<string, mixed> $values
   *
   * @return array<string, mixed>
   */
  private function normalizePaidTicketPriceCurrency(array $values): array {
    if (($values['ticket_kind'] ?? NULL) !== 'paid' || !is_array($values['price'] ?? NULL)) {
      return $values;
    }

    $currency = strtoupper(trim((string) ($values['price']['currency_code'] ?? '')));
    if ($currency !== '') {
      $values['price']['currency_code'] = $currency;
    }

    return $values;
  }

  private function normalizeExistingTicketPriceCurrency(TicketTypeInterface $ticket): void {
    if ($ticket->getTicketKind() !== 'paid' || $ticket->get('price')->isEmpty()) {
      return;
    }

    $price = $ticket->toPriceValue();
    if ($price === NULL) {
      return;
    }

    $currency = strtoupper($price->getCurrencyCode());
    if ($currency === $price->getCurrencyCode()) {
      return;
    }

    $ticket->set('price', [[
      'number' => $price->getNumber(),
      'currency_code' => $currency,
    ]]);
  }

  /**
   * @param array<string, mixed> $values
   */
  private function applyValuesToTicket(TicketTypeInterface $ticket, array $values): void {
    if (isset($values['title'])) {
      $ticket->set('title', (string) $values['title']);
    }
    if (isset($values['ticket_kind'])) {
      $ticket->set('ticket_kind', (string) $values['ticket_kind']);
    }
    if (array_key_exists('status', $values)) {
      $ticket->set('status', (int) $values['status']);
    }
    if (array_key_exists('field_is_default_ticket', $values) && $ticket->hasField('field_is_default_ticket')) {
      $ticket->set('field_is_default_ticket', !empty($values['field_is_default_ticket']));
    }
    if (array_key_exists('field_is_best_value', $values) && $ticket->hasField('field_is_best_value')) {
      $ticket->set('field_is_best_value', !empty($values['field_is_best_value']));
    }
    if (array_key_exists('lifecycle_status', $values)) {
      $ticket->set('lifecycle_status', (string) $values['lifecycle_status']);
    }
    if (array_key_exists('capacity', $values)) {
      $value = $values['capacity'];
      $ticket->set('capacity', ($value === NULL || $value === '') ? NULL : (int) $value);
    }
    if (isset($values['price']) && is_array($values['price'])) {
      $ticket->set('price', [[
        'number' => (string) $values['price']['number'],
        'currency_code' => (string) $values['price']['currency_code'],
      ]]);
    }
    if (array_key_exists('external_url', $values)) {
      $row = $values['external_url'];
      if ($row === NULL || $row === '') {
        $ticket->set('external_url', NULL);
      }
      elseif (is_array($row)) {
        if (isset($row[0]) && is_array($row[0])) {
          $row = $row[0];
        }
        $ticket->set('external_url', [[
          'uri' => (string) ($row['uri'] ?? ''),
          'title' => (string) ($row['title'] ?? ''),
        ]]);
      }
    }
    if (array_key_exists('rsvp_limit', $values)) {
      $value = $values['rsvp_limit'];
      $ticket->set('rsvp_limit', ($value === NULL || $value === '') ? NULL : (int) $value);
    }
    if (array_key_exists('sale_start', $values)) {
      $value = $values['sale_start'];
      $ticket->set('sale_start', ($value !== NULL && $value !== '') ? ['value' => $value] : NULL);
    }
    if (array_key_exists('sale_end', $values)) {
      $value = $values['sale_end'];
      $ticket->set('sale_end', ($value !== NULL && $value !== '') ? ['value' => $value] : NULL);
    }
    if (array_key_exists('visibility_mode', $values) && $ticket->hasField('visibility_mode')) {
      $ticket->set('visibility_mode', (string) $values['visibility_mode']);
    }
    if (array_key_exists('hidden_label', $values) && $ticket->hasField('hidden_label')) {
      $value = $values['hidden_label'];
      $ticket->set('hidden_label', ($value === NULL || $value === '') ? NULL : (string) $value);
    }
    if (array_key_exists('short_description', $values) && $ticket->hasField('short_description')) {
      $value = $values['short_description'];
      $ticket->set('short_description', ($value === NULL || $value === '') ? NULL : (string) $value);
    }
    if (array_key_exists('waitlist_enabled', $values) && $ticket->hasField('waitlist_enabled')) {
      $ticket->set('waitlist_enabled', (bool) $values['waitlist_enabled']);
    }
    if (array_key_exists('waitlist_capacity', $values) && $ticket->hasField('waitlist_capacity')) {
      $value = $values['waitlist_capacity'];
      $ticket->set('waitlist_capacity', ($value === NULL || $value === '') ? NULL : (int) $value);
    }
    if (array_key_exists('auto_promote_waitlist', $values) && $ticket->hasField('auto_promote_waitlist')) {
      $ticket->set('auto_promote_waitlist', (bool) $values['auto_promote_waitlist']);
    }
    if (array_key_exists('group_sale_mode', $values) && $ticket->hasField('group_sale_mode')) {
      $ticket->set('group_sale_mode', (string) $values['group_sale_mode']);
    }
    if (array_key_exists('group_min_size', $values) && $ticket->hasField('group_min_size')) {
      $value = $values['group_min_size'];
      $ticket->set('group_min_size', ($value === NULL || $value === '') ? NULL : (int) $value);
    }
    if (array_key_exists('group_bundle_size', $values) && $ticket->hasField('group_bundle_size')) {
      $value = $values['group_bundle_size'];
      $ticket->set('group_bundle_size', ($value === NULL || $value === '') ? NULL : (int) $value);
    }
    if (array_key_exists('field_use_ticket_attendee_questions', $values) && $ticket->hasField('field_use_ticket_attendee_questions')) {
      $ticket->set('field_use_ticket_attendee_questions', (bool) $values['field_use_ticket_attendee_questions']);
    }
    if (array_key_exists('field_attendee_questions', $values) && $ticket->hasField('field_attendee_questions')) {
      $ticket->set('field_attendee_questions', $values['field_attendee_questions']);
    }
  }

  private function normalizeTicketKind(mixed $kind): string {
    $kind = trim((string) $kind);
    if (!in_array($kind, ['paid', 'rsvp', 'external'], TRUE)) {
      throw new InvalidArgumentException('Ticket kind must be paid, RSVP, or external.');
    }
    return $kind;
  }

  /**
   * @param array<string, mixed> $values
   */
  private function extractPriceAmount(array $values): string {
    if (array_key_exists('price_amount', $values)) {
      return trim((string) $values['price_amount']);
    }
    if (array_key_exists('price_number', $values)) {
      return trim((string) $values['price_number']);
    }
    if (is_array($values['price'] ?? NULL)) {
      return trim((string) ($values['price']['number'] ?? ''));
    }
    if (array_key_exists('price', $values)) {
      return trim((string) $values['price']);
    }
    return '';
  }

  /**
   * @param array<string, mixed> $values
   */
  private function hasPriceAmount(array $values): bool {
    return array_key_exists('price_amount', $values) || array_key_exists('price_number', $values);
  }

  /**
   * @param array<string, mixed> $values
   */
  private function extractPriceCurrency(array $values, NodeInterface $event, ?TicketTypeInterface $ticket = NULL): string {
    $currency = strtoupper(trim((string) ($values['price_currency'] ?? '')));
    if ($currency === '' && is_array($values['price'] ?? NULL)) {
      $currency = strtoupper(trim((string) ($values['price']['currency_code'] ?? '')));
    }
    if ($currency === '' && $ticket instanceof TicketTypeInterface) {
      $price = $ticket->toPriceValue();
      if ($price !== NULL) {
        $currency = strtoupper($price->getCurrencyCode());
      }
    }
    if ($currency === '') {
      $currency = strtoupper($this->ticketTypeManager->getDefaultCurrencyCodeForEvent($event));
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
      throw new InvalidArgumentException('Enter a valid 3-letter currency code.');
    }
    return $currency;
  }

  /**
   * @param array<string, mixed> $values
   */
  private function extractExternalUri(array $values): string {
    $raw = $values['external_uri'] ?? $values['external_url'] ?? '';
    if (is_array($raw)) {
      $raw = $raw['uri'] ?? $raw[0]['uri'] ?? '';
    }
    return trim((string) $raw);
  }

  private function normalizeShortDescription(string $raw): ?string {
    $text = trim($raw);
    if ($text === '') {
      return NULL;
    }
    if (mb_strlen($text) > self::SHORT_DESCRIPTION_MAX_LENGTH) {
      $text = mb_substr($text, 0, self::SHORT_DESCRIPTION_MAX_LENGTH);
    }
    return $text;
  }

  private function normalizeOptionalNonNegativeInteger(mixed $value, string $message): ?int {
    if ($value === NULL) {
      return NULL;
    }
    if (is_array($value) || is_object($value)) {
      throw new InvalidArgumentException($message);
    }
    $raw = trim((string) $value);
    if ($raw === '') {
      return NULL;
    }
    if (!is_numeric($raw) || (int) $raw < 0) {
      throw new InvalidArgumentException($message);
    }
    return (int) $raw;
  }

  private function normalizeOptionalPositiveInteger(mixed $value, string $message): ?int {
    if ($value === NULL) {
      return NULL;
    }
    if (is_array($value) || is_object($value)) {
      throw new InvalidArgumentException($message);
    }
    $raw = trim((string) $value);
    if ($raw === '') {
      return NULL;
    }
    if (!preg_match('/^\d+$/', $raw) || (int) $raw < 1) {
      throw new InvalidArgumentException($message);
    }
    return (int) $raw;
  }

  private function resolveEventKind(NodeInterface $event): string {
    return $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : 'rsvp';
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
   * @param array<string, mixed> $values
   */
  private function assertNewTicketCurrencyMatchesEvent(NodeInterface $event, array $values): void {
    if (($values['ticket_kind'] ?? NULL) !== 'paid' || !is_array($values['price'] ?? NULL)) {
      return;
    }

    $currency = trim((string) ($values['price']['currency_code'] ?? ''));
    if (!$this->ticketTypeManager->paidTicketCurrencyMatchesEvent($event, $currency)) {
      throw new InvalidArgumentException(self::CURRENCY_MISMATCH_MESSAGE);
    }
  }

  private function assertExistingTicketCurrencyMatchesEvent(NodeInterface $event, TicketTypeInterface $ticket): void {
    if ($ticket->getTicketKind() !== 'paid') {
      return;
    }

    $price = $ticket->toPriceValue();
    if ($price === NULL) {
      return;
    }

    $excludeTicketId = $ticket->isNew() ? NULL : (int) $ticket->id();
    if (!$this->ticketTypeManager->paidTicketCurrencyMatchesEvent($event, $price->getCurrencyCode(), $excludeTicketId)) {
      throw new InvalidArgumentException(self::CURRENCY_MISMATCH_MESSAGE);
    }
  }

  private function resolveEventFromTicket(TicketTypeInterface $ticket): ?NodeInterface {
    if ($ticket->get('event')->isEmpty()) {
      return NULL;
    }

    $event = $ticket->get('event')->entity;
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

  /**
   * @param array<string, mixed> $values
   */
  private function resolveEventFromTicketValues(array $values): ?NodeInterface {
    $target_id = 0;
    if (is_array($values['event'] ?? NULL)) {
      $target_id = (int) ($values['event']['target_id'] ?? 0);
    }
    elseif (isset($values['event'])) {
      $target_id = (int) $values['event'];
    }

    if ($target_id < 1) {
      return NULL;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($target_id);
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

}
