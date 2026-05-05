<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\TicketAvailabilityService;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Audits and safely repairs event ticket reference drift (field vs inverse).
 *
 * Purchase enforcement remains in TicketAvailabilityService; this service does
 * not weaken mapping rules or mutate Commerce orders or completed order items.
 * Variation unpublish for orphan SKUs is explicit via cleanupOrphanVariations()
 * only (TASK 16); reconcile/repair still delegates to TicketTierLifecycleService.
 */
final class EventTicketReconciliationService {

  use StringTranslationTrait;

  /**
   * Order states treated as completed / paid / fulfilment usage (protected).
   *
   * Aligned with VendorEventOrdersController::INCLUDED_STATES — variations
   * referenced from order items in these states must not be unpublished.
   *
   * @see \Drupal\myeventlane_vendor\Controller\VendorEventOrdersController::INCLUDED_STATES
   */
  private const ORDER_STATES_PROTECTED_USAGE = [
    'completed',
    'partially_refunded',
    'refunded',
    'placed',
    'fulfilled',
    'fulfillment',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketAvailabilityService $ticketAvailability,
    private readonly TicketTypeManager $ticketTypeManager,
    private readonly BookingFlowResolver $bookingFlowResolver,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
    private readonly CurrencyFormatter $currencyFormatter,
    private readonly LoggerInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Short status line after ticket save/sync (explicit CTA path).
   *
   * Reloads the event from storage before auditing.
   */
  public function formatPostTicketPersistAuditSummary(NodeInterface $event): string {
    $nid = $event->id();
    if ($nid === NULL) {
      return (string) $this->t('Ticket setup saved. Run an audit again after the event is stored.');
    }

    $fresh = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$fresh instanceof NodeInterface || $fresh->bundle() !== 'event') {
      return (string) $this->t('Ticket setup saved. Audit skipped (event could not be reloaded).');
    }

    $audit = $this->auditEvent($fresh);
    $issues = $audit['issues'] ?? [];
    $blocking_issues = $this->filterBlockingPostPersistAuditIssues($issues);
    if ($blocking_issues === []) {
      $message = (string) $this->t('Tickets saved and synced. Ticket setup audit: no mapping issues.');
      if ($issues !== []) {
        $message .= ' ' . (string) $this->t('Inactive old ticket rows are ignored by checkout.');
      }
      return $message;
    }

    if ($this->paidFieldTiersAreAllUnpublishedPaid($fresh) && !$this->auditIssuesContainError($blocking_issues)) {
      return (string) $this->t('Tickets saved, but no active paid tickets are available yet.');
    }

    $codes = [];
    foreach ($blocking_issues as $issue) {
      $codes[] = (string) ($issue['code'] ?? 'unknown');
      if (count($codes) >= 5) {
        break;
      }
    }
    $remainder = count($blocking_issues) - count($codes);
    $suffix = $remainder > 0 ? ' (+' . (string) $remainder . ')' : '';

    return (string) $this->t('Tickets saved and synced. Ticket setup audit: @codes@suffix. Open Advanced ticket manager to fix mapping, or run drush mel:tickets:audit for detail.', [
      '@codes' => implode(', ', $codes),
      '@suffix' => $suffix,
    ]);
  }

  /**
   * Issues that should block the “no mapping issues” post-save line.
   *
   * Unpublished inverse-only legacy tiers (TASK 19) stay informational only.
   *
   * @param list<array<string, mixed>> $issues
   * @return list<array<string, mixed>>
   */
  private function filterBlockingPostPersistAuditIssues(array $issues): array {
    return array_values(array_filter($issues, static function (array $issue): bool {
      if (($issue['code'] ?? '') === 'inactive_inverse_ticket_types_ignored'
        && ($issue['severity'] ?? '') === 'info') {
        return FALSE;
      }
      return TRUE;
    }));
  }

