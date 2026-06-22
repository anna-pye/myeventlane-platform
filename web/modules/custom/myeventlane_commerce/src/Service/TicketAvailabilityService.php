<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\mel_ticket\Entity\TicketWaitlistEntry;
use Drupal\myeventlane_capacity\Exception\CapacityExceededException;
use Drupal\myeventlane_capacity\Service\EventCapacityServiceInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Enforces mel_ticket_type rules, event capacity, and Commerce alignment at purchase.
 */
final class TicketAvailabilityService implements TicketCustomerDisplayGatewayInterface {

  use StringTranslationTrait;

  private const PURCHASABLE_CACHE_MAX_AGE = 90;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventCapacityServiceInterface $eventCapacity,
    private readonly LoggerInterface $logger,
    private readonly TicketVariationSoldService $variationSold,
    private readonly TimeInterface $time,
    private readonly TicketTierAccessService $tierAccess,
    private readonly TicketBookingSessionService $bookingSession,
    private readonly TicketTierWaitlistService $tierWaitlist,
    private readonly AccountProxyInterface $currentUser,
    TranslationInterface $string_translation,
    private readonly ?TicketAccessCodeService $accessCodeService = NULL,
    private readonly ?TicketGroupEligibilityService $groupEligibility = NULL,
    private readonly ?TicketCapacityService $capacity = NULL,
    private readonly ?CacheBackendInterface $cache = NULL,
    private readonly ?RouteMatchInterface $routeMatch = NULL,
    private readonly ?RequestStack $requestStack = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  public function buildPublicAccessContext(NodeInterface $event): TicketAccessContext {
    $eid = (int) $event->id();
    $account = $this->currentUser->getAccount();
    return new TicketAccessContext(
      eventId: $eid,
      account: $account,
      grantedTierIds: $this->resolveGrantedTierIds($event),
      waitlistClaimEntryId: $this->bookingSession->getWaitlistClaimEntryId($eid),
      groupEligible: $this->resolveGroupEligible($account, $event),
      routeSource: 'public_booking',
    );
  }

  /**
   * Anonymous default-customer context for vendor Studio preview (no grants).
   */
  public function buildDefaultCustomerAccessContext(NodeInterface $event): TicketAccessContext {
    return new TicketAccessContext(
      eventId: (int) $event->id(),
      account: NULL,
      grantedTierIds: [],
      waitlistClaimEntryId: NULL,
      groupEligible: FALSE,
      routeSource: 'public_booking',
    );
  }

  /**
   * @return list<int>
   */
  private function resolveGrantedTierIds(NodeInterface $event): array {
    if ($this->accessCodeService !== NULL) {
      return $this->accessCodeService->validateSessionGrants($event);
    }
    return $this->bookingSession->getUnlockedTierIds((int) $event->id());
  }

  private function resolveGroupEligible(AccountInterface $account, NodeInterface $event): bool {
    if ($this->groupEligibility !== NULL) {
      return $this->groupEligibility->isEligible($account, $event);
    }
    return FALSE;
  }

  private function cacheRouteName(): string {
    if ($this->routeMatch === NULL) {
      return '';
    }
    return (string) ($this->routeMatch->getRouteName() ?? '');
  }

  private function cacheSessionId(): string {
    if ($this->requestStack === NULL) {
      return 'ns';
    }
    $request = $this->requestStack->getCurrentRequest();
    if ($request && $request->hasSession()) {
      return $request->getSession()->getId();
    }
    return 'ns';
  }

