<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Service;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use InvalidArgumentException;

/**
 * Single pipeline for mel_ticket_type create/update, event attachment, and sync.
 *
 * Forms and controllers must not call mel_ticket_type storage->create() directly;
 * use this service so Commerce projection stays consistent.
 */
final class TicketTierLifecycleService implements EventPaidTicketLoaderInterface {

  public const CURRENCY_MISMATCH_MESSAGE = 'All tickets for an event must use the same currency.';

  private const SHORT_DESCRIPTION_MAX_LENGTH = 320;

  private const TICKET_TITLE_MAX_LENGTH = 255;

  private const DUPLICATE_TITLE_SUFFIX = ' (copy)';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketTypeManager $ticketTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly Connection $database,
    private readonly TicketTierDeletionGuard $ticketTierDeletionGuard,
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
   * Loads one private reusable setup owned by the current organiser.
   */
  public function loadReusableTicketSetupForAccount(int $setupId, AccountInterface $account): ?TicketTypeInterface {
    if ($setupId < 1 || (int) $account->id() < 1) {
      return NULL;
    }
    $setup = $this->entityTypeManager->getStorage('mel_ticket_type')->load($setupId);
    return $setup instanceof TicketTypeInterface
      && $setup->isReusable()
      && !$setup->isArchived()
      && (int) $setup->get('vendor_id')->target_id === (int) $account->id()
        ? $setup
        : NULL;
  }