  /**
   * @param list<array<string, mixed>> $issues
   */
  private function auditIssuesContainError(array $issues): bool {
    foreach ($issues as $issue) {
      if (($issue['severity'] ?? '') === 'error') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * TRUE when field_ticket_types lists at least one paid tier and every one is unpublished.
   */
  private function paidFieldTiersAreAllUnpublishedPaid(NodeInterface $event): bool {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return FALSE;
    }
    $paid_on_field = 0;
    foreach ($event->get('field_ticket_types')->referencedEntities() as $entity) {
      if (!$entity instanceof TicketTypeInterface || $entity->isArchived() || $entity->getTicketKind() !== 'paid') {
        continue;
      }
      $paid_on_field++;
      if ($entity->isPublished()) {
        return FALSE;
      }
    }
    return $paid_on_field > 0;
  }

  /**
   * Audits a single event node for ticket mapping and paid display issues.
   *
   * @return array{
   *   event_id: int,
   *   event_title: string,
   *   booking_mode: string|null,
   *   product_id: int|null,
   *   variation_ids: list<int>,
   *   ticket_type_ids: list<int>,
   *   issues: list<array<string, mixed>>,
   *   repairable: bool,
   * }
   */
  public function auditEvent(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $title = $event->label();

    if ($event->bundle() !== 'event') {
      return $this->emptyAuditPayload($eventId, $title, [
        $this->issueTemplate(
          code: 'invalid_bundle',
          severity: 'warning',
          message: 'Node is not an event bundle; skipping ticket reconciliation.',
          variationId: NULL,
          ticketTypeId: NULL,
          repairable: FALSE,
          repairAction: NULL,
        ),
      ]);
    }

    $bookingMode = $this->bookingFlowResolver->getBookingMode($event);

    $productId = NULL;
    $variationIds = [];
    $freshTicketProduct = $this->loadFreshTicketProductFromEvent($event);
    if ($freshTicketProduct instanceof ProductInterface) {
      $productId = (int) $freshTicketProduct->id();
      foreach ($this->loadFreshVariationsForTicketProduct($freshTicketProduct) as $variation) {
        $variationIds[] = (int) $variation->id();
      }
      sort($variationIds, SORT_NUMERIC);
    }

    $allTickets = $this->ticketTypeManager->loadAllEventTicketTypes($event);
    $ticketTypeIds = array_keys($allTickets);
    sort($ticketTypeIds, SORT_NUMERIC);

    $fieldTicketIds = $this->fieldTicketTypeIds($event);
    $inverseTicketIds = $this->inverseTicketIdsForEvent($eventId);
    $inversePublishedTicketIds = $this->inversePublishedTicketIdsForEvent($eventId);

    $issues = [];

    $eventType = $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
      ? (string) $event->get('field_event_type')->value
      : '';

    if (in_array($eventType, ['paid', 'both'], TRUE)) {
      $issues = array_merge($issues, $this->buildTicketProductIssues($event, $eventId));
    }

    if ($bookingMode === BookingFlowResolver::MODE_PAID) {
      $prices = $this->ticketTypeManager->loadPublishedPaidTicketPrices($event);
      if ($prices === []) {
        $issues = array_merge($issues, $this->buildPaidDisplayPricingIssues($event, $allTickets, $fieldTicketIds));
      }
    }

    $missingPublishedInverseOnField = array_values(array_diff($inversePublishedTicketIds, $fieldTicketIds));
    if ($missingPublishedInverseOnField !== []) {
      $issues[] = $this->issueTemplate(
        code: 'variation_without_ticket_type',
        severity: 'error',
        message: sprintf(
          'field_ticket_types is missing %d published ticket type(s) that already reference this event (inverse event link): %s. Checkout mapping uses field_ticket_types only.',
          count($missingPublishedInverseOnField),
          implode(', ', $missingPublishedInverseOnField),
        ),
        variationId: NULL,
        ticketTypeId: NULL,
        repairable: TRUE,
        repairAction: 'reconcile_event_ticket_references',
      );
    }

    $inactiveInverseIgnored = [];
    foreach (array_diff($inverseTicketIds, $fieldTicketIds) as $tid) {
      $t = $allTickets[$tid] ?? NULL;
      if ($t instanceof TicketTypeInterface && !$t->isPublished()) {
        $inactiveInverseIgnored[] = $tid;
      }
    }
    if ($inactiveInverseIgnored !== []) {
      $issues[] = $this->issueTemplate(
        code: 'inactive_inverse_ticket_types_ignored',
        severity: 'info',
        message: sprintf(
          'Inactive inverse ticket types exist but are not referenced on field_ticket_types; ignored for checkout mapping (%s).',
          implode(', ', $inactiveInverseIgnored),
        ),
        variationId: NULL,
        ticketTypeId: NULL,
        repairable: FALSE,
        repairAction: NULL,
      );
    }

    foreach ($this->variationToTierCountsForEvent($event, $allTickets, $fieldTicketIds) as $vid => $count) {
      if ($count > 1) {
        $issues[] = $this->issueTemplate(
          code: 'ambiguous_variation_mapping',
          severity: 'error',
          message: sprintf('%d mel_ticket_type rows reference commerce_variation %d for this event.', $count, $vid),
          variationId: $vid,
          ticketTypeId: NULL,
          repairable: FALSE,
          repairAction: NULL,
        );
      }
    }

    foreach ($allTickets as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      if ($ticket->getTicketKind() === 'paid') {
        if ($ticket->get('commerce_variation')->isEmpty()) {
          $issues[] = $this->issueTemplate(
            code: 'ticket_type_without_variation',
            severity: 'error',
            message: 'Paid ticket type has no commerce_variation reference.',
            variationId: NULL,
            ticketTypeId: (int) $ticket->id(),
            repairable: FALSE,
            repairAction: NULL,
          );
        }
        else {
          $variation = $ticket->get('commerce_variation')->entity;
          if ($variation instanceof ProductVariationInterface && !$variation->isPublished() && $ticket->isPublished()) {
            $issues[] = $this->issueTemplate(
              code: 'unpublished_variation',
              severity: 'warning',
              message: 'Published ticket type points at an unpublished Commerce variation.',
              variationId: (int) $variation->id(),
              ticketTypeId: (int) $ticket->id(),
              repairable: FALSE,
              repairAction: NULL,
            );
          }
        }
      }
    }

    if ($freshTicketProduct instanceof ProductInterface) {
      foreach ($this->loadFreshVariationsForTicketProduct($freshTicketProduct) as $variation) {
          if (!$variation->isPublished() || $variation->bundle() !== 'ticket_variation') {
            continue;
          }
          $vid = (int) $variation->id();
          $tier = $this->ticketAvailability->resolveTierForVariation($event, $variation);
          if ($tier instanceof TicketTypeInterface) {
            if (!$tier->isPublished()) {
              $issues[] = $this->issueTemplate(
                code: 'unpublished_ticket_type',
                severity: 'warning',
                message: 'Mapped ticket type is unpublished while the Commerce variation is published (checkout will block).',
                variationId: $vid,
                ticketTypeId: (int) $tier->id(),
                repairable: FALSE,
                repairAction: NULL,
              );
            }
            continue;
          }

          $candidates = $this->ticketTypesWithVariationId($vid);
          $belongs = array_filter($candidates, function (TicketTypeInterface $t) use ($eventId): bool {
            if ($t->isArchived()) {
              return FALSE;
            }
            if (!$t->hasField('event') || $t->get('event')->isEmpty()) {
              return FALSE;
            }
            return (int) $t->get('event')->target_id === $eventId;
          });

          if (count($belongs) === 0) {
            $issues[] = $this->issueTemplate(
              code: 'orphan_variation_not_repairable',
              severity: 'error',
              message: 'Published ticket_variation on the event product has no mel_ticket_type mapping for this event (no inverse reference with matching commerce_variation).',
              variationId: $vid,
              ticketTypeId: NULL,
              repairable: FALSE,
              repairAction: NULL,
            );
          }
          elseif (count($belongs) === 1) {
            $tierMatch = reset($belongs);
            $tid = $tierMatch instanceof TicketTypeInterface ? (int) $tierMatch->id() : 0;
            if ($tid > 0
              && in_array($tid, $inverseTicketIds, TRUE)
              && !in_array($tid, $fieldTicketIds, TRUE)
              && $tierMatch->isPublished()) {
              $issues[] = $this->issueTemplate(
                code: 'variation_without_ticket_type',
                severity: 'error',
                message: sprintf(
                  'Variation %d matches ticket type %d via commerce_variation but that ticket type is missing from field_ticket_types.',
                  $vid,
                  $tid,
                ),
                variationId: $vid,
                ticketTypeId: $tid,
                repairable: TRUE,
                repairAction: 'reconcile_event_ticket_references',
              );
            }
          }
          else {
            $issues[] = $this->issueTemplate(
              code: 'variation_without_ticket_type',
              severity: 'error',
              message: sprintf(
                'Variation %d is not mapped via field_ticket_types for this event; reconcile cannot safely infer attachment.',
                $vid,
              ),
              variationId: $vid,
              ticketTypeId: NULL,
              repairable: FALSE,
              repairAction: NULL,
            );
          }
      }
    }

    if ($freshTicketProduct instanceof ProductInterface && $productId !== NULL) {
      $variationIdOnProduct = array_flip($variationIds);
      foreach ($this->ticketTypeManager->loadEventTicketTypes($event) as $ticket) {
        if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived() || !$ticket->isPublished()) {
          continue;
        }
        if ($ticket->get('commerce_variation')->isEmpty()) {
          continue;
        }
        $mappedVariation = $ticket->get('commerce_variation')->entity;
        if (!$mappedVariation instanceof ProductVariationInterface) {
          continue;
        }
        $vid = (int) $mappedVariation->id();
        $mappedProductId = (int) $mappedVariation->getProductId();
        if ($mappedProductId !== $productId) {
          $issues[] = $this->issueTemplate(
            code: 'product_missing_mapped_variation',
            severity: 'error',
            message: sprintf(
              'Ticket type %d maps to commerce_variation %d on product %d, but event ticket product is %d; lifecycle sync cannot attach this variation.',
              (int) $ticket->id(),
              $vid,
              $mappedProductId,
              $productId,
            ),
            variationId: $vid,
            ticketTypeId: (int) $ticket->id(),
            repairable: FALSE,
            repairAction: NULL,
          );
          continue;
        }
        if (!isset($variationIdOnProduct[$vid])) {
          $issues[] = $this->issueTemplate(
            code: 'product_missing_mapped_variation',
            severity: 'error',
            message: sprintf(
              'Ticket type %d maps to commerce_variation %d, but product %d does not reference that variation.',
              (int) $ticket->id(),
              $vid,
              $productId,
            ),
            variationId: $vid,
            ticketTypeId: (int) $ticket->id(),
            repairable: TRUE,
            repairAction: 'sync_product_variation_references',
          );
        }
      }
    }

    $issues = $this->dedupeIssues($issues);

    if ($issues === []) {
      $issues[] = $this->issueTemplate(
        code: 'ok',
        severity: 'info',
        message: 'No ticket reconciliation issues detected for this audit.',
        variationId: NULL,
        ticketTypeId: NULL,
        repairable: FALSE,
        repairAction: NULL,
      );
    }

    $abort = $this->repairAbortReason($issues);
    if ($abort !== NULL) {
      foreach ($issues as &$issue) {
        if (($issue['code'] ?? '') === 'ok') {
          continue;
        }
        $issue['repairable'] = FALSE;
        $issue['repair_action'] = NULL;
      }
      unset($issue);
    }

    $repairable = $this->computeRepairable($issues);

    return [
      'event_id' => $eventId,
      'event_title' => $title,
      'booking_mode' => $bookingMode,
      'product_id' => $productId,
      'variation_ids' => $variationIds,
      'ticket_type_ids' => $ticketTypeIds,
      'issues' => $issues,
      'repairable' => $repairable,
    ];
  }