  /**
   * Maps a Commerce variation to the mel_ticket_type row for this event, if any.
   */
  public function resolveTierForVariation(
    NodeInterface $event,
    ProductVariationInterface $variation,
  ): ?TicketTypeInterface {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return NULL;
    }
    foreach ($event->get('field_ticket_types')->referencedEntities() as $entity) {
      if (!$entity instanceof TicketTypeInterface) {
        continue;
      }
      if ($entity->isArchived()) {
        continue;
      }
      if ($entity->get('commerce_variation')->isEmpty()) {
        continue;
      }
      if ((int) $entity->get('commerce_variation')->target_id === (int) $variation->id()) {
        return $entity;
      }
    }
    return NULL;
  }

  /**
   * Variations that may appear on the ticket selection form for this product.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface[]
   */
  public function filterPurchasableVariations(
    NodeInterface $event,
    ProductInterface $product,
    ?TicketAccessContext $accessContext = NULL,
    bool $useCache = TRUE,
  ): array {
    $eid = (int) $event->id();
    $pid = (int) $product->id();
    $context = $accessContext ?? $this->buildPublicAccessContext($event);
    $route = $this->cacheRouteName();
    $sessionId = $this->cacheSessionId();
    $grants = $context->grantedTierIds;
    sort($grants, SORT_NUMERIC);
    $waitlistClaimId = $context->waitlistClaimEntryId ?? 0;
    $account_uid = $context->account !== NULL ? (int) $context->account->id() : 0;
    $cid = 'mel_ticket:purchasable_variations:' . hash('sha256', implode(':', [
      (string) $eid,
      (string) $pid,
      (string) $account_uid,
      $sessionId,
      $route,
      $context->routeSource,
      $context->groupEligible ? '1' : '0',
      implode(',', $grants),
      (string) $waitlistClaimId,
    ]));

    if ($useCache && $this->cache !== NULL && ($cached = $this->cache->get($cid))) {
      $ids = $cached->data;
      if (is_array($ids)) {
        $by_id = [];
        foreach ($product->getVariations() as $v) {
          $by_id[(int) $v->id()] = $v;
        }
        $out = [];
        foreach (array_unique(array_map(static fn ($id) => (int) $id, $ids)) as $vid) {
          if (isset($by_id[$vid]) && $by_id[$vid]->isPublished()) {
            $out[] = $by_id[$vid];
          }
        }
        return $out;
      }
    }

    $capacityMaps = $this->buildCapacityMapsForProduct($event, $product);

    $out = [];
    $seenVariationId = [];
    foreach ($product->getVariations() as $variation) {
      $variationId = (int) $variation->id();
      if (isset($seenVariationId[$variationId])) {
        continue;
      }
      $seenVariationId[$variationId] = TRUE;
      if (!$variation->isPublished()) {
        continue;
      }
      try {
        $this->assertPaidVariationLineConstraints($event, $product, $variation, 1, $context, $capacityMaps);
        $out[] = $variation;
      }
      catch (CapacityExceededException $e) {
        $this->logger->notice(
          'Variation @vid excluded from selection for event @eid: @msg',
          [
            '@vid' => (string) $variation->id(),
            '@eid' => (string) $event->id(),
            '@msg' => $e->getMessage(),
          ]
        );
      }
    }

    if ($useCache && $this->cache !== NULL) {
      $this->cache->set(
        $cid,
        array_map(static fn (ProductVariationInterface $v) => (int) $v->id(), $out),
        $this->time->getRequestTime() + self::PURCHASABLE_CACHE_MAX_AGE,
        [
          'commerce_product:' . (string) $pid,
          'node:' . (string) $eid,
        ]
      );
    }

    return $out;
  }

  /**
   * @return array{sold: array<int, int>, held: array<int, int>}
   */
  private function buildCapacityMapsForProduct(NodeInterface $event, ProductInterface $product): array {
    $eid = (int) $event->id();
    $variationIds = [];
    $tierIds = [];
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      foreach ($event->get('field_ticket_types')->referencedEntities() as $tier) {
        if (!$tier instanceof TicketTypeInterface || $tier->getTicketKind() !== 'paid') {
          continue;
        }
        if ($tier->isArchived()) {
          continue;
        }
        if ($tier->get('commerce_variation')->isEmpty()) {
          $this->logger->notice(
            'Tier @tid on event @e has no Commerce variation (capacity preload skipped for this tier).',
            ['@tid' => (string) $tier->id(), '@e' => (string) $eid]
          );
          continue;
        }
        $variation = $tier->get('commerce_variation')->entity;
        if (!$variation || (int) $variation->getProductId() !== (int) $product->id()) {
          continue;
        }
        $variationIds[] = (int) $variation->id();
        $tierIds[] = (int) $tier->id();
      }
    }

    if ($this->capacity !== NULL) {
      return [
        'sold' => $this->capacity->getSoldBatch($eid, $variationIds),
        'held' => $this->capacity->getHeldBatch($eid, $tierIds),
      ];
    }

    $soldMap = [];
    foreach ($variationIds as $vid) {
      $soldMap[$vid] = $this->variationSold->countCompletedSoldForVariation($eid, $vid);
    }
    $heldMap = [];
    foreach ($tierIds as $tid) {
      $heldMap[$tid] = $this->tierWaitlist->sumActiveOfferReserved($eid, $tid);
    }

    return [
      'sold' => $soldMap,
      'held' => $heldMap,
    ];
  }

  /**
   * Event-level cap (RSVP + paid totals) for a requested quantity.
   */
  public function assertEventTotalBookable(
    NodeInterface $event,
    int $totalQuantityForEvent,
    ?string $reservationKey = NULL,
  ): void {
    if ($totalQuantityForEvent <= 0) {
      return;
    }
    $this->eventCapacity->assertCanBook($event, $totalQuantityForEvent, $reservationKey);
  }

  /**
   * Tier line checks + event total in one call (cart / checkout).
   */
  public function assertPaidLineAndEventTotal(
    NodeInterface $event,
    ProductInterface $product,
    ProductVariationInterface $variation,
    int $lineQuantityForThisVariation,
    int $totalTicketQuantityForThisEvent,
    ?string $reservationKey = NULL,
  ): void {
    $this->assertPaidVariationLineConstraints($event, $product, $variation, $lineQuantityForThisVariation);
    $this->assertEventTotalBookable($event, $totalTicketQuantityForThisEvent, $reservationKey);
  }

  /**
   * Validates variation publish state, mapping, tier publish, sale window, price, tier cap.
   *
   * @param array{sold?: array<int, int>, held?: array<int, int>}|null $capacityMaps
   *   Optional preloaded sold (by variation id) and held (by tier id) counts.
   */
  public function assertPaidVariationLineConstraints(
    NodeInterface $event,
    ProductInterface $product,
    ProductVariationInterface $variation,
    int $lineQuantity,
    ?TicketAccessContext $context = NULL,
    ?array $capacityMaps = NULL,
  ): void {
    if ($lineQuantity <= 0) {
      return;
    }

    if (!$variation->isPublished()) {
      throw new CapacityExceededException('This ticket is not available.');
    }

    if ((int) $variation->getProductId() !== (int) $product->id()) {
      $this->logger->warning(
        'Ticket variation @vid does not belong to product @pid for event @eid.',
        [
          '@vid' => (string) $variation->id(),
          '@pid' => (string) $product->id(),
          '@eid' => (string) $event->id(),
        ]
      );
      throw new CapacityExceededException('This ticket is not available for this event.');
    }

    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      throw new CapacityExceededException('Ticketing is not configured for this event.');
    }
    $eventProduct = $event->get('field_product_target')->entity;
    if (!$eventProduct || (int) $eventProduct->id() !== (int) $product->id()) {
      $this->logger->warning(
        'Product mismatch for event @eid: cart product @pid vs event product.',
        ['@eid' => (string) $event->id(), '@pid' => (string) $product->id()]
      );
      throw new CapacityExceededException('This ticket is not available for this event.');
    }

    $tier = $this->resolveTierForVariation($event, $variation);
    if (!$tier instanceof TicketTypeInterface) {
      $this->logger->error(
        'No mel_ticket_type maps variation @vid for event @eid; blocking purchase.',
        ['@vid' => (string) $variation->id(), '@eid' => (string) $event->id()]
      );
      throw new CapacityExceededException('This ticket is not available for this event.');
    }

    if ($tier->isArchived()) {
      throw new CapacityExceededException('This ticket type is no longer available.');
    }

    if (!$tier->isPublished()) {
      throw new CapacityExceededException('This ticket type is not on sale.');
    }

    $context ??= $this->buildPublicAccessContext($event);

    if (!$this->tierAccess->canAccess($tier, $context->account, $context)) {
      $this->logger->notice(
        'Tier @tid blocked by access rules for event @eid.',
        ['@tid' => (string) $tier->id(), '@eid' => (string) $event->id()]
      );
      throw new CapacityExceededException('This ticket is not available.');
    }

    $this->assertPurchasableTicketStatus($tier, $event, $context);
    $this->assertGroupSaleQuantity($tier, $lineQuantity);
    $this->assertPriceMatchesTier($tier, $variation);
    $this->assertTierCapacity($event, $tier, $variation, $lineQuantity, $context, $capacityMaps);
  }

  /**
   * Aligns checkout with mel_ticket_type status (sale window, publish, sold out).
   */
  private function assertPurchasableTicketStatus(
    TicketTypeInterface $tier,
    NodeInterface $event,
    TicketAccessContext $context,
  ): void {
    $status = TicketStatusEvaluator::evaluate($tier, $this->variationSold, $this->time, $event);
    if ($status === TicketStatusEvaluator::STATUS_ACTIVE) {
      return;
    }

    if ($status === TicketStatusEvaluator::STATUS_SOLD_OUT) {
      $account = $context->account;
      $claimId = $context->waitlistClaimEntryId;
      $offer = $this->tierWaitlist->findActiveOfferForBuyer(
        (int) $event->id(),
        (int) $tier->id(),
        $account instanceof \Drupal\Core\Session\AccountInterface ? $account : NULL,
        $claimId ?: NULL,
      );
      if ($offer) {
        return;
      }
    }

    $message = match ($status) {
      TicketStatusEvaluator::STATUS_ARCHIVED => 'This ticket type is no longer available.',
      TicketStatusEvaluator::STATUS_INACTIVE => 'This ticket type is not on sale.',
      TicketStatusEvaluator::STATUS_UPCOMING => 'Sales have not started for this ticket yet.',
      TicketStatusEvaluator::STATUS_ENDED => 'Sales have ended for this ticket type.',
      TicketStatusEvaluator::STATUS_SOLD_OUT => 'This ticket type is sold out.',
      default => 'This ticket is not available.',
    };
    throw new CapacityExceededException($message);
  }

  private function assertGroupSaleQuantity(TicketTypeInterface $tier, int $lineQuantity): void {
    if ($tier->getTicketKind() !== 'paid' || !$tier->hasField('group_sale_mode')) {
      return;
    }
    $mode = (string) ($tier->get('group_sale_mode')->value ?? 'none');
    if ($mode === 'none' || $lineQuantity <= 0) {
      return;
    }

    if ($mode === 'fixed_bundle' || $mode === 'reserved_block') {
      $b = $tier->get('group_bundle_size')->isEmpty() ? 0 : (int) $tier->get('group_bundle_size')->value;
      if ($b >= 2 && $lineQuantity % $b !== 0) {
        throw new CapacityExceededException("This ticket must be purchased in multiples of {$b}.");
      }
    }
    elseif ($mode === 'minimum_group_size') {
      $m = $tier->get('group_min_size')->isEmpty() ? 0 : (int) $tier->get('group_min_size')->value;
      if ($m >= 2 && $lineQuantity < $m) {
        throw new CapacityExceededException("At least {$m} tickets are required for this ticket type.");
      }
    }
  }

  private function assertPriceMatchesTier(TicketTypeInterface $tier, ProductVariationInterface $variation): void {
    $tierPrice = $tier->toPriceValue();
    $varPrice = $variation->getPrice();
    if ($tierPrice === NULL || $varPrice === NULL) {
      $this->logger->error(
        'Missing price on tier @tid or variation @vid.',
        ['@tid' => (string) $tier->id(), '@vid' => (string) $variation->id()]
      );
      throw new CapacityExceededException('This ticket price is temporarily unavailable.');
    }
    if (strtoupper($tierPrice->getCurrencyCode()) !== strtoupper($varPrice->getCurrencyCode())) {
      $this->logger->error(
        'Currency mismatch tier @tid vs variation @vid.',
        ['@tid' => (string) $tier->id(), '@vid' => (string) $variation->id()]
      );
      throw new CapacityExceededException('This ticket is not available for purchase.');
    }
    $tierNum = (string) $tierPrice->getNumber();
    $varNum = (string) $varPrice->getNumber();
    $pricesEqual = function_exists('bccomp')
      ? bccomp($tierNum, $varNum, 6) === 0
      : abs((float) $tierNum - (float) $varNum) < 0.000001;
    if (!$pricesEqual) {
      $this->logger->error(
        'Price drift tier @tid vs variation @vid.',
        ['@tid' => (string) $tier->id(), '@vid' => (string) $variation->id()]
      );
      throw new CapacityExceededException('This ticket is not available for purchase.');
    }
  }

  /**
   * @param array{sold?: array<int, int>, held?: array<int, int>}|null $capacityMaps
   */
  private function assertTierCapacity(
    NodeInterface $event,
    TicketTypeInterface $tier,
    ProductVariationInterface $variation,
    int $requestedTotal,
    TicketAccessContext $context,
    ?array $capacityMaps = NULL,
  ): void {
    if ($tier->get('capacity')->isEmpty()) {
      return;
    }
    $cap = (int) $tier->get('capacity')->value;
    if ($cap < 1) {
      return;
    }

    $eid = (int) $event->id();
    $tid = (int) $tier->id();
    $vid = (int) $variation->id();

    $soldMap = is_array($capacityMaps['sold'] ?? NULL) ? $capacityMaps['sold'] : [];
    $heldMap = is_array($capacityMaps['held'] ?? NULL) ? $capacityMaps['held'] : [];

    $sold = $soldMap[$vid] ?? $this->capacitySoldCount($eid, $vid);
    $held = $heldMap[$tid] ?? $this->capacityHeldCount($eid, $tid);

    $pool = $cap - $sold - $held;
    if ($pool < 0) {
      $this->logger->error(
        'Capacity mismatch for event @e tier @t: pool negative (@pool) sold @s held @h cap @c.',
        [
          '@e' => (string) $eid,
          '@t' => (string) $tid,
          '@pool' => (string) $pool,
          '@s' => (string) $sold,
          '@h' => (string) $held,
          '@c' => (string) $cap,
        ]
      );
    }

    $account = $context->account;
    $claimId = $context->waitlistClaimEntryId;
    $offer = $this->tierWaitlist->findActiveOfferForBuyer(
      $eid,
      $tid,
      $account instanceof \Drupal\Core\Session\AccountInterface ? $account : NULL,
      $claimId ?: NULL,
    );

    if ($offer) {
      $maxBuyer = $this->buyerLimitForActiveOffer($event, $tier, $vid, $offer);
      if ($requestedTotal > $maxBuyer) {
        throw new CapacityExceededException($maxBuyer > 0
          ? "You can purchase at most {$maxBuyer} ticket(s) for this offer."
          : 'This ticket offer is no longer available.');
      }
      return;
    }

    if ($requestedTotal > max(0, $pool)) {
      $remaining = max(0, $pool);
      throw new CapacityExceededException($remaining > 0
        ? "Only {$remaining} ticket(s) remaining for this type."
        : 'This ticket type is sold out.');
    }
  }

  /**
   * Completed-order quantity for this variation and event.
   */
  public function countCompletedSoldForVariation(int $eventId, int $variationId): int {
    return $this->variationSold->countCompletedSoldForVariation($eventId, $variationId);
  }

  /**
   * Vendor-only reason a saved tier is not on the public book matrix.
   */
  public function explainVendorPreviewExclusion(
    NodeInterface $event,
    ProductInterface $product,
    TicketTypeInterface $tier,
    ?ProductVariationInterface $variation,
    TicketAccessContext $context,
  ): string {
    if (!$tier->isPublished()) {
      return (string) $this->t('Hidden from checkout: this ticket is not published.');
    }

    if ($variation === NULL || !$variation->isPublished()) {
      return (string) $this->t('Hidden from checkout: linked product variation is missing or unpublished.');
    }

    $visibility = $this->tierAccess->visibilityReason($tier, $context->account, $context);
    $visibility_copy = match ($visibility) {
      TicketTierAccessService::REASON_HIDDEN => (string) $this->t('Hidden from checkout: ticket is not visible to this customer.'),
      TicketTierAccessService::REASON_ACCESS_CODE_REQUIRED => (string) $this->t('Hidden from checkout: ticket requires an access code for this customer.'),
      TicketTierAccessService::REASON_GROUP_ONLY => (string) $this->t('Hidden from checkout: ticket is not visible to this customer.'),
      default => NULL,
    };
    if ($visibility_copy !== NULL) {
      return $visibility_copy;
    }

    $status = TicketStatusEvaluator::evaluate($tier, $this->variationSold, $this->time, $event);
    $status_copy = match ($status) {
      TicketStatusEvaluator::STATUS_UPCOMING => (string) $this->t('Hidden from checkout: sale has not started.'),
      TicketStatusEvaluator::STATUS_ENDED => (string) $this->t('Hidden from checkout: sale has ended.'),
      TicketStatusEvaluator::STATUS_INACTIVE => (string) $this->t('Hidden from checkout: this ticket is not on sale.'),
      TicketStatusEvaluator::STATUS_ARCHIVED => (string) $this->t('Hidden from checkout: this ticket is archived.'),
      TicketStatusEvaluator::STATUS_SOLD_OUT => (string) $this->t('Hidden from checkout: sold out.'),
      default => NULL,
    };
    if ($status_copy !== NULL) {
      return $status_copy;
    }

    try {
      $this->assertPaidVariationLineConstraints($event, $product, $variation, 1, $context);
      return (string) $this->t('Hidden from checkout: ticket is not available on the book page.');
    }
    catch (CapacityExceededException $exception) {
      return $this->mapPurchasabilityExceptionToVendorDiagnostic($exception->getMessage());
    }
  }

  private function mapPurchasabilityExceptionToVendorDiagnostic(string $message): string {
    $message = trim($message);
    if ($message === '') {
      return (string) $this->t('Hidden from checkout: ticket is not available on the book page.');
    }
    if (str_contains($message, 'Ticketing is not configured')) {
      return (string) $this->t('Hidden from checkout: ticketing is not configured for this event.');
    }
    if (str_contains($message, 'not available for this event')) {
      return (string) $this->t('Hidden from checkout: linked product variation is not mapped to this event.');
    }
    if (str_contains($message, 'temporarily unavailable')
      || str_contains($message, 'not available for purchase')
      || str_contains($message, 'price')) {
      return (string) $this->t('Hidden from checkout: linked product variation price does not match this ticket.');
    }
    if (str_contains($message, 'not started') || str_contains($message, 'Sales have not started')) {
      return (string) $this->t('Hidden from checkout: sale has not started.');
    }
    if (str_contains($message, 'ended') || str_contains($message, 'Sales have ended')) {
      return (string) $this->t('Hidden from checkout: sale has ended.');
    }
    if (str_contains($message, 'sold out') || str_contains($message, 'remaining')) {
      return (string) $this->t('Hidden from checkout: sold out.');
    }
    if (str_contains($message, 'no longer available')) {
      return (string) $this->t('Hidden from checkout: this ticket is archived.');
    }
    if (str_contains($message, 'not on sale')) {
      return (string) $this->t('Hidden from checkout: this ticket is not on sale.');
    }
    if ($message === 'This ticket is not available.') {
      return (string) $this->t('Hidden from checkout: ticket is not visible to this customer.');
    }
    return (string) $this->t('Hidden from checkout: @reason', ['@reason' => $message]);
  }

  private function capacitySoldCount(int $eventId, int $variationId): int {
    if ($this->capacity !== NULL) {
      return $this->capacity->getSold($eventId, $variationId);
    }
    return $this->variationSold->countCompletedSoldForVariation($eventId, $variationId);
  }

  private function capacityHeldCount(int $eventId, int $tierId): int {
    if ($this->capacity !== NULL) {
      return $this->capacity->getHeld($eventId, $tierId);
    }
    return $this->tierWaitlist->sumActiveOfferReserved($eventId, $tierId);
  }

  private function buyerLimitForActiveOffer(
    NodeInterface $event,
    TicketTypeInterface $tier,
    int $variationId,
    TicketWaitlistEntry $offer,
  ): int {
    if ($this->capacity !== NULL) {
      return $this->capacity->getBuyerLimitForOffer($event, $tier, $variationId, $offer);
    }
    if ($tier->get('capacity')->isEmpty()) {
      return (int) ($offer->get('offer_reserved')->value ?? 0);
    }
    $cap = (int) $tier->get('capacity')->value;
    if ($cap < 1) {
      return (int) ($offer->get('offer_reserved')->value ?? 0);
    }
    $sold = $this->variationSold->countCompletedSoldForVariation((int) $event->id(), $variationId);
    $reserved = (int) ($offer->get('offer_reserved')->value ?? 0);
    return min($reserved, max(0, $cap - $sold));
  }

}