  /**
   * Loads private reusable setups owned by the current organiser.
   *
   * @return array<int, \Drupal\mel_ticket\Entity\TicketTypeInterface>
   */
  public function loadReusableTicketSetupsForAccount(AccountInterface $account): array {
    $accountId = (int) $account->id();
    if ($accountId < 1) {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('mel_ticket_type')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vendor_id', $accountId)
      ->condition('is_reusable', 1)
      ->condition('lifecycle_status', TicketTypeInterface::LIFECYCLE_ACTIVE)
      ->sort('title')
      ->sort('id')
      ->execute();
    if ($ids === []) {
      return [];
    }
    $loaded = $this->entityTypeManager->getStorage('mel_ticket_type')->loadMultiple($ids);
    $setups = [];
    foreach ($ids as $id) {
      $setup = $loaded[$id] ?? NULL;
      if ($setup instanceof TicketTypeInterface && $setup->isReusable()) {
        $setups[(int) $id] = $setup;
      }
    }
    return $setups;
  }

  /**
   * Saves a private configuration-only setup from an event ticket.
   *
   * Capacity, sales windows, attendee questions, order history, event IDs and
   * Commerce variation IDs are intentionally excluded.
   */
  public function saveReusableTicketSetupFromTicket(
    TicketTypeInterface $source,
    AccountInterface $account,
  ): TicketTypeInterface {
    if ((int) $source->get('vendor_id')->target_id !== (int) $account->id()) {
      throw new InvalidArgumentException('Ticket setups must be owned by the current user.');
    }
    $kind = $source->getTicketKind();
    if (!in_array($kind, ['paid', 'rsvp', 'external'], TRUE)) {
      throw new InvalidArgumentException('Unsupported ticket kind for reusable setup.');
    }

    $values = $this->reusableTicketSetupValues($source, $account);
    foreach ($this->loadReusableTicketSetupsForAccount($account) as $existing) {
      if ($this->reusableTicketSetupMatches($existing, $values)) {
        return $existing;
      }
    }

    /** @var \Drupal\mel_ticket\Entity\TicketTypeInterface $setup */
    $setup = $this->entityTypeManager->getStorage('mel_ticket_type')->create($values);
    // TicketType::preCreate historically defaults empty status values to TRUE.
    // Reassert privacy before the first save so templates are never public.
    $setup->set('status', FALSE);
    $setup->save();
    return $setup;
  }

  /**
   * Applies source edits and saves a reusable setup in one transaction.
   *
   * @param array<string, mixed> $values
   */
  public function updateAndSaveReusableTicketSetup(
    NodeInterface $event,
    TicketTypeInterface $ticket,
    AccountInterface $account,
    array $values,
  ): TicketTypeInterface {
    $this->applyTicketValuesWithoutSave($ticket, $values);
    $this->validateTicketTypeForPersist($ticket, $event);

    $transaction = $this->database->startTransaction();
    try {
      $this->updateTicketType($ticket, $event, []);
      return $this->saveReusableTicketSetupFromTicket($ticket, $account);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Builds fresh event-ticket values from a reusable setup.
   *
   * The result never carries capacity, sales dates, attendee questions,
   * event references, order data or Commerce variation references from the
   * source setup. Organiser overrides are accepted only for the new event.
   *
   * @param array<string, mixed> $overrides
   *
   * @return array<string, mixed>
   */
  public function buildTicketValuesFromReusableTemplate(
    TicketTypeInterface $template,
    NodeInterface $event,
    AccountInterface $account,
    array $overrides = [],
  ): array {
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
    if (!in_array($kind, ['paid', 'rsvp', 'external'], TRUE)) {
      throw new InvalidArgumentException('Unsupported ticket kind for template clone.');
    }

    $input = [
      'title' => $template->getTitle(),
      'ticket_kind' => $kind,
      'status' => 0,
      'capacity' => NULL,
      'field_is_best_value' => 0,
    ];
    if ($template->hasField('visibility_mode') && !$template->get('visibility_mode')->isEmpty()) {
      $input['visibility_mode'] = (string) $template->get('visibility_mode')->value;
    }
    if ($template->hasField('short_description') && !$template->get('short_description')->isEmpty()) {
      $input['short_description'] = (string) $template->get('short_description')->value;
    }
    if ($kind === 'paid') {
      $price = $template->toPriceValue();
      if ($price === NULL || (float) $price->getNumber() <= 0) {
        throw new InvalidArgumentException('Paid ticket setups require a price greater than zero.');
      }
      $input['price_amount'] = $price->getNumber();
      $input['price_currency'] = $price->getCurrencyCode();
    }
    if ($kind === 'external') {
      $uri = $template->getExternalUrlString();
      if ($uri === NULL || $uri === '') {
        throw new InvalidArgumentException('External ticket setups require a valid https URL.');
      }
      $input['external_uri'] = $uri;
    }

    foreach (['title', 'price_amount', 'price_currency', 'capacity', 'external_uri', 'visibility_mode', 'status', 'field_is_best_value'] as $key) {
      if (array_key_exists($key, $overrides)) {
        $input[$key] = $overrides[$key];
      }
    }
    // A saved setup cannot change kind while being applied.
    $input['ticket_kind'] = $kind;

    $values = $this->buildTicketValuesFromInput($event, $account, $input);
    if ($template->hasField('hidden_label') && !$template->get('hidden_label')->isEmpty()) {
      $values['hidden_label'] = (string) $template->get('hidden_label')->value;
    }
    $values['template_source'] = ['target_id' => (int) $template->id()];
    $values['commerce_variation'] = NULL;
    return $values;
  }

  /**
   * Creates and attaches a fresh event ticket from a reusable setup.
   *
   * @param array<string, mixed> $overrides
   */
  public function cloneFromReusableTemplate(
    TicketTypeInterface $template,
    NodeInterface $event,
    AccountInterface $account,
    array $overrides = [],
  ): TicketTypeInterface {
    $values = $this->buildTicketValuesFromReusableTemplate($template, $event, $account, $overrides);
    return $this->createAttachAndSync($event, $values);
  }

  /**
   * @return array<string, mixed>
   */
  private function reusableTicketSetupValues(TicketTypeInterface $source, AccountInterface $account): array {
    $kind = $source->getTicketKind();
    $values = [
      'title' => $source->getTitle(),
      'ticket_kind' => $kind,
      'vendor_id' => ['target_id' => (int) $account->id()],
      'is_reusable' => TRUE,
      'event' => NULL,
      'status' => 0,
      'lifecycle_status' => TicketTypeInterface::LIFECYCLE_ACTIVE,
      'field_is_best_value' => 0,
      'field_is_default_ticket' => 0,
      'commerce_variation' => NULL,
    ];
    if ($source->hasField('short_description') && !$source->get('short_description')->isEmpty()) {
      $values['short_description'] = (string) $source->get('short_description')->value;
    }
    if ($source->hasField('visibility_mode') && !$source->get('visibility_mode')->isEmpty()) {
      $values['visibility_mode'] = (string) $source->get('visibility_mode')->value;
    }
    if ($source->hasField('hidden_label') && !$source->get('hidden_label')->isEmpty()) {
      $values['hidden_label'] = (string) $source->get('hidden_label')->value;
    }
    if ($kind === 'paid') {
      $price = $source->toPriceValue();
      if ($price === NULL || (float) $price->getNumber() <= 0) {
        throw new InvalidArgumentException('Paid ticket setups require a price greater than zero.');
      }
      $values['price'] = [
        'number' => $price->getNumber(),
        'currency_code' => $price->getCurrencyCode(),
      ];
    }
    if ($kind === 'external') {
      $uri = $source->getExternalUrlString();
      if ($uri === NULL || $uri === '') {
        throw new InvalidArgumentException('External ticket setups require a valid https URL.');
      }
      $values['external_url'] = $source->get('external_url')->getValue();
    }
    return $values;
  }

  /**
   * @param array<string, mixed> $values
   */
  private function reusableTicketSetupMatches(TicketTypeInterface $setup, array $values): bool {
    if ($setup->getTitle() !== (string) $values['title'] || $setup->getTicketKind() !== (string) $values['ticket_kind']) {
      return FALSE;
    }
    $expectedVisibility = (string) ($values['visibility_mode'] ?? 'public');
    $actualVisibility = $setup->hasField('visibility_mode') && !$setup->get('visibility_mode')->isEmpty()
      ? (string) $setup->get('visibility_mode')->value
      : 'public';
    if ($actualVisibility !== $expectedVisibility) {
      return FALSE;
    }
    $expectedDescription = (string) ($values['short_description'] ?? '');
    $actualDescription = $setup->hasField('short_description') && !$setup->get('short_description')->isEmpty()
      ? (string) $setup->get('short_description')->value
      : '';
    if ($actualDescription !== $expectedDescription) {
      return FALSE;
    }
    if ($setup->getTicketKind() === 'paid') {
      $actualPrice = $setup->toPriceValue();
      $expectedPrice = $values['price'] ?? [];
      return $actualPrice !== NULL
        && number_format((float) $actualPrice->getNumber(), 6, '.', '')
          === number_format((float) ($expectedPrice['number'] ?? 0), 6, '.', '')
        && $actualPrice->getCurrencyCode() === (string) ($expectedPrice['currency_code'] ?? '');
    }
    if ($setup->getTicketKind() === 'external') {
      $expectedUrl = (string) (($values['external_url'][0]['uri'] ?? $values['external_url']['uri'] ?? ''));
      return $setup->getExternalUrlString() === $expectedUrl;
    }
    return TRUE;
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
        if (!is_array($row)) {
          continue;
        }
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
        static fn (mixed $item): bool => !is_array($item) || (int) ($item['target_id'] ?? 0) !== $ticketId
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
        if (!is_array($row)) {
          continue;
        }
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
   * Duplicates a ticket type onto the same event as a draft copy.
   *
   * Copies the same organiser configuration as
   * {@see cloneFromReusableTemplate()} (waitlist, hidden checkout label,
   * group-sale settings, capacity windows, etc.). Commerce variation IDs are
   * never copied — sync creates a new variation for paid tiers. Best-value /
   * default flags are reset so organisers choose highlights intentionally.
   *
   * @throws \InvalidArgumentException
   */
  public function duplicateTicketOnEvent(
    NodeInterface $event,
    TicketTypeInterface $source,
    AccountInterface $account,
  ): TicketTypeInterface {
    if ($event->bundle() !== 'event') {
      throw new InvalidArgumentException('Duplicate target must be an event node.');
    }
    $sourceId = (int) $source->id();
    if ($sourceId < 1 || !$this->ticketBelongsToEvent($event, $sourceId)) {
      throw new InvalidArgumentException('Ticket does not belong to this event.');
    }

    $kind = $source->getTicketKind();
    if (!in_array($kind, ['paid', 'rsvp', 'external'], TRUE)) {
      throw new InvalidArgumentException('Unsupported ticket kind for duplicate.');
    }

    $values = [
      'title' => $this->buildDuplicateTicketTitle($source->getTitle()),
      'ticket_kind' => $kind,
      'vendor_id' => ['target_id' => (int) $account->id()],
      'is_reusable' => FALSE,
      'event' => ['target_id' => (int) $event->id()],
      'status' => 0,
      'lifecycle_status' => TicketTypeInterface::LIFECYCLE_ACTIVE,
      'field_is_best_value' => 0,
      'field_is_default_ticket' => 0,
      'commerce_variation' => NULL,
    ];

    if (!$source->get('capacity')->isEmpty()) {
      $values['capacity'] = (int) $source->get('capacity')->value;
    }
    if ($source->hasField('rsvp_limit') && !$source->get('rsvp_limit')->isEmpty()) {
      $values['rsvp_limit'] = (int) $source->get('rsvp_limit')->value;
    }
    if (!$source->get('sale_start')->isEmpty()) {
      $values['sale_start'] = $source->get('sale_start')->getValue();
    }
    if (!$source->get('sale_end')->isEmpty()) {
      $values['sale_end'] = $source->get('sale_end')->getValue();
    }

    if ($source->hasField('visibility_mode') && !$source->get('visibility_mode')->isEmpty()) {
      $values['visibility_mode'] = (string) $source->get('visibility_mode')->value;
    }
    if ($source->hasField('hidden_label') && !$source->get('hidden_label')->isEmpty()) {
      $values['hidden_label'] = (string) $source->get('hidden_label')->value;
    }
    if ($source->hasField('short_description') && !$source->get('short_description')->isEmpty()) {
      $values['short_description'] = (string) $source->get('short_description')->value;
    }
    if ($source->hasField('waitlist_enabled')) {
      $values['waitlist_enabled'] = $source->get('waitlist_enabled')->value ? 1 : 0;
    }
    if ($source->hasField('waitlist_capacity') && !$source->get('waitlist_capacity')->isEmpty()) {
      $values['waitlist_capacity'] = (int) $source->get('waitlist_capacity')->value;
    }
    if ($source->hasField('auto_promote_waitlist')) {
      $values['auto_promote_waitlist'] = $source->get('auto_promote_waitlist')->value ? 1 : 0;
    }
    if ($source->hasField('group_sale_mode') && !$source->get('group_sale_mode')->isEmpty()) {
      $values['group_sale_mode'] = (string) $source->get('group_sale_mode')->value;
    }
    if ($source->hasField('group_min_size') && !$source->get('group_min_size')->isEmpty()) {
      $values['group_min_size'] = (int) $source->get('group_min_size')->value;
    }
    if ($source->hasField('group_bundle_size') && !$source->get('group_bundle_size')->isEmpty()) {
      $values['group_bundle_size'] = (int) $source->get('group_bundle_size')->value;
    }

    if ($source->hasField('field_use_ticket_attendee_questions')) {
      $values['field_use_ticket_attendee_questions'] = $source->get('field_use_ticket_attendee_questions')->value ? 1 : 0;
    }
    if ($source->hasField('field_attendee_questions') && !$source->get('field_attendee_questions')->isEmpty()) {
      $refs = [];
      foreach ($source->get('field_attendee_questions')->referencedEntities() as $paragraph) {
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

    if ($kind === 'paid') {
      $price = $source->toPriceValue();
      if ($price === NULL || (float) $price->getNumber() <= 0) {
        throw new InvalidArgumentException('Paid tickets require a price greater than zero.');
      }
      $values['price'] = [
        'number' => $price->getNumber(),
        'currency_code' => $price->getCurrencyCode(),
      ];
    }
    else {
      $values['price'] = NULL;
    }

    if ($kind === 'external') {
      if ($source->get('external_url')->isEmpty()) {
        throw new InvalidArgumentException('External tickets require a valid https URL.');
      }
      $values['external_url'] = $source->get('external_url')->getValue();
    }

    return $this->createAttachAndSync($event, $values);
  }

  /**
   * Builds the duplicate ticket title or rejects names that cannot fit.
   *
   * @throws \InvalidArgumentException
   *   When appending the duplicate suffix would exceed the title max length.
   */
  public function buildDuplicateTicketTitle(string $sourceTitle): string {
    $base = trim($sourceTitle);
    if ($base === '') {
      throw new InvalidArgumentException('Ticket title is required.');
    }
    $title = $base . self::DUPLICATE_TITLE_SUFFIX;
    if (mb_strlen($title) > self::TICKET_TITLE_MAX_LENGTH) {
      throw new InvalidArgumentException(
        'This ticket name is too long to duplicate. Shorten the name, then try again.',
      );
    }
    return $title;
  }

  /**
   * Applies ticket field values in memory without saving.
   *
   * Used so duplicate can read same-request edits before the source is
   * persisted. Callers must still persist via {@see updateTicketType()}.
   *
   * @param array<string, mixed> $values
   */
  public function applyTicketValuesWithoutSave(TicketTypeInterface $ticket, array $values): void {
    if ($values === []) {
      return;
    }
    $this->applyValuesToTicket($ticket, $values);
  }

  /**
   * Applies source edits and creates a duplicate in one database transaction.
   *
   * Prevents partial outcomes where either the source saves without a copy or
   * the copy is created while same-request source edits are rolled back.
   *
   * @param array<string, mixed> $values
   *
   * @throws \InvalidArgumentException
   * @throws \Throwable
   */
  public function updateAndDuplicateTicketOnEvent(
    NodeInterface $event,
    TicketTypeInterface $ticket,
    AccountInterface $account,
    array $values,
  ): TicketTypeInterface {
    $this->applyTicketValuesWithoutSave($ticket, $values);
    $this->validateTicketTypeForPersist($ticket, $event);
    // Fail before any write when the copy title cannot be formed.
    $this->buildDuplicateTicketTitle($ticket->getTitle());

    $transaction = $this->database->startTransaction();
    try {
      // Persist source first so a duplicate failure rolls the source edits back
      // with the same transaction as the copy create/attach.
      $this->updateTicketType($ticket, $event, []);
      return $this->duplicateTicketOnEvent($event, $ticket, $account);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
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
   * @return array{
   *   allowed: bool,
   *   blockers: list<string>,
   *   counts: array<string, int>
   * }
   */
  public function evaluateTicketDeletion(TicketTypeInterface $ticket): array {
    return $this->ticketTierDeletionGuard->evaluate($ticket);
  }

  /**
   * Permanently deletes an unreferenced ticket and its unused variation.
   *
   * @throws \InvalidArgumentException
   * @throws \Throwable
   */
  public function deleteTicketOnEvent(NodeInterface $event, TicketTypeInterface $ticket): void {
    if (!$this->ticketBelongsToEvent($event, (int) $ticket->id())) {
      throw new InvalidArgumentException('Ticket data could not be matched to this event.');
    }

    $eligibility = $this->evaluateTicketDeletion($ticket);
    if (!$eligibility['allowed']) {
      throw new InvalidArgumentException('This ticket has booking or operational history and cannot be permanently deleted.');
    }

    $variation_id = $ticket->hasField('commerce_variation') && !$ticket->get('commerce_variation')->isEmpty()
      ? (int) $ticket->get('commerce_variation')->target_id
      : 0;

    $transaction = $this->database->startTransaction();
    try {
      $this->archiveTicketOnEvent($event, $ticket);

      if ($variation_id > 0) {
        $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
        $variation_storage->resetCache([$variation_id]);
        $variation = $variation_storage->load($variation_id);
        if ($variation instanceof ProductVariationInterface) {
          $product = $variation->getProduct();
          if ($product instanceof ProductInterface && method_exists($product, 'hasVariation')
            && $product->hasVariation($variation)) {
            $product->removeVariation($variation);
            $product->save();
          }
          $variation->delete();
        }
      }

      $ticket->delete();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
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
   * Lifecycle callers share this path so ticket input produces consistent
   * mel_ticket_type entity values.
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
    if (array_key_exists('sale_start', $values)) {
      $payload['sale_start'] = $this->normalizeTicketDateValue($values['sale_start']);
    }
    if (array_key_exists('sale_end', $values)) {
      $payload['sale_end'] = $this->normalizeTicketDateValue($values['sale_end']);
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
    if (!$this->accountCanManageTicketForEvent($event, $ticket, $account)) {
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
    if (array_key_exists('sale_start', $values)) {
      $payload['sale_start'] = $this->normalizeTicketDateValue($values['sale_start']);
    }
    if (array_key_exists('sale_end', $values)) {
      $payload['sale_end'] = $this->normalizeTicketDateValue($values['sale_end']);
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
   * Canonical persistence for Advanced Ticket Manager / embedded Event Studio rows.
   *
   * Writes Commerce variations, ensures mel_ticket_type rows and field_ticket_types,
   * then runs syncPaidTiers() so TicketTypeManager projection stays authoritative.
   *
   * @param array<string, mixed> $rows
   *   Same shape as EventTicketManagerForm `tickets` form values.
   *
   * @return array{ok: bool, messages: list<string>}
   */
  public function persistTicketManagerRows(NodeInterface $event, AccountInterface $account, array $rows): array {
    if ($event->bundle() !== 'event') {
      return ['ok' => FALSE, 'messages' => ['Invalid event.']];
    }

    $eventType = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';
    if (!in_array($eventType, ['paid', 'both'], TRUE)) {
      return ['ok' => TRUE, 'messages' => []];
    }

    $nid = (int) $event->id();
    if ($nid < 1) {
      return ['ok' => FALSE, 'messages' => ['Save the event before managing tickets.']];
    }

    $product = $this->loadTicketProductForManagerPersist($event);
    if (!$product instanceof ProductInterface) {
      $this->syncPaidTiers($event);
      $loadedEvent = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($loadedEvent instanceof NodeInterface) {
        $event = $loadedEvent;
      }
      $product = $this->loadTicketProductForManagerPersist($event);
      if (!$product instanceof ProductInterface) {
        $this->loggerFactory->get('myeventlane_event')->error(
          'persistTicketManagerRows could not create a ticket product for event @nid.',
          ['@nid' => (string) $nid],
        );
        return ['ok' => FALSE, 'messages' => ['Tickets not set up. Link a ticket before saving tickets.']];
      }
    }

    $row_metas = [];

    try {
      $existing_variations = $this->indexProductVariationsById($product);
      $used_ids = [];

      foreach ($rows as $row_key => $row) {
        if (!is_array($row) || !empty($row['more']['delete']) || !$this->managerRowHasInput($row)) {
          continue;
        }

        $variation_id = $this->managerSubmittedVariationId($row_key, $row);
        if ($variation_id > 0) {
          if (!isset($existing_variations[$variation_id])) {
            $this->loggerFactory->get('myeventlane_event')->error(
              'persistTicketManagerRows: variation @vid not on product @pid for event @nid.',
              [
                '@vid' => (string) $variation_id,
                '@pid' => (string) $product->id(),
                '@nid' => (string) $nid,
              ],
            );
            return ['ok' => FALSE, 'messages' => ['Ticket data could not be matched to this event. Reload the page and try again.']];
          }
          $variation = $existing_variations[$variation_id];
          $row['title'] = $this->resolveManagerRowTitle($row, $variation);
          if ($row['title'] === '') {
            $this->loggerFactory->get('myeventlane_event')->warning(
              'persistTicketManagerRows: rejected existing ticket row @vid without a name for event @nid.',
              [
                '@vid' => (string) $variation_id,
                '@nid' => (string) $nid,
              ],
            );
            return ['ok' => FALSE, 'messages' => ['Ticket name is required.']];
          }
        }
        else {
          $title = trim((string) ($row['title'] ?? ''));
          if ($title === '') {
            $this->loggerFactory->get('myeventlane_event')->warning(
              'persistTicketManagerRows: rejected new ticket row without a name for event @nid.',
              ['@nid' => (string) $nid],
            );
            return ['ok' => FALSE, 'messages' => ['Ticket name is required.']];
          }
          $variation = ProductVariation::create([
            'type' => 'ticket_variation',
            'sku' => $this->generateTicketManagerSku($event, $title),
            'title' => $title,
            'price' => new Price('0.00', (string) ($row['currency'] ?? 'AUD')),
            'status' => 1,
            'product_id' => $product->id(),
          ]);
        }

        $this->applyManagerRowToVariation($variation, $row, $event);
        $variation->save();
        if (method_exists($product, 'hasVariation') && !$product->hasVariation($variation)) {
          $product->addVariation($variation);
        }
        $vid = (int) $variation->id();
        $used_ids[] = $vid;
        $row_metas[] = [
          'row' => $row,
          'variation_id' => $vid,
        ];
      }

      $removed_ids = array_diff(array_keys($existing_variations), $used_ids);
      foreach ($removed_ids as $removed_id) {
        $removed_variation = $existing_variations[(int) $removed_id] ?? NULL;
        if (!$removed_variation instanceof ProductVariationInterface) {
          continue;
        }
        $tier = $this->loadTierByVariationOnEvent($event, (int) $removed_variation->id());
        if ($tier instanceof TicketTypeInterface) {
          $this->archiveTicketOnEvent($event, $tier);
        }
        if (method_exists($product, 'removeVariation')) {
          $product->removeVariation($removed_variation);
        }
        $removed_variation->delete();
      }

      $product->save();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'persistTicketManagerRows Commerce phase failed for event @nid: @message',
        ['@nid' => (string) $nid, '@message' => $e->getMessage()],
      );
      return ['ok' => FALSE, 'messages' => ['Tickets could not be saved. Please check the details and try again.']];
    }

    $loadedEvent = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$loadedEvent instanceof NodeInterface) {
      return ['ok' => FALSE, 'messages' => ['Event could not be reloaded after saving tickets.']];
    }
    $event = $loadedEvent;

    $freshProduct = $this->loadTicketProductForManagerPersist($event);
    if (!$freshProduct instanceof ProductInterface) {
      return ['ok' => FALSE, 'messages' => ['Tickets not set up after save.']];
    }

    $ordered_tier_ids = [];

    try {
      foreach ($row_metas as $meta) {
        $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($meta['variation_id']);
        if (!$variation instanceof ProductVariationInterface) {
          $this->loggerFactory->get('myeventlane_event')->error(
            'persistTicketManagerRows: variation @vid missing after save for event @nid.',
            ['@vid' => (string) $meta['variation_id'], '@nid' => (string) $nid],
          );
          return ['ok' => FALSE, 'messages' => ['Ticket rows could not be linked to Commerce variations. Reload and try again.']];
        }
        $ordered_tier_ids[] = $this->ensurePaidTierForManagerVariation($event, $account, $variation, $meta['row']);
      }

      $reloaded = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($reloaded instanceof NodeInterface) {
        $this->applyMergedTicketTypesFromManager($reloaded, $freshProduct, $ordered_tier_ids);
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_event')->error(
        'persistTicketManagerRows tier phase failed for event @nid: @message',
        ['@nid' => (string) $nid, '@message' => $e->getMessage()],
      );
      return ['ok' => FALSE, 'messages' => ['Tickets saved in Commerce but canonical ticket types could not be updated. Contact support if this persists.']];
    }

    $this->loggerFactory->get('myeventlane_event')->info(
      'persistTicketManagerRows completed for event @nid (@count tiers).',
      ['@nid' => (string) $nid, '@count' => (string) count($ordered_tier_ids)],
    );

    return ['ok' => TRUE, 'messages' => []];
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
    $inverseAllIds = [];
    $inversePublishedIds = [];
    foreach ($ticketStorage->loadMultiple($candidateIds) as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      $tid = (int) $ticket->id();
      $inverseAllIds[] = $tid;
      if ($ticket->isPublished()) {
        $inversePublishedIds[] = $tid;
      }
    }

    if ($inverseAllIds === []) {
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

    $inverseAllSet = array_flip($inverseAllIds);
    $merged = [];
    foreach ($fieldIds as $id) {
      if (isset($inverseAllSet[$id])) {
        $merged[] = $id;
      }
    }
    foreach ($inversePublishedIds as $id) {
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
    if (!$this->accountCanManageTicketForEvent($event, $entity, $account)) {
      return NULL;
    }
    return $entity;
  }

  private function accountCanManageTicketForEvent(NodeInterface $event, TicketTypeInterface $ticket, AccountInterface $account): bool {
    if ($account->hasPermission('administer mel_ticket_type entities')) {
      return TRUE;
    }
    if ((int) $ticket->get('vendor_id')->target_id === (int) $account->id()) {
      return TRUE;
    }
    return $this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account);
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
      $published = (bool) (int) $values['status'];
      if ($ticket instanceof EntityPublishedInterface) {
        if ($published) {
          $ticket->setPublished();
        }
        else {
          $ticket->setUnpublished();
        }
      }
      else {
        $ticket->set('status', $published ? 1 : 0);
      }
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

  private function normalizeTicketDateValue(mixed $value): ?string {
    if ($value instanceof DrupalDateTime) {
      return $value->format('Y-m-d\TH:i:s');
    }
    if (is_array($value) && isset($value['date'], $value['time'])) {
      $raw = trim((string) $value['date'] . ' ' . (string) $value['time']);
      if ($raw === '') {
        return NULL;
      }
      return (new DrupalDateTime($raw))->format('Y-m-d\TH:i:s');
    }
    $raw = trim((string) $value);
    if ($raw === '') {
      return NULL;
    }
    return (new DrupalDateTime($raw))->format('Y-m-d\TH:i:s');
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

  private function loadTicketProductForManagerPersist(NodeInterface $event): ?ProductInterface {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return NULL;
    }
    $product = $event->get('field_product_target')->entity;
    return $product instanceof ProductInterface ? $product : NULL;
  }

  /**
   * @return array<int, \Drupal\commerce_product\Entity\ProductVariationInterface>
   */
  private function indexProductVariationsById(ProductInterface $product): array {
    $indexed = [];
    foreach ($product->getVariations() as $variation) {
      if ($variation instanceof ProductVariationInterface && $variation->id() !== NULL) {
        $indexed[(int) $variation->id()] = $variation;
      }
    }
    return $indexed;
  }

  /**
   * @param array<string, mixed> $values
   */
  private function managerRowHasInput(array $values): bool {
    $price = trim((string) ($values['price'] ?? ''));
    return trim((string) ($values['title'] ?? '')) !== ''
      || ($price !== '' && is_numeric($price) && (float) $price > 0)
      || !empty($values['best_value']);
  }

  /**
   * @param int|string $row_key
   * @param array<string, mixed> $row
   */
  public function managerSubmittedVariationId(int|string $row_key, array $row): int {
    $submitted_id = (int) ($row['variation_id'] ?? 0);
    if ($submitted_id > 0) {
      return $submitted_id;
    }
    return is_numeric($row_key) ? (int) $row_key : 0;
  }

  private function generateTicketManagerSku(NodeInterface $event, string $title): string {
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/', '-', $title));
    $slug = trim($slug, '-') ?: 'ticket';
    return 'ticket-' . (int) $event->id() . '-' . $slug . '-' . (string) time();
  }

  /**
   * @param array<string, mixed> $values
   */
  private function applyManagerRowToVariation(ProductVariationInterface $variation, array $values, NodeInterface $event): void {
    $variation->setTitle($this->resolveManagerRowTitle($values, $variation));
    $variation->setPrice(new Price($this->normalizeManagerPriceNumber($values['price'] ?? '0'), (string) ($values['currency'] ?? 'AUD')));

    if ($variation->hasField('field_best_value')) {
      $variation->set('field_best_value', !empty($values['best_value']) ? 1 : 0);
    }
    $more = isset($values['more']) && is_array($values['more']) ? $values['more'] : [];
    if ($variation->hasField('field_capacity')) {
      $capacity = trim((string) ($more['capacity'] ?? ''));
      $variation->set('field_capacity', $capacity === '' ? NULL : (int) $capacity);
    }
    if ($variation->hasField('field_limit_per_order')) {
      $limit_per_order = trim((string) ($more['limit_per_order'] ?? ''));
      $variation->set('field_limit_per_order', $limit_per_order === '' ? NULL : (int) $limit_per_order);
    }
    if ($variation->hasField('field_show_remaining')) {
      $variation->set('field_show_remaining', !empty($more['show_remaining']) ? 1 : 0);
    }
    if ($variation->hasField('field_collect_questions')) {
      $variation->set('field_collect_questions', !empty($more['collect_questions']) ? 1 : 0);
    }
    if ($variation->hasField('field_ticket_start')) {
      $variation->set('field_ticket_start', $this->normalizeManagerSubmittedDate($more['ticket_start'] ?? NULL));
    }
    if ($variation->hasField('field_ticket_end')) {
      $variation->set('field_ticket_end', $this->normalizeManagerSubmittedDate($more['ticket_end'] ?? NULL));
    }
    if ($variation->hasField('field_event')) {
      $variation->set('field_event', ['target_id' => $event->id()]);
    }

    $variationForDefault = $variation->id() !== NULL ? $variation : NULL;
    $active = $this->managerRowIsActive($values, $variationForDefault);
    $this->applyManagerRowActiveToVariation($variation, $active);
  }

  /**
   * Keeps existing ticket names when a nested manager submission omits the text input.
   *
   * @param array<string, mixed> $values
   */
  public function resolveManagerRowTitle(array $values, ?ProductVariationInterface $variation = NULL): string {
    $title = trim((string) ($values['title'] ?? ''));
    if ($title !== '') {
      return $title;
    }
    if ($variation instanceof ProductVariationInterface) {
      $existing = trim((string) $variation->getTitle());
      if ($existing !== '') {
        return $existing;
      }
    }
    return '';
  }

  /**
   * Whether the ticket manager row marks the tier as active (published).
   *
   * Missing `active` in POST uses the existing variation publish state, or TRUE
   * for new rows so paid tiers default to on-sale unless explicitly turned off.
   */
  public function managerRowIsActive(array $row, ?ProductVariationInterface $variation = NULL): bool {
    if (array_key_exists('active', $row)) {
      return !empty($row['active']);
    }
    if ($variation instanceof ProductVariationInterface && $variation->id() !== NULL) {
      return $variation->isPublished();
    }
    return TRUE;
  }

  /**
   * TRUE when a paid row is non-deleted, has input, is active, and has price &gt; 0.
   */
  public function managerPaidRowIsSellable(array $row, ?ProductVariationInterface $variation = NULL): bool {
    if (!is_array($row) || !empty($row['more']['delete'])) {
      return FALSE;
    }
    if (!$this->managerRowHasInput($row)) {
      return FALSE;
    }
    if (!$this->managerRowIsActive($row, $variation)) {
      return FALSE;
    }
    $price = trim((string) ($row['price'] ?? ''));
    return $price !== '' && is_numeric($price) && (float) $price > 0;
  }

  private function applyManagerRowActiveToVariation(ProductVariationInterface $variation, bool $active): void {
    if ($variation instanceof EntityPublishedInterface) {
      if ($active) {
        if (!$variation->isPublished()) {
          $variation->setPublished();
        }
      }
      elseif ($variation->isPublished()) {
        $variation->setUnpublished();
      }
    }
    elseif ($variation->hasField('status')) {
      $variation->set('status', $active ? 1 : 0);
    }
  }

  private function normalizeManagerPriceNumber(mixed $value): string {
    $number = is_numeric($value) ? (float) $value : 0.0;
    return number_format(max(0.0, $number), 2, '.', '');
  }

  private function normalizeManagerSubmittedDate(mixed $value): ?string {
    if ($value instanceof DrupalDateTime) {
      return $value->format('Y-m-d\TH:i:s');
    }
    if (is_array($value) && isset($value['date'], $value['time'])) {
      $raw = trim((string) $value['date'] . ' ' . (string) $value['time']);
      if ($raw !== '') {
        return (new DrupalDateTime($raw))->format('Y-m-d\TH:i:s');
      }
    }
    $raw = trim((string) $value);
    return $raw === '' ? NULL : (new DrupalDateTime($raw))->format('Y-m-d\TH:i:s');
  }

  private function loadTierByVariationOnEvent(NodeInterface $event, int $variationId): ?TicketTypeInterface {
    if (!$this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      return NULL;
    }
    $eventId = (int) $event->id();
    if ($eventId < 1 || $variationId < 1) {
      return NULL;
    }
    $ids = $this->entityTypeManager->getStorage('mel_ticket_type')->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('commerce_variation', $variationId)
      ->range(0, 2)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    if (count($ids) > 1) {
      $this->loggerFactory->get('myeventlane_event')->warning(
        'Multiple mel_ticket_type rows reference commerce_variation @vid on event @nid; using lowest id.',
        ['@vid' => (string) $variationId, '@nid' => (string) $eventId],
      );
    }
    sort($ids, SORT_NUMERIC);
    $tid = (int) reset($ids);
    $ticket = $this->entityTypeManager->getStorage('mel_ticket_type')->load($tid);
    return $ticket instanceof TicketTypeInterface ? $ticket : NULL;
  }

  /**
   * @param array<string, mixed> $row
   */
  private function ensurePaidTierForManagerVariation(NodeInterface $event, AccountInterface $account, ProductVariationInterface $variation, array $row): int {
    $tier = $this->loadTierByVariationOnEvent($event, (int) $variation->id());
    $price = $variation->getPrice();
    if ($price === NULL) {
      $price = new Price('0.00', 'AUD');
    }

    $published = $this->managerRowIsActive($row, $variation);
    $values = [
      'title' => trim((string) $variation->getTitle()),
      'price' => [
        'number' => $price->getNumber(),
        'currency_code' => $price->getCurrencyCode(),
      ],
      'status' => $published ? 1 : 0,
      'field_is_best_value' => !empty($row['best_value']),
    ];

    if ($tier instanceof TicketTypeInterface) {
      $this->applyValuesToTicket($tier, $values);
      if ($tier->get('commerce_variation')->isEmpty() || (int) $tier->get('commerce_variation')->target_id !== (int) $variation->id()) {
        $tier->set('commerce_variation', ['target_id' => (int) $variation->id()]);
      }
      if ($tier->hasField('event') && ($tier->get('event')->isEmpty() || (int) $tier->get('event')->target_id !== (int) $event->id())) {
        $tier->set('event', ['target_id' => (int) $event->id()]);
      }
      $this->syncTierCapacityFromVariation($tier, $variation);
      $this->validateTicketTypeForPersist($tier, $event);
      $tier->save();
      return (int) $tier->id();
    }

    /** @var \Drupal\mel_ticket\Entity\TicketTypeInterface $tier */
    $tier = $this->entityTypeManager->getStorage('mel_ticket_type')->create([
      'title' => trim((string) $variation->getTitle()) !== '' ? trim((string) $variation->getTitle()) : 'Ticket',
      'ticket_kind' => 'paid',
      'vendor_id' => ['target_id' => (int) $account->id()],
      'event' => ['target_id' => (int) $event->id()],
      'status' => $this->managerRowIsActive($row, $variation) ? 1 : 0,
      'lifecycle_status' => TicketTypeInterface::LIFECYCLE_ACTIVE,
      'is_reusable' => FALSE,
      'price' => [
        'number' => $price->getNumber(),
        'currency_code' => $price->getCurrencyCode(),
      ],
      'commerce_variation' => ['target_id' => (int) $variation->id()],
    ]);
    if ($tier->hasField('field_is_best_value')) {
      $tier->set('field_is_best_value', !empty($row['best_value']));
    }
    $this->syncTierCapacityFromVariation($tier, $variation);
    $this->validateTicketTypeForPersist($tier, $event);
    $tier->save();

    return (int) $tier->id();
  }

  private function syncTierCapacityFromVariation(TicketTypeInterface $ticket, ProductVariationInterface $variation): void {
    if (!$ticket->hasField('capacity') || !$variation->hasField('field_capacity')) {
      return;
    }
    if ($variation->get('field_capacity')->isEmpty()) {
      $ticket->set('capacity', NULL);
      return;
    }
    $ticket->set('capacity', (int) $variation->get('field_capacity')->value);
  }

  /**
   * @param list<int> $orderedPaidTierIds
   */
  private function applyMergedTicketTypesFromManager(NodeInterface $event, ProductInterface $product, array $orderedPaidTierIds): void {
    if (!$event->hasField('field_ticket_types')) {
      return;
    }

    $eventType = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';

    if ($eventType === 'paid') {
      $merged = array_values(array_unique(array_map('intval', $orderedPaidTierIds)));
    }
    else {
      $preserved = $this->preservedTicketTypeIdsForManagerMerge($event, $product);
      $merged = array_merge($preserved, $orderedPaidTierIds);
      $merged = array_values(array_unique(array_map('intval', $merged)));
    }

    $event->set(
      'field_ticket_types',
      array_map(static fn (int $id): array => ['target_id' => $id], $merged),
    );
    EventNodeRevisionSave::prepare($event, 'Ticket manager: synced canonical ticket types.');
    $event->save();
    $this->ticketTypeManager->normalizeDefaultTicketSelection($event);
    $this->ticketTypeManager->normalizeBestValueTicketSelection($event);
    $this->syncPaidTiers($event);
  }

  /**
   * Preserves non–ticket-product paid tiers and all non-paid tiers when merging manager output on hybrid events.
   *
   * @return list<int>
   */
  private function preservedTicketTypeIdsForManagerMerge(NodeInterface $event, ProductInterface $product): array {
    $productId = (int) $product->id();
    $preserved = [];
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return $preserved;
    }

    foreach ($event->get('field_ticket_types')->getValue() as $row) {
      $tid = (int) ($row['target_id'] ?? 0);
      if ($tid < 1) {
        continue;
      }
      $t = $this->entityTypeManager->getStorage('mel_ticket_type')->load($tid);
      if (!$t instanceof TicketTypeInterface || $t->isArchived()) {
        continue;
      }
      if ($t->getTicketKind() !== 'paid') {
        $preserved[] = $tid;
        continue;
      }
      if ($t->get('commerce_variation')->isEmpty()) {
        $preserved[] = $tid;
        continue;
      }
      $v = $t->get('commerce_variation')->entity;
      if (!$v instanceof ProductVariationInterface) {
        $preserved[] = $tid;
        continue;
      }
      if ((int) $v->getProductId() !== $productId) {
        $preserved[] = $tid;
        continue;
      }
    }

    return $preserved;
  }

}