  /**
   * Audits multiple events (Drush / bulk tooling).
   *
   * @param array{
   *   event_id?: int|null,
   *   limit?: int,
   * } $options
   *
   * @return array{
   *   events: list<array<string, mixed>>,
   *   summary: array{
   *     scanned: int,
   *     with_errors: int,
   *     with_warnings: int,
   *     repairable_events: int,
   *   },
   * }
   */
  public function auditAll(array $options = []): array {
    $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;
    $filterEventId = isset($options['event_id']) ? (int) $options['event_id'] : NULL;

    $this->logger->info('Ticket reconciliation audit_all started (limit @limit, event @eid).', [
      '@limit' => (string) $limit,
      '@eid' => $filterEventId !== NULL ? (string) $filterEventId : 'any',
    ]);

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->sort('nid', 'DESC')
      ->range(0, $limit);

    if ($filterEventId !== NULL) {
      $query->condition('nid', $filterEventId);
    }

    $ids = array_values(array_map('intval', array_values($query->execute())));
    $events = [];
    $withErrors = 0;
    $withWarnings = 0;
    $repairableEvents = 0;

    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $row = $this->auditEvent($node);
      $events[] = $row;

      $hasError = FALSE;
      $hasWarning = FALSE;
      foreach ($row['issues'] as $issue) {
        if (($issue['severity'] ?? '') === 'error') {
          $hasError = TRUE;
        }
        if (($issue['severity'] ?? '') === 'warning') {
          $hasWarning = TRUE;
        }
      }
      if ($hasError) {
        $withErrors++;
      }
      if ($hasWarning) {
        $withWarnings++;
      }
      if (!empty($row['repairable'])) {
        $repairableEvents++;
      }
    }

