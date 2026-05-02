<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;

/**
 * Validates mel_access_code rows and resolves unlocked mel_ticket_type IDs.
 *
 * Does not typehint AccessCode to avoid a module dependency cycle with
 * myeventlane_tickets (tickets → vendor → commerce).
 */
final class TicketAccessCodeService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
    private readonly TicketBookingSessionService $bookingSession,
  ) {}

  /**
   * Resolves tier IDs unlocked by a code for this event product.
   *
   * @return list<int>
   *
   * @throws \InvalidArgumentException
   */
  public function resolveUnlockedTierIds(NodeInterface $event, ProductInterface $product, string $rawCode): array {
    return $this->resolveUnlockedTierIdsWithCodeId($event, $product, $rawCode, NULL)['tier_ids'];
  }

  /**
   * @return array{tier_ids: list<int>, access_code_id: int}
   *
   * @throws \InvalidArgumentException
   */
  public function resolveUnlockedTierIdsWithCodeId(
    NodeInterface $event,
    ProductInterface $product,
    string $rawCode,
    ?int $sessionActiveCodeId = NULL,
  ): array {
    $entity = $this->loadActiveCodeEntityForEvent($event, $rawCode);
    $tierIds = $this->buildTierIdsForCodeEntity($event, $product, $entity);
    if ($tierIds === []) {
      throw new \InvalidArgumentException('This access code does not unlock any tickets for this event.');
    }

    $resolvedId = (int) $entity->id();
    // Do not burn usage_limit on repeated "Apply" for the same code in-session.
    if ($sessionActiveCodeId === NULL || $sessionActiveCodeId !== $resolvedId) {
      $count = (int) ($entity->get('usage_count')->value ?? 0);
      $entity->set('usage_count', $count + 1);
      $entity->save();
    }

    return [
      'tier_ids' => array_values(array_unique($tierIds)),
      'access_code_id' => $resolvedId,
    ];
  }

  /**
   * Re-validates session tier grants against the stored access code row (no usage increment).
   *
   * @return list<int>
   */
  public function validateSessionGrants(NodeInterface $event): array {
    $eid = (int) $event->id();
    $sessionTiers = $this->bookingSession->getUnlockedTierIds($eid);
    if ($sessionTiers === []) {
      return [];
    }

    $codeId = $this->bookingSession->getAccessCodeId($eid);
    $productId = $this->bookingSession->getAccessProductId($eid);
    if (!$codeId || !$productId) {
      $this->loggerFactory->get('myeventlane_commerce')->notice(
        'Discarding unverifiable session tier grants for event @e (missing access code metadata).',
        ['@e' => (string) $eid]
      );
      return [];
    }

    if (!$this->entityTypeManager->hasDefinition('mel_access_code')) {
      return [];
    }

    $entity = $this->entityTypeManager->getStorage('mel_access_code')->load($codeId);
    if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() !== 'mel_access_code') {
      $this->loggerFactory->get('myeventlane_commerce')->notice(
        'Session access code @id missing; clearing grants for event @e.',
        ['@id' => (string) $codeId, '@e' => (string) $eid]
      );
      return [];
    }

    if ((int) $entity->get('event')->target_id !== $eid) {
      $this->loggerFactory->get('myeventlane_commerce')->warning(
        'Session access code @id event mismatch for event @e.',
        ['@id' => (string) $codeId, '@e' => (string) $eid]
      );
      return [];
    }

    if ((string) $entity->get('status')->value !== 'active') {
      return [];
    }
    if ($this->codeEntityIsExpired($entity)) {
      return [];
    }
    if ($this->codeEntityReachedLimit($entity)) {
      return [];
    }

    $product = $this->entityTypeManager->getStorage('commerce_product')->load($productId);
    if (!$product instanceof ProductInterface) {
      return [];
    }

    if (!$event->hasField('field_product_target') || $event->get('field_product_target')->isEmpty()) {
      return [];
    }
    $eventProduct = $event->get('field_product_target')->entity;
    if (!$eventProduct || (int) $eventProduct->id() !== (int) $product->id()) {
      $this->loggerFactory->get('myeventlane_commerce')->notice(
        'Session ticket product @p no longer matches event @e product; clearing access grants.',
        ['@p' => (string) $productId, '@e' => (string) $eid]
      );
      return [];
    }

    try {
      $valid = $this->buildTierIdsForCodeEntity($event, $product, $entity);
    }
    catch (\InvalidArgumentException) {
      return [];
    }

    $valid = array_values(array_unique($valid));
    return array_values(array_intersect($sessionTiers, $valid));
  }

  /**
   * @return list<int>
   *
   * @throws \InvalidArgumentException
   */
  private function buildTierIdsForCodeEntity(
    NodeInterface $event,
    ProductInterface $product,
    ContentEntityInterface $entity,
  ): array {
    $tierIds = [];
    if ($entity->hasField('allowed_ticket_types') && !$entity->get('allowed_ticket_types')->isEmpty()) {
      foreach ($entity->get('allowed_ticket_types')->referencedEntities() as $tier) {
        if ($tier instanceof TicketTypeInterface) {
          $tierIds[] = (int) $tier->id();
        }
      }
    }
    elseif ($entity->hasField('allowed_ticket_products') && !$entity->get('allowed_ticket_products')->isEmpty()) {
      $allowedPids = [];
      foreach ($entity->get('allowed_ticket_products')->referencedEntities() as $p) {
        if ($p && $p->id()) {
          $allowedPids[] = (int) $p->id();
        }
      }
      if ($allowedPids && !in_array((int) $product->id(), $allowedPids, TRUE)) {
        throw new \InvalidArgumentException('This access code does not apply to these tickets.');
      }
      $tierIds = $this->collectPaidTierIdsForProduct($event, $product);
    }
    else {
      $tierIds = $this->collectPaidTierIdsForProduct($event, $product);
    }

    if ($entity->hasField('allowed_ticket_types') && !$entity->get('allowed_ticket_types')->isEmpty()) {
      $tierIds = $this->filterTiersAttachedToEvent($event, $tierIds);
    }

    return $tierIds;
  }

  /**
   * @throws \InvalidArgumentException
   */
  private function loadActiveCodeEntityForEvent(NodeInterface $event, string $rawCode): ContentEntityInterface {
    if (!$this->entityTypeManager->hasDefinition('mel_access_code')) {
      throw new \InvalidArgumentException('Access codes are not available on this site.');
    }
    $code = trim($rawCode);
    if ($code === '') {
      throw new \InvalidArgumentException('Enter an access code.');
    }

    $storage = $this->entityTypeManager->getStorage('mel_access_code');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event->id())
      ->condition('code', $code)
      ->range(0, 2)
      ->execute();
    if (!$ids) {
      $this->loggerFactory->get('myeventlane_commerce')->notice(
        'Ticket access code miss for event @e.',
        ['@e' => (string) $event->id()]
      );
      throw new \InvalidArgumentException('That access code is not valid for this event.');
    }
    if (count($ids) > 1) {
      $this->loggerFactory->get('myeventlane_commerce')->error(
        'Duplicate access code rows for event @e; refusing match.',
        ['@e' => (string) $event->id()]
      );
      throw new \InvalidArgumentException('Access code configuration error. Contact the organiser.');
    }
    $entity = $storage->load(reset($ids));
    if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() !== 'mel_access_code') {
      throw new \InvalidArgumentException('That access code is not valid for this event.');
    }
    if ((string) $entity->get('status')->value !== 'active') {
      throw new \InvalidArgumentException('This access code is not active.');
    }
    if ($this->codeEntityIsExpired($entity)) {
      throw new \InvalidArgumentException('This access code has expired.');
    }
    if ($this->codeEntityReachedLimit($entity)) {
      throw new \InvalidArgumentException('This access code has reached its usage limit.');
    }

    return $entity;
  }

  private function codeEntityIsExpired(ContentEntityInterface $entity): bool {
    if (!$entity->hasField('expires') || $entity->get('expires')->isEmpty()) {
      return FALSE;
    }
    $value = $entity->get('expires')->value;
    if ($value === NULL || $value === '') {
      return FALSE;
    }
    $expires = strtotime((string) $value);
    return $expires > 0 && $expires < $this->time->getRequestTime();
  }

  private function codeEntityReachedLimit(ContentEntityInterface $entity): bool {
    if (!$entity->hasField('usage_limit') || $entity->get('usage_limit')->isEmpty()) {
      return FALSE;
    }
    $limit = (int) $entity->get('usage_limit')->value;
    $count = (int) ($entity->get('usage_count')->value ?? 0);
    return $limit > 0 && $count >= $limit;
  }

  /**
   * @param list<int> $tierIds
   *
   * @return list<int>
   */
  private function filterTiersAttachedToEvent(NodeInterface $event, array $tierIds): array {
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return [];
    }
    $allowed = [];
    foreach ($event->get('field_ticket_types')->referencedEntities() as $t) {
      if ($t instanceof TicketTypeInterface && !$t->isArchived()) {
        $allowed[(int) $t->id()] = TRUE;
      }
    }
    $out = [];
    foreach ($tierIds as $id) {
      if (isset($allowed[(int) $id])) {
        $out[] = (int) $id;
      }
    }
    return $out;
  }

  /**
   * @return list<int>
   */
  private function collectPaidTierIdsForProduct(NodeInterface $event, ProductInterface $product): array {
    $out = [];
    if (!$event->hasField('field_ticket_types') || $event->get('field_ticket_types')->isEmpty()) {
      return $out;
    }
    foreach ($event->get('field_ticket_types')->referencedEntities() as $tier) {
      if (!$tier instanceof TicketTypeInterface || $tier->getTicketKind() !== 'paid') {
        continue;
      }
      if ($tier->isArchived()) {
        continue;
      }
      if ($tier->get('commerce_variation')->isEmpty()) {
        $this->loggerFactory->get('myeventlane_commerce')->notice(
          'Paid tier @tid on event @e has no Commerce variation mapped.',
          ['@tid' => (string) $tier->id(), '@e' => (string) $event->id()]
        );
        continue;
      }
      $variation = $tier->get('commerce_variation')->entity;
      if ($variation && (int) $variation->getProductId() === (int) $product->id()) {
        $out[] = (int) $tier->id();
      }
    }
    return $out;
  }

}
