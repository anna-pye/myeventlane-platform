<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles post-checkout processing for ticket orders.
 *
 * Creates attendee records and syncs holder answers into roster rows.
 * Operational PDFs and wallet passes are issued only from canonical ticket
 * entities (see TicketIssuer on ORDER_PAID); this subscriber does not generate
 * those artifacts.
 */
final class OrderCompletedSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs OrderCompletedSubscriber.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Listen to order completion (when order transitions to 'completed').
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace', -100],
    ];
  }

  /**
   * Responds to order placement.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $this->repairOrderAttendees($order);
  }

  /**
   * Ensures ticket attendee records exist for each order item quantity.
   *
   * This can be called for both fresh orders and legacy repair runs.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to inspect.
   */
  public function repairOrderAttendees(OrderInterface $order): void {
    $logger = $this->loggerFactory->get('myeventlane_commerce');

    $orderId = (int) $order->id();

    $logger->info('Processing order @order_id after placement.', [
      '@order_id' => $order->id(),
      'order_id' => $orderId,
    ]);

    // Process each order item.
    foreach ($order->getItems() as $orderItem) {
      if ($orderItem instanceof OrderItemInterface) {
        $this->processOrderItem($orderItem, $order);
      }
    }

    $logger->info('Completed processing for order @order_id.', [
      '@order_id' => $order->id(),
      'order_id' => $orderId,
    ]);
  }

  /**
   * Processes a single order item (ticket).
   */
  private function processOrderItem(OrderItemInterface $orderItem, OrderInterface $order): void {
    $logger = $this->loggerFactory->get('myeventlane_commerce');
    $orderId = method_exists($order, 'id') ? (int) $order->id() : NULL;
    $orderItemId = method_exists($orderItem, 'id') ? (int) $orderItem->id() : NULL;

    // Exclude Boost: it is an admin product, not a vendor event attendee.
    if (method_exists($orderItem, 'bundle') && $orderItem->bundle() === 'boost') {
      return;
    }

    // Get event from order item.
    if (!$orderItem->hasField('field_target_event') || $orderItem->get('field_target_event')->isEmpty()) {
      // Fallback: try to get event from product variation.
      $purchasedEntity = $orderItem->getPurchasedEntity();
      if ($purchasedEntity && $purchasedEntity->hasField('field_event') && !$purchasedEntity->get('field_event')->isEmpty()) {
        $eventId = (int) $purchasedEntity->get('field_event')->target_id;
      }
      else {
        $logger->warning('Order item @id has no event reference. Skipping.', [
          '@id' => $orderItem->id(),
          'order_id' => $orderId,
          'event_id' => NULL,
          'order_item_id' => $orderItemId,
        ]);
        return;
      }
    }
    else {
      $eventId = (int) $orderItem->get('field_target_event')->target_id;
    }

    // Get ticket holders (attendee info) from paragraphs.
    if (!$orderItem->hasField('field_ticket_holder') || $orderItem->get('field_ticket_holder')->isEmpty()) {
      $logger->warning('Order item @id has no ticket holder info. Skipping.', [
        '@id' => $orderItem->id(),
        'order_id' => $orderId,
        'event_id' => $eventId,
        'order_item_id' => $orderItemId,
      ]);
      return;
    }

    /** @var \Drupal\paragraphs\ParagraphInterface[] $ticketHolders */
    $ticketHolders = $orderItem->get('field_ticket_holder')->referencedEntities();
    $purchasedEntity = $orderItem->getPurchasedEntity();
    $variationTitle = $purchasedEntity ? $purchasedEntity->label() : 'Unknown';
    $price = $orderItem->getTotalPrice();
    $isRsvp = $price && ((float) $price->getNumber() === 0.0);

    if ($isRsvp) {
      $this->processRsvpOrderItemLegacy($orderItem, $order, $eventId, $ticketHolders, $variationTitle, $logger, $orderId, $orderItemId);
      return;
    }

    $this->processPaidTicketOrderItem($orderItem, $order, $eventId, $ticketHolders, $variationTitle, $logger, $orderId, $orderItemId);
  }

  /**
   * RSVP ($0) path: preserve legacy quantity-based sync and email ordering.
   */
  private function processRsvpOrderItemLegacy(
    OrderItemInterface $orderItem,
    OrderInterface $order,
    int $eventId,
    array $ticketHolders,
    string $variationTitle,
    LoggerInterface $logger,
    ?int $orderId,
    ?int $orderItemId,
  ): void {
    $expectedQuantity = max(0, (int) $orderItem->getQuantity());
    $source = EventAttendee::SOURCE_RSVP;
    $existingAttendeeCount = $this->countExistingAttendees($eventId, (int) $orderItem->id(), $source);

    if (count($ticketHolders) < $expectedQuantity) {
      $logger->error('Order item @id has fewer ticket holders than quantity. order_id=@order_id event_id=@event_id quantity=@quantity holders=@holders existing_attendees=@existing', [
        '@id' => $orderItem->id(),
        '@order_id' => (string) ($orderId ?? ''),
        '@event_id' => $eventId,
        '@quantity' => $expectedQuantity,
        '@holders' => count($ticketHolders),
        '@existing' => $existingAttendeeCount,
        'order_id' => $orderId,
        'event_id' => $eventId,
        'order_item_id' => $orderItemId,
      ]);
    }

    if ($existingAttendeeCount >= $expectedQuantity) {
      $logger->info('Skipping attendee creation for order item @id because attendee count already matches quantity.', [
        '@id' => $orderItem->id(),
        'order_id' => $orderId,
        'event_id' => $eventId,
        'order_item_id' => $orderItemId,
      ]);
      return;
    }

    $attendeeData = $this->loadOrderItemAttendeeDataJson($orderItem);
    $fallbackHolder = $ticketHolders[array_key_last($ticketHolders)] ?? NULL;
    if (!$fallbackHolder) {
      $logger->error('Order item @id could not backfill missing attendees because no holder paragraph exists.', [
        '@id' => $orderItem->id(),
        'order_id' => $orderId,
        'event_id' => $eventId,
        'order_item_id' => $orderItemId,
      ]);
      return;
    }

    for ($ticketOffset = $existingAttendeeCount; $ticketOffset < $expectedQuantity; $ticketOffset++) {
      $holder = $ticketHolders[$ticketOffset] ?? $fallbackHolder;
      if (!$holder) {
        $logger->error('Order item @id could not resolve holder for ticket offset @offset.', [
          '@id' => $orderItem->id(),
          '@offset' => $ticketOffset,
          'order_id' => $orderId,
          'event_id' => $eventId,
          'order_item_id' => $orderItemId,
        ]);
        return;
      }

      $accessibilityNeeds = $this->extractAccessibilityNeedsForTicket($attendeeData, $ticketOffset);
      $this->createAttendeeRecord(
        $holder,
        $eventId,
        $order,
        $orderItem,
        TRUE,
        $variationTitle,
        $accessibilityNeeds,
        rsvpLegacyEmailOrder: TRUE,
        logExtraDataDiagnostics: FALSE,
      );
    }
  }

  /**
   * Paid ticket path: one attendee per stored holder paragraph; quantity mismatch logged.
   */
  private function processPaidTicketOrderItem(
    OrderItemInterface $orderItem,
    OrderInterface $order,
    int $eventId,
    array $ticketHolders,
    string $variationTitle,
    LoggerInterface $logger,
    ?int $orderId,
    ?int $orderItemId,
  ): void {
    $quantity = max(0, (int) $orderItem->getQuantity());
    $paragraphCount = count($ticketHolders);
    $source = EventAttendee::SOURCE_TICKET;
    $existingAttendeeCount = $this->countExistingAttendees($eventId, (int) $orderItem->id(), $source);

    $mismatchQuantityVsParagraphs = $quantity !== $paragraphCount;
    if ($mismatchQuantityVsParagraphs) {
      $logger->warning('Order item @id: ticket quantity (@qty) does not match holder paragraph count (@pc). order_id=@order_id event_id=@event_id', [
        '@id' => $orderItem->id(),
        '@qty' => $quantity,
        '@pc' => $paragraphCount,
        '@order_id' => (string) ($orderId ?? ''),
        'order_id' => $orderId,
        'event_id' => $eventId,
        'order_item_id' => $orderItemId,
        'quantity' => $quantity,
        'paragraph_count' => $paragraphCount,
      ]);
    }

    // When quantity exceeds paragraphs, retain resilient extra slots using last holder (explicitly logged).
    $fallbackHolder = $ticketHolders[array_key_last($ticketHolders)] ?? NULL;
    if (!$fallbackHolder instanceof ParagraphInterface) {
      $logger->error('Order item @id: paid ticket sync requires at least one holder paragraph.', [
        '@id' => $orderItem->id(),
        'order_id' => $orderId,
        'event_id' => $eventId,
        'order_item_id' => $orderItemId,
      ]);
      return;
    }

    $totalSlots = $quantity > $paragraphCount ? $quantity : $paragraphCount;

    $attendeeData = $this->loadOrderItemAttendeeDataJson($orderItem);
    $createdThisRun = 0;
    $purchaserFallbackUsed = FALSE;
    $mismatchFallbackPathUsed = FALSE;

    if ($existingAttendeeCount >= $totalSlots) {
      $logger->info('Skipping paid attendee creation for order item @id; attendee count (@existing) already covers total slots (@slots).', [
        '@id' => $orderItem->id(),
        '@existing' => $existingAttendeeCount,
        '@slots' => $totalSlots,
        'order_id' => $orderId,
        'event_id' => $eventId,
        'order_item_id' => $orderItemId,
      ]);
    }
    else {
    for ($slotIndex = $existingAttendeeCount; $slotIndex < $totalSlots; $slotIndex++) {
      $isMismatchFallbackSlot = $slotIndex >= $paragraphCount;
      if ($isMismatchFallbackSlot) {
        $mismatchFallbackPathUsed = TRUE;
        $logger->notice('TEMP_DEBUG attendee_sync: mismatch_fallback_duplication order_id=@oid order_item_id=@oiid slot=@slot using_last_holder_paragraph (qty @qty > paragraphs @pc)', [
          '@oid' => (string) ($orderId ?? ''),
          '@oiid' => (string) ($orderItemId ?? ''),
          '@slot' => (string) $slotIndex,
          '@qty' => (string) $quantity,
          '@pc' => (string) $paragraphCount,
          'order_id' => $orderId,
          'order_item_id' => $orderItemId,
          'slot_index' => $slotIndex,
          'quantity' => $quantity,
          'paragraph_count' => $paragraphCount,
        ]);
        $holder = $fallbackHolder;
      }
      else {
        $holder = $ticketHolders[$slotIndex] ?? $fallbackHolder;
      }

      if (!$holder instanceof ParagraphInterface) {
        $logger->error('Order item @id: could not resolve holder for paid slot @slot.', [
          '@id' => $orderItem->id(),
          '@slot' => $slotIndex,
          'order_id' => $orderId,
          'event_id' => $eventId,
          'order_item_id' => $orderItemId,
        ]);
        break;
      }

      $accessibilityNeeds = $this->extractAccessibilityNeedsForTicket($attendeeData, $slotIndex);
      $usedPurchaserFallback = $this->createAttendeeRecord(
        $holder,
        $eventId,
        $order,
        $orderItem,
        FALSE,
        $variationTitle,
        $accessibilityNeeds,
        rsvpLegacyEmailOrder: FALSE,
        logExtraDataDiagnostics: TRUE,
      );
      if ($usedPurchaserFallback) {
        $purchaserFallbackUsed = TRUE;
      }
      $createdThisRun++;
    }
    }

    // Always refresh extra_data from holder paragraphs (fixes empty extra_data when
    // attendees were created before extraction ran, or checkout saved answers later).
    $this->syncPaidTicketExtraDataFromHolders(
      $orderItem,
      $eventId,
      $ticketHolders,
      $logger,
      $orderId,
      $orderItemId,
    );

    $this->logTempDebugAttendeeSyncSummary(
      $logger,
      $orderId,
      $orderItemId,
      $quantity,
      $paragraphCount,
      $createdThisRun,
      $purchaserFallbackUsed,
      $mismatchFallbackPathUsed,
    );
  }

  /**
   * Copies structured extra answers from holder paragraphs onto ticket attendees.
   *
   * Attendees are matched to holders by creation order (id ASC) vs paragraph
   * delta order — the same assumption used when attendees were first created.
   *
   * @return int
   *   Number of event_attendee rows updated.
   */
  private function syncPaidTicketExtraDataFromHolders(
    OrderItemInterface $orderItem,
    int $eventId,
    array $ticketHolders,
    LoggerInterface $logger,
    ?int $orderId,
    ?int $orderItemId,
  ): int {
    $orderItemIdInt = (int) $orderItem->id();
    if ($orderItemIdInt <= 0 || $eventId <= 0) {
      return 0;
    }

    $ids = $this->entityTypeManager
      ->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('order_item', $orderItemIdInt)
      ->condition('source', EventAttendee::SOURCE_TICKET)
      ->sort('id', 'ASC')
      ->execute();

    if (!$ids) {
      return 0;
    }

    /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee[] $attendees */
    $attendees = array_values($this->entityTypeManager->getStorage('event_attendee')->loadMultiple($ids));
    $updated = 0;
    $max = min(count($attendees), count($ticketHolders));

    for ($i = 0; $i < $max; $i++) {
      $holder = $ticketHolders[$i] ?? NULL;
      if (!$holder instanceof ParagraphInterface) {
        continue;
      }
      $attendee = $attendees[$i] ?? NULL;
      if (!$attendee instanceof EventAttendee) {
        continue;
      }

      $extracted = $this->extractExtraDataFromHolder(
        $holder,
        $logger,
        $orderId,
        $orderItemId,
        FALSE,
        TRUE,
      );

      $current = $attendee->getExtraDataMap();

      if ($extracted == $current) {
        continue;
      }

      $attendee->set('extra_data', $extracted);
      $attendee->save();
      $updated++;
      $logger->info('Synced extra_data onto event_attendee @aid from holder paragraph @hid (order_item @oi).', [
        '@aid' => (string) $attendee->id(),
        '@hid' => (string) ($holder->id() ?? 'new'),
        '@oi' => (string) $orderItemIdInt,
        'event_attendee_id' => (int) $attendee->id(),
        'holder_paragraph_id' => $holder->id(),
        'order_item_id' => $orderItemIdInt,
        'order_id' => $orderId,
      ]);
    }

    if ($updated > 0) {
      $logger->notice(
        'TEMP_DEBUG attendee_questions: backfilled extra_data for @n event_attendee row(s) order_id=@oid order_item_id=@oiid',
        [
          '@n' => (string) $updated,
          '@oid' => (string) ($orderId ?? ''),
          '@oiid' => (string) ($orderItemId ?? ''),
          'order_id' => $orderId,
          'order_item_id' => $orderItemId,
          'updated_count' => $updated,
        ]
      );
    }

    return $updated;
  }

  /**
   * Logs a single structured TEMP_DEBUG line for paid ticket attendee sync.
   */
  private function logTempDebugAttendeeSyncSummary(
    LoggerInterface $logger,
    ?int $orderId,
    ?int $orderItemId,
    int $quantity,
    int $paragraphCount,
    int $createdAttendeeCount,
    bool $purchaserEmailFallbackUsed,
    bool $mismatchFallbackPathUsed,
  ): void {
    $logger->notice(
      'TEMP_DEBUG attendee_sync: order_id=@oid order_item_id=@oiid quantity=@qty holder_paragraph_count=@pc created_attendee_count=@created purchaser_email_fallback=@pfb mismatch_fallback_path=@mfb',
      [
        '@oid' => (string) ($orderId ?? ''),
        '@oiid' => (string) ($orderItemId ?? ''),
        '@qty' => (string) $quantity,
        '@pc' => (string) $paragraphCount,
        '@created' => (string) $createdAttendeeCount,
        '@pfb' => $purchaserEmailFallbackUsed ? '1' : '0',
        '@mfb' => $mismatchFallbackPathUsed ? '1' : '0',
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
        'quantity' => $quantity,
        'holder_paragraph_count' => $paragraphCount,
        'created_attendee_count' => $createdAttendeeCount,
        'purchaser_email_fallback_used' => $purchaserEmailFallbackUsed,
        'mismatch_fallback_path_used' => $mismatchFallbackPathUsed,
      ]
    );
  }

  /**
   * @return array<string, mixed>
   */
  private function loadOrderItemAttendeeDataJson(OrderItemInterface $orderItem): array {
    $attendeeData = [];
    if ($orderItem->hasField('field_attendee_data') && !$orderItem->get('field_attendee_data')->isEmpty()) {
      $attendeeData = $orderItem->get('field_attendee_data')->value ?? [];
      if (is_string($attendeeData)) {
        $attendeeData = json_decode($attendeeData, TRUE) ?? [];
      }
    }
    return is_array($attendeeData) ? $attendeeData : [];
  }

  /**
   * @param array<string, mixed> $attendeeData
   *
   * @return array<int|string, mixed>
   */
  private function extractAccessibilityNeedsForTicket(array $attendeeData, int $ticketOffset): array {
    $accessibilityNeeds = [];
    $ticketKey = 'ticket_' . ($ticketOffset + 1);
    if (isset($attendeeData[$ticketKey]['accessibility_needs'])) {
      $needs = $attendeeData[$ticketKey]['accessibility_needs'];
      $accessibilityNeeds = array_filter($needs, function ($value) {
        return $value !== 0 && $value !== FALSE && $value !== '';
      });
    }
    return $accessibilityNeeds;
  }

  /**
   * Extracts custom question answers from a holder paragraph.
   *
   * Paid tickets: keys are sanitized machine names (see buildPaidExtraDataMapKey);
   * values are arrays with 'label' and 'value' (export-friendly map payload).
   *
   * RSVP: legacy flat map of question label => answer string.
   *
   * @return array<string, mixed>
   */
  private function extractExtraDataFromHolder(object $holder, LoggerInterface $logger, ?int $orderId, ?int $orderItemId, bool $diagnostics, bool $paidStructuredKeys): array {
    $extraData = [];
    $holderPid = ($holder instanceof ParagraphInterface && $holder->id()) ? (int) $holder->id() : NULL;

    if (!$holder->hasField('field_attendee_questions')) {
      if ($diagnostics) {
        $logger->notice('TEMP_DEBUG attendee_sync: holder @pid lacks field_attendee_questions; no extra_data extraction. order_id=@oid order_item_id=@oiid', [
          '@pid' => $holderPid !== NULL ? (string) $holderPid : 'new',
          '@oid' => (string) ($orderId ?? ''),
          '@oiid' => (string) ($orderItemId ?? ''),
          'order_id' => $orderId,
          'order_item_id' => $orderItemId,
          'holder_paragraph_id' => $holderPid,
        ]);
      }
      return $extraData;
    }

    if ($holder->get('field_attendee_questions')->isEmpty()) {
      if ($diagnostics) {
        $logger->notice('TEMP_DEBUG attendee_sync: holder @pid has empty field_attendee_questions; no extra_data extracted. order_id=@oid order_item_id=@oiid', [
          '@pid' => $holderPid !== NULL ? (string) $holderPid : 'new',
          '@oid' => (string) ($orderId ?? ''),
          '@oiid' => (string) ($orderItemId ?? ''),
          'order_id' => $orderId,
          'order_item_id' => $orderItemId,
          'holder_paragraph_id' => $holderPid,
        ]);
      }
      return $extraData;
    }

    $questions = $holder->get('field_attendee_questions')->referencedEntities();
    $paired = FALSE;
    $usedKeys = [];
    foreach ($questions as $q_index => $question) {
      if (!$question->hasField('field_attendee_extra_field')) {
        if ($diagnostics) {
          $qid = method_exists($question, 'id') && $question->id() ? (string) $question->id() : 'new';
          $logger->notice('TEMP_DEBUG attendee_sync: nested question @qid missing field_attendee_extra_field. order_id=@oid order_item_id=@oiid holder=@pid', [
            '@qid' => $qid,
            '@oid' => (string) ($orderId ?? ''),
            '@oiid' => (string) ($orderItemId ?? ''),
            '@pid' => $holderPid !== NULL ? (string) $holderPid : 'new',
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'holder_paragraph_id' => $holderPid,
          ]);
        }
        continue;
      }
      $label = $question->hasField('field_question_label')
        ? trim((string) ($question->get('field_question_label')->value ?? ''))
        : '';
      $answerRaw = $question->get('field_attendee_extra_field')->value ?? NULL;
      $valueString = $this->normalizeExtraAnswerValue($answerRaw);
      if ($valueString === '') {
        continue;
      }

      if ($paidStructuredKeys) {
        $mapKey = $this->buildPaidExtraDataMapKey($question, (int) $q_index, $usedKeys);
        $displayLabel = $label !== '' ? $label : $mapKey;
        $extraData[$mapKey] = [
          'label' => $displayLabel,
          'value' => $valueString,
        ];
        $paired = TRUE;
      }
      elseif ($label !== '') {
        $extraData[$label] = $valueString;
        $paired = TRUE;
      }
    }

    if ($diagnostics && !$paired && $questions !== []) {
      $logger->notice('TEMP_DEBUG attendee_sync: holder @pid had nested questions but no extra_data pairs (empty labels/answers). order_id=@oid order_item_id=@oiid', [
        '@pid' => $holderPid !== NULL ? (string) $holderPid : 'new',
        '@oid' => (string) ($orderId ?? ''),
        '@oiid' => (string) ($orderItemId ?? ''),
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
        'holder_paragraph_id' => $holderPid,
      ]);
    }

    return $extraData;
  }

  /**
   * Normalizes stored answer payloads to a single string (JSON for arrays).
   */
  private function normalizeExtraAnswerValue(mixed $raw): string {
    if ($raw === NULL) {
      return '';
    }
    if (is_bool($raw)) {
      return $raw ? '1' : '';
    }
    if (is_array($raw)) {
      $encoded = json_encode($raw);
      return $encoded !== FALSE ? $encoded : '';
    }
    return trim((string) $raw);
  }

  /**
   * Builds a stable map key for paid-ticket extra_data using machine name.
   *
   * @param object $question
   *   A paragraph entity (attendee_extra_field).
   * @param array<string, bool> $usedKeys
   *   Internal deduplication tracker (by reference).
   */
  private function buildPaidExtraDataMapKey(object $question, int $qIndex, array &$usedKeys): string {
    $base = 'question_' . $qIndex;
    if ($question->hasField('field_question_machine_name')) {
      $raw = trim((string) ($question->get('field_question_machine_name')->value ?? ''));
      if ($raw !== '') {
        $sanitized = strtolower((string) (preg_replace('/[^a-z0-9_]+/i', '_', $raw) ?? ''));
        $sanitized = trim((string) preg_replace('/_+/', '_', $sanitized), '_');
        if ($sanitized !== '') {
          $base = $sanitized;
        }
      }
    }

    $candidate = $base;
    $suffix = 0;
    while (isset($usedKeys[$candidate])) {
      $suffix++;
      $candidate = $base . '_' . $suffix;
    }
    $usedKeys[$candidate] = TRUE;

    return $candidate;
  }

  /**
   * Creates an attendee record using the EventAttendee entity.
   *
   * @param object $holder
   *   The ticket holder paragraph entity.
   * @param int $eventId
   *   The event node ID.
   * @param object $order
   *   The parent order.
   * @param object $orderItem
   *   The order item.
   * @param bool $isRsvp
   *   TRUE if this is a free RSVP (price = $0).
   * @param string $variationTitle
   *   The ticket variation title.
   * @param array $accessibilityNeeds
   *   Array of accessibility term IDs.
   * @param bool $rsvpLegacyEmailOrder
   *   When TRUE, prefer purchaser email over holder (RSVP legacy). Paid tickets use holder first.
   * @param bool $logExtraDataDiagnostics
   *   When TRUE, log TEMP_DEBUG lines for missing / empty extra question data.
   *
   * @return bool
   *   TRUE when purchaser email was used because holder email was blank (paid path only).
   */
  private function createAttendeeRecord(
    object $holder,
    int $eventId,
    object $order,
    object $orderItem,
    bool $isRsvp,
    string $variationTitle,
    array $accessibilityNeeds = [],
    bool $rsvpLegacyEmailOrder = FALSE,
    bool $logExtraDataDiagnostics = FALSE,
  ): bool {
    $logger = $this->loggerFactory->get('myeventlane_commerce');
    $orderId = method_exists($order, 'id') ? (int) $order->id() : NULL;
    $orderItemId = method_exists($orderItem, 'id') ? (int) $orderItem->id() : NULL;

    // Extract ticket holder data from paragraph.
    $firstName = $holder->get('field_first_name')->value ?? '';
    $lastName = $holder->get('field_last_name')->value ?? '';
    $holderEmail = trim((string) ($holder->get('field_email')->value ?? ''));
    $phone = $holder->hasField('field_phone') ? ($holder->get('field_phone')->value ?? '') : '';
    $fullName = trim($firstName . ' ' . $lastName);

    $paidStructuredExtraKeys = !$isRsvp;
    $extraData = $this->extractExtraDataFromHolder($holder, $logger, $orderId, $orderItemId, $logExtraDataDiagnostics, $paidStructuredExtraKeys);
    if (!$isRsvp && $logExtraDataDiagnostics) {
      $holderPid = ($holder instanceof ParagraphInterface && $holder->id()) ? (string) $holder->id() : 'new';
      $logger->notice(
        'TEMP_DEBUG attendee_questions: order_id=@oid order_item_id=@oiid holder_pid=@hp extra_data_pairs_extracted=@p',
        [
          '@oid' => (string) ($orderId ?? ''),
          '@oiid' => (string) ($orderItemId ?? ''),
          '@hp' => $holderPid,
          '@p' => (string) count($extraData),
          'order_id' => $orderId,
          'order_item_id' => $orderItemId,
          'holder_paragraph_id' => $holderPid,
          'extra_data_pairs_extracted' => count($extraData),
        ]
      );
    }

    // Determine purchaser identity (used for linking to "My Events").
    $purchaserUid = (int) ($order->getCustomerId() ?? 0);
    $purchaserEmail = '';
    if (method_exists($order, 'getEmail')) {
      $purchaserEmail = trim((string) ($order->getEmail() ?? ''));
    }
    elseif ($order->hasField('mail')) {
      $purchaserEmail = trim((string) ($order->get('mail')->value ?? ''));
    }

    $purchaserEmailUsedAsFallback = FALSE;
    if ($rsvpLegacyEmailOrder) {
      $storedEmail = $purchaserEmail !== '' ? $purchaserEmail : $holderEmail;
    }
    else {
      if ($holderEmail !== '') {
        $storedEmail = $holderEmail;
      }
      else {
        $storedEmail = $purchaserEmail;
        $purchaserEmailUsedAsFallback = $purchaserEmail !== '';
        if ($storedEmail === '') {
          $logger->warning('Paid attendee sync: holder and purchaser email both empty; saving empty email may fail. order_id=@oid order_item_id=@oiid', [
            '@oid' => (string) ($orderId ?? ''),
            '@oiid' => (string) ($orderItemId ?? ''),
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
          ]);
        }
      }
    }

    // Generate unique ticket code.
    // Cast IDs to int to satisfy strict types and avoid accidental strings.
    $ticketCode = $this->generateTicketCode(
      $eventId,
      (int) $order->id(),
      (int) $orderItem->id()
    );

    // Create EventAttendee entity.
    try {
      $attendeeValues = [
        'event' => $eventId,
        'name' => $fullName !== '' ? $fullName : 'Attendee',
        // Link attendance to purchaser for dashboard lookups.
        'uid' => $purchaserUid,
        'email' => $storedEmail,
        'phone' => $phone,
        'status' => EventAttendee::STATUS_CONFIRMED,
        'source' => $isRsvp ? EventAttendee::SOURCE_RSVP : EventAttendee::SOURCE_TICKET,
        'order_item' => $orderItem->id(),
        'ticket_code' => $ticketCode,
        'extra_data' => $extraData,
      ];

      // Add accessibility needs if provided.
      if (!empty($accessibilityNeeds)) {
        $attendeeValues['accessibility_needs'] = array_values($accessibilityNeeds);
      }

      /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee */
      $attendee = $this->entityTypeManager
        ->getStorage('event_attendee')
        ->create($attendeeValues);

      $attendee->save();

      $logger->info('Created attendee record for @name (@email) - Event @event_id', [
        '@name' => $fullName,
        '@email' => $storedEmail,
        '@event_id' => $eventId,
        'event_id' => $eventId,
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
      ]);
    }
    catch (\Exception $e) {
      $logger->error('Failed to create attendee record: @message', [
        '@message' => $e->getMessage(),
        'event_id' => $eventId,
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
      ]);
    }

    return $purchaserEmailUsedAsFallback;
  }

  /**
   * Counts existing attendee records for an order item and source.
   */
  private function countExistingAttendees(int $eventId, int $orderItemId, string $source): int {
    if ($eventId <= 0 || $orderItemId <= 0) {
      return 0;
    }

    $attendeeIds = $this->entityTypeManager
      ->getStorage('event_attendee')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('order_item', $orderItemId)
      ->condition('source', $source)
      ->execute();

    return count($attendeeIds);
  }

  /**
   * Generates a unique ticket code.
   *
   * @param int $eventId
   *   Event ID.
   * @param int $orderId
   *   Order ID.
   * @param int $orderItemId
   *   Order item ID.
   *
   * @return string
   *   The ticket code.
   */
  private function generateTicketCode(int $eventId, int $orderId, int $orderItemId): string {
    // Format: MEL-{EVENT_ID}-{ORDER_ID}-{ITEM_ID}-{RANDOM}.
    $random = strtoupper(substr(md5(uniqid((string) mt_rand(), TRUE)), 0, 6));
    return sprintf('MEL-%d-%d-%d-%s', $eventId, $orderId, $orderItemId, $random);
  }

}