    return [
      'events' => $events,
      'summary' => [
        'scanned' => count($events),
        'with_errors' => $withErrors,
        'with_warnings' => $withWarnings,
        'repairable_events' => $repairableEvents,
      ],
    ];
  }

  /**
   * Inspects published orphan ticket variations for an event ticket product.
   *
   * Orphans match audit issue orphan_variation_not_repairable (see TASK 16 doc).
   *
   * @return array{
   *   event_id: int,
   *   event_title: string,
   *   product_id: int|null,
   *   orphans: list<array<string, mixed>>,
   *   summary: array{
   *     total_orphans: int,
   *     safe_to_unpublish: int,
   *     manual_review: int,
   *   },
   * }
   */
  public function inspectOrphanVariations(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $title = $event->label();
    $productId = NULL;

    if ($event->bundle() !== 'event') {
      return [
        'event_id' => $eventId,
        'event_title' => $title,
        'product_id' => NULL,
        'orphans' => [],
        'summary' => [
          'total_orphans' => 0,
          'safe_to_unpublish' => 0,
          'manual_review' => 0,
        ],
      ];
    }

    $product = $this->loadFreshTicketProductFromEvent($event);
    $productId = $product instanceof ProductInterface ? (int) $product->id() : NULL;

    $orphans = [];
    if ($product instanceof ProductInterface) {
      foreach ($this->loadFreshVariationsForTicketProduct($product) as $variation) {
        if (!$variation instanceof ProductVariationInterface) {
          continue;
        }
        if (!$this->variationIsOrphanPublishedTicketVariation($event, $variation)) {
          continue;
        }
        $vid = (int) $variation->id();
        $usage = $this->countOrderItemsForPurchasedVariation($vid);
        $safe = $this->variationIsSafeToUnpublishFromUsage($usage);
        $recommended = $safe ? 'unpublish' : 'manual_review';
        $reason = $this->orphanInspectReason($usage, $safe);

        $orphans[] = [
          'variation_id' => $vid,
          'label' => $variation->label(),
          'status' => $variation->isPublished(),
          'price' => $this->formatVariationPriceLabel($variation),
          'sku' => $variation->hasField('sku') && !$variation->get('sku')->isEmpty()
            ? (string) $variation->get('sku')->value
            : NULL,
          'order_usage' => [
            'total_order_items' => $usage['total_order_items'],
            'completed_order_items' => $usage['completed_order_items'],
            'draft_order_items' => $usage['draft_order_items'],
            'has_completed_usage' => $usage['has_completed_usage'],
          ],
          'safe_to_unpublish' => $safe,
          'recommended_action' => $recommended,
          'reason' => $reason,
        ];
      }
    }

    usort($orphans, static function (array $a, array $b): int {
      return ($a['variation_id'] ?? 0) <=> ($b['variation_id'] ?? 0);
    });

    $safeCount = 0;
    $manualCount = 0;
    foreach ($orphans as $row) {
      if (!empty($row['safe_to_unpublish'])) {
        $safeCount++;
      }
      if (($row['recommended_action'] ?? '') === 'manual_review') {
        $manualCount++;
      }
    }

    return [
      'event_id' => $eventId,
      'event_title' => $title,
      'product_id' => $productId,
      'orphans' => $orphans,
      'summary' => [
        'total_orphans' => count($orphans),
        'safe_to_unpublish' => $safeCount,
        'manual_review' => $manualCount,
      ],
    ];
  }

  /**
   * Dry-run or apply unpublish-only cleanup for orphan ticket variations.
   *
   * @param array{
   *   apply?: bool,
   *   allow_draft_usage?: bool,
   *   variation_ids?: list<int>,
   *   then_reconcile?: bool,
   * } $options
   *
   * @return array<string, mixed>
   */
  public function cleanupOrphanVariations(NodeInterface $event, array $options = []): array {
    $apply = !empty($options['apply']);
    $allowDraftUsage = array_key_exists('allow_draft_usage', $options)
      ? (bool) $options['allow_draft_usage']
      : TRUE;
    $thenReconcile = array_key_exists('then_reconcile', $options)
      ? (bool) $options['then_reconcile']
      : TRUE;
    $variationFilter = [];
    if (!empty($options['variation_ids']) && is_array($options['variation_ids'])) {
      $variationFilter = array_values(array_unique(array_map(
        static fn ($v): int => (int) $v,
        $options['variation_ids'],
      )));
      $variationFilter = array_values(array_filter($variationFilter, static fn (int $id): bool => $id > 0));
    }

    $inspect = $this->inspectOrphanVariations($event);
    $changes = [];
    $remainingBlockers = [];

    $orphanRowsById = [];
    foreach ($inspect['orphans'] as $row) {
      $orphanRowsById[(int) ($row['variation_id'] ?? 0)] = $row;
    }

    foreach ($inspect['orphans'] as $row) {
      $vid = (int) ($row['variation_id'] ?? 0);
      if ($vid < 1) {
        continue;
      }
      if ($variationFilter !== [] && !in_array($vid, $variationFilter, TRUE)) {
        continue;
      }

      $usage = $this->countOrderItemsForPurchasedVariation($vid);

      if (empty($row['status'])) {
        $changes[] = [
          'variation_id' => $vid,
          'action' => 'skipped',
          'reason' => 'Variation is not published; out of scope for orphan unpublish cleanup.',
        ];
        continue;
      }

      $loadedVariation = $this->loadVariation($vid);
      if (!$loadedVariation instanceof ProductVariationInterface
        || !$this->variationBelongsToEventTicketProduct($event, $loadedVariation)
        || !$this->variationIsOrphanPublishedTicketVariation($event, $loadedVariation)) {
        $changes[] = [
          'variation_id' => $vid,
          'action' => 'skipped',
          'reason' => 'Variation is not attached to this event ticket product or is no longer an orphan.',
        ];
        continue;
      }

      if ($usage['has_completed_usage']) {
        $changes[] = [
          'variation_id' => $vid,
          'action' => 'skipped',
          'reason' => 'Order items exist in a protected (completed/paid/fulfilment) order state; cannot unpublish.',
        ];
        continue;
      }

      if ($usage['other_order_items'] > 0) {
        $changes[] = [
          'variation_id' => $vid,
          'action' => 'skipped',
          'reason' => 'Order items exist in a non-draft, non-protected order state; manual review required.',
        ];
        continue;
      }

      if ($usage['draft_order_items'] > 0 && !$allowDraftUsage) {
        $changes[] = [
          'variation_id' => $vid,
          'action' => 'skipped',
          'reason' => 'Draft/cart order items reference this variation; re-run with allow_draft_usage enabled.',
        ];
        continue;
      }

      if (!$apply) {
        $changes[] = [
          'variation_id' => $vid,
          'action' => 'would_unpublish',
          'reason' => $usage['draft_order_items'] > 0
            ? sprintf('Dry-run: would unpublish (%d draft/cart order item row(s); allow_draft_usage=true).', $usage['draft_order_items'])
            : 'Dry-run: would unpublish (no protected order usage).',
        ];
        continue;
      }

      $varStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
      $varStorage->resetCache([$vid]);
      $variation = $this->loadVariation($vid);
      if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
        $changes[] = [
          'variation_id' => $vid,
          'action' => 'skipped',
          'reason' => 'Variation already unpublished.',
        ];
        continue;
      }

      $beforePublished = $variation->isPublished();
      $beforeRawStatus = $variation->hasField('status') ? $variation->get('status')->value : NULL;

      try {
        $this->applyTicketVariationUnpublish($variation);
        $variation->save();

        $varStorage->resetCache([$vid]);
        $recheck = $varStorage->load($vid);

        if (!$recheck instanceof ProductVariationInterface || $recheck->isPublished()) {
          $afterPublished = $recheck instanceof ProductVariationInterface ? $recheck->isPublished() : FALSE;
          $afterRawStatus = ($recheck instanceof ProductVariationInterface && $recheck->hasField('status'))
            ? $recheck->get('status')->value
            : NULL;
          $reloadConfirmsPublished = $recheck instanceof ProductVariationInterface && $recheck->isPublished();

          $fallbackDetached = FALSE;
          if (($usage['total_order_items'] ?? 0) === 0) {
            $fallbackDetached = $this->removeOrphanVariationReferenceFromEventTicketProduct($event, $vid);
          }

          if ($fallbackDetached) {
            $this->logger->notice(
              'Orphan cleanup fallback: removed variation @vid from ticket product references for event @eid (entity not deleted).',
              ['@vid' => (string) $vid, '@eid' => (string) $event->id()],
            );
            $changes[] = [
              'variation_id' => $vid,
              'action' => 'removed_product_reference',
              'reason' => 'Removed orphan variation reference from product; variation entity remains published but is no longer exposed by product.',
              'diagnostic' => [
                'variation_id' => $vid,
                'published_before' => $beforePublished,
                'published_after' => $afterPublished,
                'raw_status_before' => $beforeRawStatus,
                'raw_status_after' => $afterRawStatus,
                'storage_reload_confirms_published' => $reloadConfirmsPublished,
                'product_reference_removed' => TRUE,
                'suspected_next_action' => 'Run mel:tickets:audit and mel:tickets:repair dry-run; rebuild product variation references when abort clears.',
              ],
            ];
          }
          else {
            $failReason = 'Attempted to unpublish but variation remained published after save. Manual review required.';
            $suspected = ($usage['total_order_items'] ?? 0) === 0
              ? 'Unpersisted unpublish with zero order items: inspect project/custom code altering commerce_product_variation published state; product-reference fallback failed or was not attempted.'
              : 'Unpersisted unpublish with non-zero order usage: product-reference fallback is not allowed; inspect hooks altering variation publish state.';
            $diagnostic = [
              'variation_id' => $vid,
              'published_before' => $beforePublished,
              'published_after' => $afterPublished,
              'raw_status_before' => $beforeRawStatus,
              'raw_status_after' => $afterRawStatus,
              'storage_reload_confirms_published' => $reloadConfirmsPublished,
              'suspected_next_action' => $suspected,
            ];
            $this->logger->warning('Orphan cleanup: variation @vid (event @eid): @reason (@detail)', [
              '@vid' => (string) $vid,
              '@eid' => (string) $event->id(),
              '@reason' => $failReason,
              '@detail' => $suspected,
            ]);
            $changes[] = [
              'variation_id' => $vid,
              'action' => 'skipped',
              'reason' => $failReason,
              'diagnostic' => $diagnostic,
            ];
            $remainingBlockers[] = sprintf('Variation %d: %s', $vid, $failReason);
          }
        }
        else {
          $changes[] = [
            'variation_id' => $vid,
            'action' => 'unpublished',
            'reason' => $usage['draft_order_items'] > 0
              ? sprintf('Unpublished orphan variation (%d draft/cart order item row(s) allowed).', $usage['draft_order_items'])
              : 'Unpublished orphan variation (no protected order usage).',
          ];
          $this->logger->notice('Orphan ticket variation @vid unpublished for event @eid via cleanupOrphanVariations.', [
            '@vid' => (string) $vid,
            '@eid' => (string) $event->id(),
          ]);
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Failed to unpublish orphan variation @vid for event @eid: @message', [
          '@vid' => (string) $vid,
          '@eid' => (string) $event->id(),
          '@message' => $e->getMessage(),
        ]);
        $changes[] = [
          'variation_id' => $vid,
          'action' => 'skipped',
          'reason' => 'Save failed: ' . $e->getMessage(),
        ];
      }
    }

    if ($variationFilter !== []) {
      foreach ($variationFilter as $requested) {
        if (!isset($orphanRowsById[$requested])) {
          $changes[] = [
            'variation_id' => $requested,
            'action' => 'skipped',
            'reason' => 'Variation is not in the orphan inspect set for this event (not an orphan or does not belong to the ticket product).',
          ];
        }
      }
    }

    $postAudit = NULL;

    if ($apply && $thenReconcile) {
      $this->resetReconciliationEntityCaches((int) $event->id());
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $reloaded = $nodeStorage->load($event->id());
      if ($reloaded instanceof NodeInterface) {
        $afterAudit = $this->auditEvent($reloaded);
        $abort = $this->repairAbortReason($afterAudit['issues']);
        if ($abort !== NULL) {
          $remainingBlockers[] = $abort;
          $postAudit = $afterAudit;
        }
        else {
          $repairResult = $this->repairEvent($reloaded, ['apply' => TRUE]);
          $postAudit = $repairResult['audit_after'] ?? $afterAudit;
          $skipped = (string) ($repairResult['skipped_reason'] ?? '');
          if ($skipped !== '' && $skipped !== 'no_repair_actions') {
            $remainingBlockers[] = $skipped;
          }
        }
      }
    }

    return [
      'event_id' => (int) $event->id(),
      'dry_run' => !$apply,
      'applied' => $apply,
      'changes' => $changes,
      'post_audit' => $postAudit,
      'remaining_blockers' => $remainingBlockers,
    ];
  }

  /**
   * Repairs safe field drift using reconcileEventTicketReferences().
   *
   * @param array{
   *   dry_run?: bool,
   *   apply?: bool,
   * } $options
   *
   * @return array<string, mixed>
   */
  public function repairEvent(NodeInterface $event, array $options = []): array {
    $apply = !empty($options['apply']);
    $dryRun = !$apply;

    $this->logger->info('Ticket reconciliation repair invoked for event @nid (dry_run=@dry).', [
      '@nid' => (string) $event->id(),
      '@dry' => $dryRun ? 'true' : 'false',
    ]);

    $before = $this->auditEvent($event);

    $abort = $this->repairAbortReason($before['issues']);
    if ($abort !== NULL) {
      $this->logger->warning('Repair skipped for event @nid: @reason.', [
        '@nid' => (string) $event->id(),
        '@reason' => $abort,
      ]);
      return [
        'event_id' => (int) $event->id(),
        'dry_run' => $dryRun,
        'applied' => FALSE,
        'skipped_reason' => $abort,
        'audit_before' => $before,
        'audit_after' => NULL,
        'actions' => [],
        'success' => NULL,
        'remaining_orphan_variation_ids' => [],
      ];
    }

    $wantsProduct = $this->issuesWantProductRepair($before['issues']);
    $wantsReconcile = $this->issuesWantReconcile($before['issues']);
    $wantsVariationRefSync = $this->issuesWantProductVariationReferenceSync($before['issues']);

    if (!$wantsProduct && !$wantsReconcile && !$wantsVariationRefSync) {
      $this->logger->notice('Repair nothing to apply for event @nid (no product or reconcile-eligible issues).', [
        '@nid' => (string) $event->id(),
      ]);
      return [
        'event_id' => (int) $event->id(),
        'dry_run' => $dryRun,
        'applied' => FALSE,
        'skipped_reason' => 'no_repair_actions',
        'audit_before' => $before,
        'audit_after' => NULL,
        'actions' => [],
        'success' => NULL,
        'remaining_orphan_variation_ids' => [],
      ];
    }

    $actions = [];

    if ($wantsProduct) {
      $eventId = (int) $event->id();
      $candidates = $this->findTicketProductIdsForEvent($eventId);
      if (count($candidates) === 1) {
        $actions[] = sprintf(
          'Would link field_product_target to commerce_product %d (unique field_event match).',
          $candidates[0],
        );
      }
      else {
        $actions[] = 'Would run TicketTypeManager::syncTicketTypesToVariations() (canonical create/link ticket product for paid/both).';
      }
    }

    if ($wantsReconcile) {
      $actions[] = 'Would run TicketTierLifecycleService::reconcileEventTicketReferences() (merge inverse mel_ticket_type.event rows onto field_ticket_types).';
    }

    if ($wantsVariationRefSync) {
      $actions[] = 'Would sync product variation references to include mapped ticket variations.';
    }

    if ($dryRun) {
      $this->logger->notice('Repair dry-run for event @nid: planned actions logged.', [
        '@nid' => (string) $event->id(),
      ]);
      return [
        'event_id' => (int) $event->id(),
        'dry_run' => TRUE,
        'applied' => FALSE,
        'skipped_reason' => NULL,
        'audit_before' => $before,
        'audit_after' => NULL,
        'actions' => $actions,
        'success' => NULL,
        'remaining_orphan_variation_ids' => [],
      ];
    }

    $appliedLines = [];

    try {
      if ($wantsProduct) {
        $appliedLines = array_merge($appliedLines, $this->applyMissingTicketProductRepair($event));
      }

      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $loaded = $nodeStorage->load($event->id());
      $eventForReconcile = $loaded instanceof NodeInterface ? $loaded : $event;

      $this->resetReconciliationEntityCaches((int) $event->id());
      $fresh = $this->auditEvent($eventForReconcile);
      $abortAfterProduct = $this->repairAbortReason($fresh['issues']);

      if ($this->issuesWantReconcile($fresh['issues'])) {
        if ($abortAfterProduct !== NULL) {
          $this->logger->warning('Reconcile skipped for event @nid after product repair: @reason.', [
            '@nid' => (string) $event->id(),
            '@reason' => $abortAfterProduct,
          ]);
          $appliedLines[] = sprintf('Skipped reconcileEventTicketReferences(): %s', $abortAfterProduct);
          $reloadedAfter = $nodeStorage->load($event->id());
          $finalEvent = $reloadedAfter instanceof NodeInterface ? $reloadedAfter : $eventForReconcile;
          $finalized = $this->finalizeRepairAudit($finalEvent);
          if (!$finalized['success']) {
            $this->logger->warning('Repair applied for event @nid but orphan variation blockers remain after product step: @vids.', [
              '@nid' => (string) $event->id(),
              '@vids' => $finalized['remaining_orphan_variation_ids'] !== []
                ? implode(',', array_map('strval', $finalized['remaining_orphan_variation_ids']))
                : 'none',
            ]);
          }

          return [
            'event_id' => (int) $event->id(),
            'dry_run' => FALSE,
            'applied' => TRUE,
            'skipped_reason' => $abortAfterProduct,
            'audit_before' => $before,
            'audit_after' => $finalized['audit'],
            'actions' => $appliedLines,
            'success' => $finalized['success'],
            'remaining_orphan_variation_ids' => $finalized['remaining_orphan_variation_ids'],
          ];
        }

        $this->ticketTierLifecycle->reconcileEventTicketReferences($eventForReconcile);
        $appliedLines[] = 'Executed reconcileEventTicketReferences().';
      }

      $reloadedForSync = $nodeStorage->load($event->id());
      $eventForVariationSync = $reloadedForSync instanceof NodeInterface ? $reloadedForSync : $eventForReconcile;
      $this->resetReconciliationEntityCaches((int) $event->id());
      $auditAfterSteps = $this->auditEvent($eventForVariationSync);
      $abortAfterSteps = $this->repairAbortReason($auditAfterSteps['issues']);
      if ($abortAfterSteps === NULL && $this->issuesWantProductVariationReferenceSync($auditAfterSteps['issues'])) {
        $syncOk = $this->ticketTypeManager->syncProductVariationReferencesForEvent($eventForVariationSync);
        if ($syncOk) {
          $appliedLines[] = 'Product variation reference sync succeeded (sync_product_variation_references).';
        }
        else {
          $appliedLines[] = 'Product variation reference sync returned FALSE (missing ticket product or invalid event state); manual review required.';
          $this->logger->warning('Product variation reference sync returned FALSE for event @nid.', [
            '@nid' => (string) $event->id(),
          ]);
        }
      }

      $final = $nodeStorage->load($event->id());
      $finalEvent = $final instanceof NodeInterface ? $final : $eventForReconcile;
      $finalized = $this->finalizeRepairAudit($finalEvent);

      if ($finalized['success']) {
        $this->logger->notice('Repair applied for event @nid.', [
          '@nid' => (string) $event->id(),
        ]);
      }
      else {
        $this->logger->warning('Repair applied for event @nid but orphan variation blockers remain: @vids.', [
          '@nid' => (string) $event->id(),
          '@vids' => $finalized['remaining_orphan_variation_ids'] !== []
            ? implode(',', array_map('strval', $finalized['remaining_orphan_variation_ids']))
            : 'none',
        ]);
      }

      return [
        'event_id' => (int) $event->id(),
        'dry_run' => FALSE,
        'applied' => TRUE,
        'skipped_reason' => NULL,
        'audit_before' => $before,
        'audit_after' => $finalized['audit'],
        'actions' => $appliedLines,
        'success' => $finalized['success'],
        'remaining_orphan_variation_ids' => $finalized['remaining_orphan_variation_ids'],
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Repair exception for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Paid/both events must resolve field_product_target to a ticket Commerce product.
   *
   * @return list<array<string, mixed>>
   */
  private function buildTicketProductIssues(NodeInterface $event, int $eventId): array {
    if ($this->hasValidTicketProductReference($event)) {
      return [];
    }

    $candidates = $this->findTicketProductIdsForEvent($eventId);
    if (count($candidates) > 1) {
      return [
        $this->issueTemplate(
          code: 'ambiguous_ticket_product_relink',
          severity: 'error',
          message: sprintf(
            'Multiple Commerce ticket products reference this event via field_event (%s). Manual choice required.',
            implode(', ', $candidates),
          ),
          variationId: NULL,
          ticketTypeId: NULL,
          repairable: FALSE,
          repairAction: NULL,
        ),
      ];
    }

    $repairable = FALSE;
    $repairAction = NULL;
    if (count($candidates) === 1) {
      $repairable = TRUE;
      $repairAction = 'link_field_product_target';
    }
    elseif ($this->canAttemptSyncProductRepair($event)) {
      $repairable = TRUE;
      $repairAction = 'sync_ticket_types_to_variations';
    }

    return [
      $this->issueTemplate(
        code: 'missing_ticket_product',
        severity: 'error',
        message: $this->describeMissingTicketProductMessage($event),
        variationId: NULL,
        ticketTypeId: NULL,
        repairable: $repairable,
        repairAction: $repairAction,
      ),
    ];
  }

  private function describeMissingTicketProductMessage(NodeInterface $event): string {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return 'field_product_target is empty; paid/both events require a linked Commerce ticket product.';
    }
    $target = $event->get('field_product_target')->entity;
    if (!$target instanceof ProductInterface) {
      return 'field_product_target references a missing or unloadable Commerce product.';
    }
    if ($target->bundle() !== 'ticket') {
      return sprintf('field_product_target references a non-ticket Commerce product bundle (%s).', $target->bundle());
    }
    return 'field_product_target does not resolve to a valid ticket product.';
  }

  private function hasValidTicketProductReference(NodeInterface $event): bool {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return FALSE;
    }
    $product = $event->get('field_product_target')->entity;
    return $product instanceof ProductInterface && $product->bundle() === 'ticket';
  }

  /**
   * @return list<int>
   */
  private function findTicketProductIdsForEvent(int $eventId): array {
    $storage = $this->entityTypeManager->getStorage('commerce_product');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'ticket')
      ->condition('field_event', $eventId)
      ->sort('product_id')
      ->execute();
    return array_values(array_map('intval', array_values($ids)));
  }

  private function canAttemptSyncProductRepair(NodeInterface $event): bool {
    if ($this->ticketTypeManager->hasVendorStore($event)) {
      return TRUE;
    }
    $stores = $this->entityTypeManager->getStorage('commerce_store')->loadByProperties(['is_default' => TRUE]);
    if ($stores !== []) {
      return TRUE;
    }
    $all = $this->entityTypeManager->getStorage('commerce_store')->loadMultiple();
    return $all !== [];
  }

  /**
   * @param list<array<string, mixed>> $issues
   */
  private function issuesWantProductRepair(array $issues): bool {
    foreach ($issues as $issue) {
      if (($issue['code'] ?? '') !== 'missing_ticket_product') {
        continue;
      }
      if (empty($issue['repairable'])) {
        continue;
      }
      $action = (string) ($issue['repair_action'] ?? '');
      if (in_array($action, ['link_field_product_target', 'sync_ticket_types_to_variations'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param list<array<string, mixed>> $issues
   */
  private function issuesWantReconcile(array $issues): bool {
    foreach ($issues as $issue) {
      if (!empty($issue['repairable']) && ($issue['repair_action'] ?? '') === 'reconcile_event_ticket_references') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param list<array<string, mixed>> $issues
   */
  private function issuesWantProductVariationReferenceSync(array $issues): bool {
    foreach ($issues as $issue) {
      if (($issue['code'] ?? '') !== 'product_missing_mapped_variation') {
        continue;
      }
      if (!empty($issue['repairable']) && ($issue['repair_action'] ?? '') === 'sync_product_variation_references') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return list<string>
   */
  private function applyMissingTicketProductRepair(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $ids = $this->findTicketProductIdsForEvent($eventId);
    if (count($ids) > 1) {
      throw new \LogicException('ambiguous_ticket_product_relink');
    }

    if (count($ids) === 1) {
      $event->set('field_product_target', ['target_id' => $ids[0]]);
      EventNodeRevisionSave::prepare($event, 'MEL mel:tickets:repair linked field_product_target to ticket product with matching field_event.');
      $event->save();
      return [sprintf('Linked field_product_target to commerce_product %d (unique field_event match).', $ids[0])];
    }

    $ok = $this->ticketTypeManager->syncTicketTypesToVariations($event);
    if (!$ok) {
      throw new \RuntimeException('TicketTypeManager::syncTicketTypesToVariations() returned FALSE (check Commerce store, mixed paid currencies, and published paid tiers).');
    }

    return ['Executed TicketTypeManager::syncTicketTypesToVariations() to create/link the ticket product.'];
  }

  private function fieldTicketTypeIds(NodeInterface $event): array {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return [];
    }
    $ids = [];
    foreach ($event->get('field_ticket_types')->getValue() as $row) {
      if (!empty($row['target_id'])) {
        $ids[] = (int) $row['target_id'];
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Non-archived mel_ticket_type IDs with inverse event reference.
   *
   * @return list<int>
   */
  private function inverseTicketIdsForEvent(int $eventId): array {
    return $this->inverseTicketIdsForEventFiltered($eventId, published_only: FALSE);
  }

  /**
   * Published, non-archived mel_ticket_type IDs with inverse event reference.
   *
   * @return list<int>
   */
  private function inversePublishedTicketIdsForEvent(int $eventId): array {
    return $this->inverseTicketIdsForEventFiltered($eventId, published_only: TRUE);
  }

  /**
   * @return list<int>
   */
  private function inverseTicketIdsForEventFiltered(int $eventId, bool $published_only): array {
    if (!$this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      return [];
    }
    $ticketStorage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $candidateIds = array_values(array_map(
      'intval',
      array_values($ticketStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->sort('id')
        ->execute()),
    ));

    $ids = [];
    foreach ($ticketStorage->loadMultiple($candidateIds) as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      if ($published_only && !$ticket->isPublished()) {
        continue;
      }
      $ids[] = (int) $ticket->id();
    }
    return $ids;
  }

  /**
   * Counts tiers per commerce variation id for tickets belonging to this event.
   *
   * @param array<int, \Drupal\mel_ticket\Entity\TicketTypeInterface> $allTickets
   * @param list<int> $fieldTicketIds
   *
   * @return array<int, int>
   */
  private function variationToTierCountsForEvent(NodeInterface $event, array $allTickets, array $fieldTicketIds): array {
    $counts = [];
    foreach ($allTickets as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      if ($ticket->get('commerce_variation')->isEmpty()) {
        continue;
      }
      $vid = (int) $ticket->get('commerce_variation')->target_id;
      if ($vid < 1) {
        continue;
      }
      if (!$this->ticketBelongsToEventAggregate($event, $ticket, $fieldTicketIds)) {
        continue;
      }
      $counts[$vid] = ($counts[$vid] ?? 0) + 1;
    }
    return $counts;
  }

  /**
   * @param list<int> $fieldTicketIds
   */
  private function ticketBelongsToEventAggregate(NodeInterface $event, TicketTypeInterface $ticket, array $fieldTicketIds): bool {
    $eid = (int) $event->id();
    $onField = in_array((int) $ticket->id(), $fieldTicketIds, TRUE);
    $inverse = FALSE;
    if ($ticket->hasField('event') && !$ticket->get('event')->isEmpty()) {
      $inverse = (int) $ticket->get('event')->target_id === $eid;
    }
    return $onField || $inverse;
  }

  /**
   * @return list<\Drupal\mel_ticket\Entity\TicketTypeInterface>
   */
  private function ticketTypesWithVariationId(int $variationId): array {
    if (!$this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $ids = array_values(array_map(
      'intval',
      array_values($storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('commerce_variation', $variationId)
        ->execute()),
    ));
    $out = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof TicketTypeInterface) {
        $out[] = $entity;
      }
    }
    return $out;
  }

  /**
   * @param list<array<string, mixed>> $issues
   *
   * @return list<array<string, mixed>>
   */
  private function dedupeIssues(array $issues): array {
    $seen = [];
    $out = [];
    foreach ($issues as $issue) {
      $key = ($issue['code'] ?? '')
        . '|' . (string) ($issue['variation_id'] ?? '')
        . '|' . (string) ($issue['ticket_type_id'] ?? '')
        . '|' . substr((string) ($issue['message'] ?? ''), 0, 120);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $out[] = $issue;
    }
    return $out;
  }

  /**
   * @param list<array<string, mixed>> $issues
   */
  private function computeRepairable(array $issues): bool {
    if ($this->repairAbortReason($issues) !== NULL) {
      return FALSE;
    }
    foreach ($issues as $issue) {
      if (!empty($issue['repairable'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Repair must not run while known-dangerous commerce gaps remain.
   *
   * @param list<array<string, mixed>> $issues
   */
  private function repairAbortReason(array $issues): ?string {
    foreach ($issues as $issue) {
      if (($issue['code'] ?? '') === 'ambiguous_ticket_product_relink') {
        return 'ambiguous_ticket_product_relink';
      }
    }
    foreach ($issues as $issue) {
      if (($issue['code'] ?? '') === 'ambiguous_variation_mapping') {
        return 'ambiguous_variation_mapping';
      }
    }
    foreach ($issues as $issue) {
      if (($issue['code'] ?? '') === 'orphan_variation_not_repairable') {
        return 'orphan_variation_not_repairable';
      }
    }
    return NULL;
  }

  /**
   * @param array<int, \Drupal\mel_ticket\Entity\TicketTypeInterface> $allTickets
   */
  /**
   * Granular paid-display audit codes plus legacy paid_without_prices for tooling.
   *
   * @param array<int, \Drupal\mel_ticket\Entity\TicketTypeInterface> $allTickets
   * @param list<int> $fieldTicketIds
   *
   * @return list<array<string, mixed>>
   */
  private function buildPaidDisplayPricingIssues(NodeInterface $event, array $allTickets, array $fieldTicketIds): array {
    $out = [];
    $paidOnField = [];
    foreach ($fieldTicketIds as $tid) {
      $t = $allTickets[$tid] ?? NULL;
      if (!$t instanceof TicketTypeInterface || $t->isArchived() || $t->getTicketKind() !== 'paid') {
        continue;
      }
      $paidOnField[] = $t;
    }

    if ($paidOnField === []) {
      $out[] = $this->issueTemplate(
        code: 'paid_without_ticket_types',
        severity: 'warning',
        message: 'No paid ticket types are referenced on field_ticket_types (checkout uses this field, not inverse mel_ticket_type.event alone).',
        variationId: NULL,
        ticketTypeId: NULL,
        repairable: FALSE,
        repairAction: NULL,
      );
    }

    $publishedPaid = array_values(array_filter(
      $paidOnField,
      static fn (TicketTypeInterface $t): bool => $t->isPublished(),
    ));
    if ($paidOnField !== [] && $publishedPaid === []) {
      $out[] = $this->issueTemplate(
        code: 'paid_ticket_types_unpublished',
        severity: 'warning',
        message: 'Paid ticket types exist on the event but none are published.',
        variationId: NULL,
        ticketTypeId: NULL,
        repairable: FALSE,
        repairAction: NULL,
      );
    }

    $missingPrice = FALSE;
    foreach ($publishedPaid as $t) {
      if ($t->toPriceValue() === NULL) {
        $missingPrice = TRUE;
        break;
      }
    }
    if ($publishedPaid !== [] && $missingPrice) {
      $out[] = $this->issueTemplate(
        code: 'paid_ticket_types_without_prices',
        severity: 'warning',
        message: 'At least one published paid tier on field_ticket_types lacks a resolvable tier price.',
        variationId: NULL,
        ticketTypeId: NULL,
        repairable: FALSE,
        repairAction: NULL,
      );
    }

    $out[] = $this->issueTemplate(
      code: 'paid_without_prices',
      severity: 'warning',
      message: $this->describePaidWithoutPrices($event, $allTickets),
      variationId: NULL,
      ticketTypeId: NULL,
      repairable: FALSE,
      repairAction: NULL,
    );

    return $out;
  }

  /**
   * @param array<int, \Drupal\mel_ticket\Entity\TicketTypeInterface> $allTickets
   */
  private function describePaidWithoutPrices(NodeInterface $event, array $allTickets): string {
    $paidPublished = 0;
    $paidUnpublished = 0;
    $missingPrice = 0;
    foreach ($allTickets as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      if ($ticket->getTicketKind() !== 'paid') {
        continue;
      }
      if (!$ticket->isPublished()) {
        $paidUnpublished++;
        continue;
      }
      $paidPublished++;
      if ($ticket->toPriceValue() === NULL) {
        $missingPrice++;
      }
    }

    $parts = [];
    if ($paidPublished === 0) {
      $parts[] = 'No published paid ticket types found (check publish status).';
    }
    if ($missingPrice > 0) {
      $parts[] = sprintf('%d published paid tier(s) lack a resolvable tier price.', $missingPrice);
    }
    if ($paidUnpublished > 0) {
      $parts[] = sprintf('%d paid tier(s) are unpublished.', $paidUnpublished);
    }
    if ($parts === []) {
      $parts[] = 'No published paid prices resolved via loadPublishedPaidTicketPrices().';
    }

    $productMissing = !$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty();
    if ($productMissing) {
      array_unshift($parts, 'Event has no field_product_target product.');
    }

    return implode(' ', $parts);
  }

  /**
   * @param list<array<string, mixed>> $issues
   *
   * @return array{
   *   event_id: int,
   *   event_title: string,
   *   booking_mode: null,
   *   product_id: null,
   *   variation_ids: list<int>,
   *   ticket_type_ids: list<int>,
   *   issues: list<array<string, mixed>>,
   *   repairable: bool,
   * }
   */
  private function emptyAuditPayload(int $eventId, string $title, array $issues): array {
    return [
      'event_id' => $eventId,
      'event_title' => $title,
      'booking_mode' => NULL,
      'product_id' => NULL,
      'variation_ids' => [],
      'ticket_type_ids' => [],
      'issues' => $issues,
      'repairable' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function issueTemplate(
    string $code,
    string $severity,
    string $message,
    ?int $variationId,
    ?int $ticketTypeId,
    bool $repairable,
    ?string $repairAction,
  ): array {
    return [
      'code' => $code,
      'severity' => $severity,
      'message' => $message,
      'variation_id' => $variationId,
      'ticket_type_id' => $ticketTypeId,
      'repairable' => $repairable,
      'repair_action' => $repairAction,
    ];
  }

  /**
   * Loads the ticket Commerce product from the event with storage cache reset.
   */
  private function loadFreshTicketProductFromEvent(NodeInterface $event): ?ProductInterface {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return NULL;
    }
    $targetId = (int) $event->get('field_product_target')->target_id;
    if ($targetId < 1) {
      return NULL;
    }
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $productStorage->resetCache([$targetId]);
    $product = $productStorage->load($targetId);
    return $product instanceof ProductInterface && $product->bundle() === 'ticket'
      ? $product
      : NULL;
  }

  /**
   * Loads variations from storage (not product->getVariations()) for accurate status.
   *
   * @return list<ProductVariationInterface>
   */
  private function loadFreshVariationsForTicketProduct(ProductInterface $product): array {
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $pid = (int) $product->id();
    $productStorage->resetCache([$pid]);
    $freshProduct = $productStorage->load($pid);
    if (!$freshProduct instanceof ProductInterface || !method_exists($freshProduct, 'getVariationIds')) {
      return [];
    }
    $ids = $freshProduct->getVariationIds();
    if (!is_array($ids) || $ids === []) {
      return [];
    }
    $intIds = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $ids)));

    $varStorage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $varStorage->resetCache($intIds);
    $entities = $varStorage->loadMultiple($intIds);

    $ordered = [];
    foreach ($intIds as $vid) {
      if (isset($entities[$vid]) && $entities[$vid] instanceof ProductVariationInterface) {
        $ordered[] = $entities[$vid];
      }
    }

    return $ordered;
  }

  /**
   * Clears entity caches so audit/inspect see persisted Commerce state.
   */
  private function resetReconciliationEntityCaches(int ...$nodeIds): void {
    $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    $this->entityTypeManager->getStorage('commerce_product')->resetCache();
    if ($this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      $this->entityTypeManager->getStorage('mel_ticket_type')->resetCache();
    }
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    if ($nodeIds !== []) {
      $nodeStorage->resetCache($nodeIds);
    }
    else {
      $nodeStorage->resetCache();
    }
  }

  /**
   * @param list<array<string, mixed>> $issues
   *
   * @return list<int>
   */
  private function extractOrphanVariationIdsFromIssues(array $issues): array {
    $ids = [];
    foreach ($issues as $issue) {
      if (($issue['code'] ?? '') === 'orphan_variation_not_repairable') {
        $vid = $issue['variation_id'] ?? NULL;
        if ($vid !== NULL && (int) $vid > 0) {
          $ids[] = (int) $vid;
        }
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * TRUE when audit still reports product / tier variation reference drift (TASK 17).
   *
   * @param list<array<string, mixed>> $issues
   */
  private function auditHasProductVariationReferenceErrors(array $issues): bool {
    foreach ($issues as $issue) {
      if (($issue['code'] ?? '') === 'product_missing_mapped_variation') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reloads entity caches and returns a fresh audit after repair mutations.
   *
   * @return array{
   *   audit: array<string, mixed>,
   *   success: bool,
   *   remaining_orphan_variation_ids: list<int>,
   * }
   */
  private function finalizeRepairAudit(NodeInterface $event): array {
    $this->resetReconciliationEntityCaches((int) $event->id());
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $reloaded = $nodeStorage->load($event->id());
    $target = $reloaded instanceof NodeInterface ? $reloaded : $event;
    $audit = $this->auditEvent($target);
    $orphanIds = $this->extractOrphanVariationIdsFromIssues($audit['issues']);
    $productVariationGap = $this->auditHasProductVariationReferenceErrors($audit['issues']);

    return [
      'audit' => $audit,
      'success' => $orphanIds === [] && !$productVariationGap,
      'remaining_orphan_variation_ids' => $orphanIds,
    ];
  }

  private function loadVariation(int $variationId): ?ProductVariationInterface {
    $entity = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variationId);
    return $entity instanceof ProductVariationInterface ? $entity : NULL;
  }

  private function variationBelongsToEventTicketProduct(NodeInterface $event, ProductVariationInterface $variation): bool {
    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return FALSE;
    }
    $product = $event->get('field_product_target')->entity;
    if (!$product instanceof ProductInterface || $product->bundle() !== 'ticket') {
      return FALSE;
    }
    return (int) $variation->getProductId() === (int) $product->id();
  }

  /**
   * Matches audit orphan_variation_not_repairable detection for published variations.
   */
  private function variationIsOrphanPublishedTicketVariation(NodeInterface $event, ProductVariationInterface $variation): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }
    if (!$variation->isPublished() || $variation->bundle() !== 'ticket_variation') {
      return FALSE;
    }
    if (!$this->variationBelongsToEventTicketProduct($event, $variation)) {
      return FALSE;
    }
    $tier = $this->ticketAvailability->resolveTierForVariation($event, $variation);
    if ($tier instanceof TicketTypeInterface) {
      return FALSE;
    }
    $belongs = $this->inverseNonArchivedTicketTypesForVariationAndEvent((int) $variation->id(), (int) $event->id());
    return count($belongs) === 0;
  }

  /**
   * @return list<TicketTypeInterface>
   */
  private function inverseNonArchivedTicketTypesForVariationAndEvent(int $variationId, int $eventId): array {
    $out = [];
    foreach ($this->ticketTypesWithVariationId($variationId) as $ticket) {
      if (!$ticket instanceof TicketTypeInterface || $ticket->isArchived()) {
        continue;
      }
      if (!$ticket->hasField('event') || $ticket->get('event')->isEmpty()) {
        continue;
      }
      if ((int) $ticket->get('event')->target_id === $eventId) {
        $out[] = $ticket;
      }
    }
    return $out;
  }

  /**
   * @return array{
   *   total_order_items: int,
   *   completed_order_items: int,
   *   draft_order_items: int,
   *   other_order_items: int,
   *   has_completed_usage: bool,
   * }
   */
  private function countOrderItemsForPurchasedVariation(int $variationId): array {
    $completed = 0;
    $draft = 0;
    $other = 0;

    $itemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $ids = $itemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('purchased_entity', $variationId)
      ->execute();
    $ids = $ids ? array_values(array_map('intval', array_values($ids))) : [];

    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

    foreach ($itemStorage->loadMultiple($ids) as $item) {
      if (!$item instanceof OrderItemInterface) {
        $other++;
        continue;
      }
      $order = $orderStorage->load($item->getOrderId());
      if (!$order instanceof OrderInterface) {
        $other++;
        continue;
      }
      $stateId = $order->getState()->getId();
      if (in_array($stateId, self::ORDER_STATES_PROTECTED_USAGE, TRUE)) {
        $completed++;
      }
      elseif ($stateId === 'draft') {
        $draft++;
      }
      else {
        $other++;
      }
    }

    return [
      'total_order_items' => count($ids),
      'completed_order_items' => $completed,
      'draft_order_items' => $draft,
      'other_order_items' => $other,
      'has_completed_usage' => $completed > 0,
    ];
  }

  /**
   * @param array{
   *   has_completed_usage: bool,
   *   other_order_items: int,
   *   draft_order_items: int,
   * } $usage
   */
  private function variationIsSafeToUnpublishFromUsage(array $usage): bool {
    if (!empty($usage['has_completed_usage'])) {
      return FALSE;
    }
    if (($usage['other_order_items'] ?? 0) > 0) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @param array{
   *   has_completed_usage: bool,
   *   completed_order_items: int,
   *   draft_order_items: int,
   *   other_order_items: int,
   * } $usage
   */
  private function orphanInspectReason(array $usage, bool $safe): string {
    if (!empty($usage['has_completed_usage'])) {
      return sprintf(
        'Protected order usage: %d order item row(s) in completed/paid/fulfilment states.',
        (int) ($usage['completed_order_items'] ?? 0),
      );
    }
    if (($usage['other_order_items'] ?? 0) > 0) {
      return sprintf(
        'Non-draft order item row(s) in an unrecognized state (%d); manual review required.',
        (int) $usage['other_order_items'],
      );
    }
    if (($usage['draft_order_items'] ?? 0) > 0) {
      return sprintf(
        '%d draft/cart order item row(s); cleanup may strand active carts unless operators accept draft usage.',
        (int) $usage['draft_order_items'],
      );
    }
    return $safe
      ? 'No protected or ambiguous order usage; unpublish-only cleanup is allowed when explicitly applied.'
      : 'Manual review required.';
  }

  /**
   * Unpublishes a Commerce product variation using supported publish APIs.
   *
   * Commerce maps the published flag to a boolean `status` base field.
   * Integer zero assignments do not reliably persist; callers must use
   * EntityPublishedInterface::setUnpublished() or boolean FALSE.
   */
  private function applyTicketVariationUnpublish(ProductVariationInterface $variation): void {
    if ($variation instanceof EntityPublishedInterface) {
      $variation->setUnpublished();
      return;
    }
    if ($variation->hasField('status')) {
      $variation->set('status', FALSE);
    }
  }

  /**
   * Removes a variation target_id from the event ticket product only (no delete).
   *
   * Used when unpublish cannot persist and order usage is zero (explicit apply).
   *
   * @return bool
   *   TRUE when the variation ID is no longer listed on a freshly loaded product.
   */
  private function removeOrphanVariationReferenceFromEventTicketProduct(NodeInterface $event, int $variationId): bool {
    $product = $this->loadFreshTicketProductFromEvent($event);
    if (!$product instanceof ProductInterface) {
      return FALSE;
    }

    $pid = (int) $product->id();
    $productStorage = $this->entityTypeManager->getStorage('commerce_product');
    $varStorage = $this->entityTypeManager->getStorage('commerce_product_variation');

    $productStorage->resetCache([$pid]);
    $freshProduct = $productStorage->load($pid);
    if (!$freshProduct instanceof ProductInterface) {
      return FALSE;
    }

    $listed = array_map('intval', $freshProduct->getVariationIds());
    if (!in_array($variationId, $listed, TRUE)) {
      return TRUE;
    }

    $variation = $varStorage->load($variationId);
    if (!$variation instanceof ProductVariationInterface) {
      return FALSE;
    }

    try {
      $freshProduct->removeVariation($variation);
      $freshProduct->save();
    }
    catch (\Throwable $e) {
      $this->logger->error(
        'Orphan cleanup fallback: failed removing variation @vid from product @pid: @message',
        [
          '@vid' => (string) $variationId,
          '@pid' => (string) $pid,
          '@message' => $e->getMessage(),
        ],
      );
      return FALSE;
    }

    $productStorage->resetCache([$pid]);
    $varStorage->resetCache([$variationId]);
    $verify = $productStorage->load($pid);
    if (!$verify instanceof ProductInterface) {
      return FALSE;
    }

    return !in_array($variationId, array_map('intval', $verify->getVariationIds()), TRUE);
  }

  private function formatVariationPriceLabel(ProductVariationInterface $variation): ?string {
    if (!method_exists($variation, 'getPrice')) {
      return NULL;
    }
    $price = $variation->getPrice();
    if (!$price instanceof Price) {
      return NULL;
    }
    return $this->currencyFormatter->format(
      $price->getNumber(),
      $price->getCurrencyCode(),
      [
        'currency_display' => 'symbol',
        'minimum_fraction_digits' => 0,
        'maximum_fraction_digits' => 2,
      ],
    );
  }

}
