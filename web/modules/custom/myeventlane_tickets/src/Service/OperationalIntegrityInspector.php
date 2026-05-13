<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\State\StateInterface;
use Drupal\myeventlane_messaging\EventSubscriber\OrderPaidConfirmationPdfRecoverySubscriber;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketIssuer;
use Drupal\myeventlane_tickets\Ticket\TicketPdfGenerator;
use Drupal\myeventlane_tickets\Ticket\TicketQrPayload;
use Drupal\myeventlane_wallet\Service\WalletTicketResolver;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only operational diagnostics for issuance, artifacts, and recovery.
 *
 * Does not mutate entities, queues, messages, or state. Callers must enforce
 * access control before invoking on sensitive orders.
 *
 * @see \Drupal\myeventlane_messaging\EventSubscriber\OrderPaidConfirmationPdfRecoverySubscriber::recoveryStateKey()
 */
final class OperationalIntegrityInspector {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketIssuer $ticketIssuer,
    private readonly UniversalTicketViewModelBuilder $universalTicketViewModelBuilder,
    private readonly TicketPdfGenerator $ticketPdfGenerator,
    private readonly TicketQrPayload $ticketQrPayload,
    private readonly TicketCapabilityManager $ticketCapabilityManager,
    private readonly EntitlementCapabilityRegistry $entitlementCapabilityRegistry,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
    private readonly ?WalletTicketResolver $walletTicketResolver = NULL,
    private readonly ?object $messageStorage = NULL,
  ) {}

  /**
   * Builds deterministic diagnostics for one Commerce order.
   *
   * @return array{
   *   issuance: array<string, mixed>,
   *   artifacts: array<string, mixed>,
   *   recovery: array<string, mixed>,
   *   compatibility: array<string, mixed>,
   *   guest_continuity: array<string, mixed>
   * }
   */
  public function inspectOrder(OrderInterface $order): array {
    $orderId = (int) $order->id();
    if ($orderId < 1) {
      return $this->emptyDiagnostics('invalid');
    }

    $tickets = $this->loadTicketsForOrder($orderId);
    $expected = $this->ticketIssuer->countExpectedIssuanceUnits($order);
    $issued = count($tickets);

    $orderItemIds = $this->orderItemIdSet($order);
    $orphanTickets = $this->countOrphanTicketLinks($tickets, $orderItemIds);
    $orphanEligibleItems = $this->countOrphanEligibleOrderItems($order, $tickets);

    $issuance = $this->buildIssuanceDomain(
      $orderId,
      $expected,
      $issued,
      $orphanTickets,
      $orphanEligibleItems,
    );

    $artifacts = $this->buildArtifactsDomain($order, $tickets);
    $recovery = $this->buildRecoveryDomain($order, $tickets);
    $compatibility = $this->buildCompatibilityDomain($order, $tickets, $artifacts);
    $guest_continuity = $this->buildGuestContinuityDomain($order, $tickets);

    $this->maybeLogAnomalies(
      $orderId,
      $issuance,
      $recovery,
    );

    return [
      'issuance' => $issuance,
      'artifacts' => $artifacts,
      'recovery' => $recovery,
      'compatibility' => $compatibility,
      'guest_continuity' => $guest_continuity,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function emptyDiagnostics(string $order_status): array {
    return [
      'issuance' => [
        'order_operational_status' => $order_status,
        'expected_quantity' => 0,
        'issued_ticket_count' => 0,
        'quantity_alignment_status' => 'missing',
        'idempotent_replay_would_skip' => FALSE,
        'partial_issuance_blocked_by_idempotent_guard' => FALSE,
        'orphan_ticket_count' => 0,
        'orphan_eligible_order_item_count' => 0,
      ],
      'artifacts' => [
        'canonical_pdf_readiness' => 'missing',
        'wallet_route_scaffold' => 'missing',
        'qr_payload_operational' => 'missing',
        'attachment_continuity' => 'missing',
        'entitlement_capability_policy' => [],
      ],
      'recovery' => [
        'completion_state' => 'missing',
        'recovery_completed_recorded' => FALSE,
        'late_tickets_after_confirmation' => 'unknown',
        'recovery_requirement_signal' => 'unknown',
        'recovery_mismatch' => FALSE,
      ],
      'compatibility' => [
        'ticket_pdf_operational_path' => 'pending',
        'order_item_pdf_surface' => 'missing',
        'wallet_resolution_surface' => 'pending',
      ],
      'guest_continuity' => [
        'guest_access_compatible' => FALSE,
        'entitlement_ownership_valid' => FALSE,
        'purchaser_identity_continuity_valid' => FALSE,
        'continuity_status' => 'invalid',
      ],
    ];
  }

  /**
   * @param list<Ticket> $tickets
   *
   * @return array<string, mixed>
   */
  private function buildIssuanceDomain(
    int $orderId,
    int $expected,
    int $issued,
    int $orphanTickets,
    int $orphanEligibleItems,
  ): array {
    $alignment = $this->quantityAlignmentStatus($expected, $issued);
    $idempotentSkip = $issued > 0;
    $partialBlocked = $issued > 0 && $issued < $expected;

    return [
      'order_operational_status' => 'valid',
      'expected_quantity' => $expected,
      'issued_ticket_count' => $issued,
      'quantity_alignment_status' => $alignment,
      'idempotent_replay_would_skip' => $idempotentSkip,
      'partial_issuance_blocked_by_idempotent_guard' => $partialBlocked,
      'orphan_ticket_count' => $orphanTickets,
      'orphan_eligible_order_item_count' => $orphanEligibleItems,
    ];
  }

  private function quantityAlignmentStatus(int $expected, int $issued): string {
    if ($expected < 1 && $issued < 1) {
      return 'pending';
    }
    if ($expected < 1 && $issued > 0) {
      return 'invalid';
    }
    if ($expected > 0 && $issued < 1) {
      return 'missing';
    }
    return $issued === $expected ? 'valid' : 'invalid';
  }

  /**
   * @param list<Ticket> $tickets
   *
   * @return array<string, mixed>
   */
  private function buildArtifactsDomain(OrderInterface $order, array $tickets): array {
    if ($tickets === []) {
      return [
        'canonical_pdf_readiness' => 'missing',
        'wallet_route_scaffold' => 'missing',
        'qr_payload_operational' => 'missing',
        'attachment_continuity' => 'missing',
        'entitlement_capability_policy' => [],
      ];
    }

    $pdfReady = 0;
    $pdfPending = 0;
    $walletReady = 0;
    $walletPending = 0;
    $qrValid = 0;
    $qrInvalid = 0;
    $holderReady = 0;

    $capability_policy = $this->buildEntitlementCapabilityPolicyDigest($tickets);

    foreach ($tickets as $ticket) {
      if ($this->ticketPdfGenerator->canonicalPdfPreconditionsSatisfied($ticket)) {
        $pdfReady++;
        $holderReady++;
      }
      else {
        $pdfPending++;
      }

      if ($this->walletScaffoldPossible($ticket)) {
        $walletReady++;
      }
      else {
        $walletPending++;
      }

      if ($this->qrOperationalForTicket($ticket)) {
        $qrValid++;
      }
      else {
        $qrInvalid++;
      }
    }

    $total = count($tickets);

    return [
      'canonical_pdf_readiness' => $this->aggregateReadiness($pdfReady, $pdfPending, $total),
      'wallet_route_scaffold' => $this->aggregateReadiness($walletReady, $walletPending, $total),
      'qr_payload_operational' => $this->aggregateReadiness($qrValid, $qrInvalid, $total),
      'attachment_continuity' => $this->attachmentContinuityStatus($holderReady, $total),
      'entitlement_capability_policy' => $capability_policy,
    ];
  }

  /**
   * @param list<Ticket> $tickets
   *
   * @return array<string, array<string, mixed>>
   *   Machine-only capability diagnostics keyed by normalized entitlement type.
   */
  private function buildEntitlementCapabilityPolicyDigest(array $tickets): array {
    $digest = [];
    foreach ($tickets as $ticket) {
      $type = $this->ticketCapabilityManager->getEntitlementType($ticket);
      if (isset($digest[$type])) {
        continue;
      }
      $map = $this->entitlementCapabilityRegistry->getCapabilityMap($type);
      $digest[$type] = [
        'scanner_mode' => $map['scanner_mode'],
        'fulfilment_mode' => $map['fulfilment_mode'],
        'redeemable' => $map['redeemable'],
        'requires_fulfilment' => $map['requires_fulfilment'],
        'multi_use' => $map['multi_use'],
        'scan_required' => $map['scan_required'],
      ];
    }
    return $digest;
  }

  private function walletScaffoldPossible(Ticket $ticket): bool {
    return $this->readTargetId($ticket, 'order_item_id') > 0;
  }

  private function qrOperationalForTicket(Ticket $ticket): bool {
    try {
      $payload = $this->ticketQrPayload->buildForTicket($ticket);
      return trim($payload) !== '';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * @param list<Ticket> $tickets
   *
   * @return array<string, mixed>
   */
  private function buildRecoveryDomain(OrderInterface $order, array $tickets): array {
    $orderId = (int) $order->id();
    $stateKey = OrderPaidConfirmationPdfRecoverySubscriber::recoveryStateKey($orderId);
    $completedRaw = $this->state->get($stateKey, FALSE);
    $completed = $completedRaw !== FALSE && $completedRaw !== NULL && $completedRaw !== '';

    $late = 'unknown';
    $signal = 'unknown';
    $mismatch = FALSE;

    if ($this->messageStorageSupportsRecovery() && $tickets !== []) {
      $recipient = $this->orderRecipientMail($order);
      if ($recipient !== '') {
        $minCreated = $this->minTicketCreatedTimestamp($tickets);
        $sentRows = $this->messageStorage->findSentOrderConfirmationsForOrder($orderId, $recipient);
        $earliestSent = $this->earliestSentTimestamp($sentRows);
        if ($earliestSent !== NULL && $earliestSent > 0 && $minCreated !== NULL && $minCreated > $earliestSent) {
          $late = 'true';
        }
        else {
          $late = 'false';
        }

        $eligible = $this->orderHasVariationLineItemsEligibleForTickets($order);
        if ($eligible && $minCreated !== NULL && $earliestSent !== NULL && $earliestSent > 0 && $minCreated > $earliestSent) {
          $signal = 'pending';
        }
        else {
          $signal = 'none';
        }
      }
      else {
        $late = 'false';
        $signal = 'skipped';
      }
    }
    elseif ($tickets === []) {
      $late = 'false';
      $signal = 'none';
    }

    if ($completed) {
      $signal = 'recovered';
      $mismatch = FALSE;
    }
    elseif ($signal === 'pending' && !$completed) {
      $mismatch = TRUE;
    }

    return [
      'completion_state' => $completed ? 'recovered' : 'missing',
      'recovery_completed_recorded' => $completed,
      'late_tickets_after_confirmation' => $late,
      'recovery_requirement_signal' => $signal,
      'recovery_mismatch' => $mismatch,
    ];
  }

  /**
   * @param list<Ticket> $tickets
   *
   * @return array<string, mixed>
   */
  private function buildCompatibilityDomain(OrderInterface $order, array $tickets, array $artifacts): array {
    $orderItemSurface = $this->orderItemPdfSurfaceStatus($order);
    $walletSurface = $this->walletResolutionSurface($order, $tickets);
    $canonicalReadiness = $artifacts['canonical_pdf_readiness'] ?? 'missing';
    $pdfPath = $this->ticketPdfPathStatus($tickets, $canonicalReadiness);

    return [
      'ticket_pdf_operational_path' => $pdfPath,
      'order_item_pdf_surface' => $orderItemSurface,
      'wallet_resolution_surface' => $walletSurface,
    ];
  }

  /**
   * @param list<Ticket> $tickets
   *
   * @return array<string, mixed>
   */
  private function buildGuestContinuityDomain(OrderInterface $order, array $tickets): array {
    $customerId = (int) $order->getCustomerId();
    $ownership = TRUE;
    $purchaserContinuity = TRUE;

    foreach ($tickets as $ticket) {
      $purchaserUid = $this->readTargetId($ticket, 'purchaser_uid');
      if ($purchaserUid !== $customerId) {
        $ownership = FALSE;
      }
      if ($customerId > 0 && $purchaserUid !== $customerId) {
        $purchaserContinuity = FALSE;
      }
      if ($customerId === 0 && $purchaserUid !== 0) {
        $purchaserContinuity = FALSE;
      }
    }

    $guestAccess = $this->guestAccessPatternValid($order, $tickets, $customerId);

    $continuity = ($ownership && $purchaserContinuity && $guestAccess) ? 'valid' : 'invalid';

    return [
      'guest_access_compatible' => $guestAccess,
      'entitlement_ownership_valid' => $ownership,
      'purchaser_identity_continuity_valid' => $purchaserContinuity,
      'continuity_status' => $continuity,
    ];
  }

  /**
   * @param list<Ticket> $tickets
   */
  private function guestAccessPatternValid(OrderInterface $order, array $tickets, int $customerId): bool {
    if ($tickets === []) {
      return TRUE;
    }
    if ($customerId > 0) {
      return TRUE;
    }
    foreach ($tickets as $ticket) {
      if ((int) $ticket->getOwnerId() !== 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @param array<string, mixed> $issuance
   * @param array<string, mixed> $recovery
   */
  private function maybeLogAnomalies(int $orderId, array $issuance, array $recovery): void {
    $orphanTickets = (int) ($issuance['orphan_ticket_count'] ?? 0);
    $orphanItems = (int) ($issuance['orphan_eligible_order_item_count'] ?? 0);
    if ($orphanTickets > 0 || $orphanItems > 0) {
      $this->logger->warning('OperationalIntegrityInspector: orphan operational state for order @order_id orphan_tickets=@ot orphan_items=@oi', [
        '@order_id' => (string) $orderId,
        '@ot' => (string) $orphanTickets,
        '@oi' => (string) $orphanItems,
        'order_id' => $orderId,
        'orphan_ticket_count' => $orphanTickets,
        'orphan_eligible_order_item_count' => $orphanItems,
      ]);
    }

    $expected = (int) ($issuance['expected_quantity'] ?? 0);
    $issued = (int) ($issuance['issued_ticket_count'] ?? 0);
    if ($issued > $expected && $expected >= 0) {
      $this->logger->warning('OperationalIntegrityInspector: issuance count anomaly (possible duplicate guard context) order @order_id expected=@e issued=@i', [
        '@order_id' => (string) $orderId,
        '@e' => (string) $expected,
        '@i' => (string) $issued,
        'order_id' => $orderId,
        'expected_quantity' => $expected,
        'issued_ticket_count' => $issued,
      ]);
    }

    $partial = (bool) ($issuance['partial_issuance_blocked_by_idempotent_guard'] ?? FALSE);
    if ($partial) {
      $this->logger->warning('OperationalIntegrityInspector: partial issuance with idempotent guard active order @order_id expected=@e issued=@i', [
        '@order_id' => (string) $orderId,
        '@e' => (string) $expected,
        '@i' => (string) $issued,
        'order_id' => $orderId,
        'expected_quantity' => $expected,
        'issued_ticket_count' => $issued,
      ]);
    }

    if ($issued > 0 && $expected === 0) {
      $this->logger->warning('OperationalIntegrityInspector: duplicate_issuance_prevention_detection stray_ticket_rows order @order_id issued=@i', [
        '@order_id' => (string) $orderId,
        '@i' => (string) $issued,
        'order_id' => $orderId,
        'issued_ticket_count' => $issued,
      ]);
    }

    if (!empty($recovery['recovery_mismatch'])) {
      $this->logger->warning('OperationalIntegrityInspector: recovery mismatch detected order @order_id', [
        '@order_id' => (string) $orderId,
        'order_id' => $orderId,
      ]);
    }
  }

  /**
   * @return list<Ticket>
   */
  private function loadTicketsForOrder(int $orderId): array {
    if (!$this->entityTypeManager->hasDefinition('myeventlane_ticket')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('myeventlane_ticket');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId)
      ->sort('id')
      ->execute();
    if (!$ids || !is_array($ids)) {
      return [];
    }
    $loaded = $storage->loadMultiple($ids);
    $list = [];
    foreach ($loaded as $entity) {
      if ($entity instanceof Ticket) {
        $list[] = $entity;
      }
    }
    return $list;
  }

  /**
   * @return array<int, true>
   */
  private function orderItemIdSet(OrderInterface $order): array {
    $set = [];
    foreach ($order->getItems() as $item) {
      if ($item instanceof OrderItemInterface) {
        $set[(int) $item->id()] = TRUE;
      }
    }
    return $set;
  }

  /**
   * @param list<Ticket> $tickets
   */
  private function countOrphanTicketLinks(array $tickets, array $orderItemIds): int {
    $orphan = 0;
    foreach ($tickets as $ticket) {
      $oi = $this->readTargetId($ticket, 'order_item_id');
      if ($oi < 1 || !isset($orderItemIds[$oi])) {
        $orphan++;
      }
    }
    return $orphan;
  }

  /**
   * Eligible variation line items with fewer linked tickets than quantity.
   *
   * @param list<Ticket> $tickets
   */
  private function countOrphanEligibleOrderItems(OrderInterface $order, array $tickets): int {
    $byItem = [];
    foreach ($tickets as $ticket) {
      $oi = $this->readTargetId($ticket, 'order_item_id');
      if ($oi > 0) {
        $byItem[$oi] = ($byItem[$oi] ?? 0) + 1;
      }
    }

    $orphan = 0;
    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      $purchased = $item->getPurchasedEntity();
      if (!$purchased || $purchased->getEntityTypeId() !== 'commerce_product_variation') {
        continue;
      }
      if (!$this->ticketIssuer->isOrderItemEligibleForTicketIssuance($item)) {
        continue;
      }
      $qty = max(0, (int) $item->getQuantity());
      $have = (int) ($byItem[(int) $item->id()] ?? 0);
      if ($have < $qty) {
        $orphan++;
      }
    }
    return $orphan;
  }

  /**
   * True when the order has at least one variation line item TicketIssuer would attempt.
   */
  private function orderHasVariationLineItemsEligibleForTickets(OrderInterface $order): bool {
    return $this->ticketIssuer->countExpectedIssuanceUnits($order) > 0;
  }

  private function orderRecipientMail(OrderInterface $order): string {
    $mail = $order->getEmail();
    if (!$mail) {
      $customer = $order->getCustomer();
      $mail = $customer ? $customer->getEmail() : NULL;
    }
    return $mail ? trim((string) $mail) : '';
  }

  /**
   * @param list<Ticket> $tickets
   */
  private function minTicketCreatedTimestamp(array $tickets): ?int {
    $min = NULL;
    foreach ($tickets as $ticket) {
      $c = $this->ticketCreated($ticket);
      if ($c !== NULL && ($min === NULL || $c < $min)) {
        $min = $c;
      }
    }
    return $min;
  }

  private function ticketCreated(Ticket $ticket): ?int {
    if (!$ticket->hasField('created') || $ticket->get('created')->isEmpty()) {
      return NULL;
    }
    $created = (int) $ticket->get('created')->value;
    return $created > 0 ? $created : NULL;
  }

  /**
   * @param object[] $rows
   */
  private function earliestSentTimestamp(array $rows): ?int {
    $min = NULL;
    foreach ($rows as $row) {
      $s = isset($row->sent) ? (int) $row->sent : 0;
      if ($s > 0 && ($min === NULL || $s < $min)) {
        $min = $s;
      }
    }
    return $min;
  }

  private function orderItemPdfSurfaceStatus(OrderInterface $order): string {
    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      $purchased = $item->getPurchasedEntity();
      if ($purchased && $purchased->getEntityTypeId() === 'commerce_product_variation') {
        return 'legacy';
      }
    }
    return 'missing';
  }

  /**
   * @param list<Ticket> $tickets
   */
  private function walletResolutionSurface(OrderInterface $order, array $tickets): string {
    if (!$this->walletTicketResolver instanceof WalletTicketResolver) {
      return 'unknown';
    }
    if ($tickets === []) {
      return 'legacy';
    }
    $account = $this->resolveOrderAccount($order);
    $canonical = 0;
    $legacy = 0;
    foreach ($order->getItems() as $item) {
      if (!$item instanceof OrderItemInterface) {
        continue;
      }
      $purchased = $item->getPurchasedEntity();
      if (!$purchased || $purchased->getEntityTypeId() !== 'commerce_product_variation') {
        continue;
      }
      $count = $this->countTicketsForOrderItem($tickets, (int) $item->id());
      if ($count < 1) {
        $legacy++;
        continue;
      }
      $resolved = $this->walletTicketResolver->resolvePrimaryTicketForOrderItem($item, $account);
      $canonical += $resolved instanceof Ticket ? 1 : 0;
    }
    if ($canonical > 0 && $legacy > 0) {
      return 'mixed';
    }
    return $canonical > 0 ? 'canonical' : 'legacy';
  }

  /**
   * @param list<Ticket> $tickets
   */
  private function countTicketsForOrderItem(array $tickets, int $orderItemId): int {
    $n = 0;
    foreach ($tickets as $ticket) {
      if ($this->readTargetId($ticket, 'order_item_id') === $orderItemId) {
        $n++;
      }
    }
    return $n;
  }

  private function resolveOrderAccount(OrderInterface $order): AccountInterface {
    $uid = (int) $order->getCustomerId();
    if ($uid > 0) {
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if ($user instanceof UserInterface) {
        return $user;
      }
    }
    return new AnonymousUserSession();
  }

  /**
   * @param list<Ticket> $tickets
   */
  private function ticketPdfPathStatus(array $tickets, string $canonicalReadiness): string {
    if ($tickets === []) {
      return 'pending';
    }
    $viewModelOk = 0;
    $viewModelBad = 0;
    foreach ($tickets as $ticket) {
      if ($this->viewModelBuilds($ticket)) {
        $viewModelOk++;
      }
      else {
        $viewModelBad++;
      }
    }
    if ($viewModelOk > 0 && $viewModelBad > 0) {
      return 'mixed';
    }
    if ($canonicalReadiness === 'mixed') {
      return 'mixed';
    }
    if ($viewModelOk === count($tickets)) {
      return 'canonical';
    }
    if ($viewModelBad === count($tickets)) {
      return 'invalid';
    }
    return 'legacy';
  }

  private function viewModelBuilds(Ticket $ticket): bool {
    try {
      $model = $this->universalTicketViewModelBuilder->build($ticket);
      return isset($model['ticket']['code']) && $model['ticket']['code'] !== '';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  private function aggregateReadiness(int $ready, int $notReady, int $total): string {
    if ($total < 1) {
      return 'missing';
    }
    if ($ready === $total) {
      return 'valid';
    }
    if ($notReady === $total) {
      return $ready > 0 ? 'invalid' : 'pending';
    }
    return 'mixed';
  }

  private function attachmentContinuityStatus(int $holderReady, int $total): string {
    if ($total < 1) {
      return 'missing';
    }
    if ($holderReady === $total) {
      return 'valid';
    }
    if ($holderReady < 1) {
      return 'pending';
    }
    return 'mixed';
  }

  private function readTargetId(Ticket $ticket, string $field): int {
    if (!$ticket->hasField($field) || $ticket->get($field)->isEmpty()) {
      return 0;
    }
    return (int) $ticket->get($field)->target_id;
  }

  private function messageStorageSupportsRecovery(): bool {
    return is_object($this->messageStorage)
      && method_exists($this->messageStorage, 'findSentOrderConfirmationsForOrder');
  }

}
