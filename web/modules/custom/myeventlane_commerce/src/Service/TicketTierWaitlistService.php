<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\mel_ticket\Entity\TicketWaitlistEntry;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Tier waitlist queue, offers, and capacity holds for paid tickets.
 */
final class TicketTierWaitlistService {

  private const OFFER_TTL = 172800;

  private const PROMOTE_LOCK_TIMEOUT = 15;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TicketVariationSoldService $variationSold,
    private readonly QueueFactory $queueFactory,
    private readonly ?TicketCapacityService $capacity = NULL,
    private readonly ?LockBackendInterface $lock = NULL,
    private readonly ?TicketBookingSessionService $bookingSession = NULL,
    private readonly ?TranslationInterface $stringTranslation = NULL,
  ) {}

  /**
   * Whether waitlist entity storage tables exist (after hook_update / install).
   */
  private function waitlistSchemaReady(): bool {
    if (!$this->entityTypeManager->hasDefinition('mel_ticket_waitlist_entry')) {
      return FALSE;
    }
    $definition = $this->entityTypeManager->getDefinition('mel_ticket_waitlist_entry', FALSE);
    if (!$definition) {
      return FALSE;
    }
    $base = $definition->getBaseTable();
    return $base !== '' && $this->database->schema()->tableExists($base);
  }

  /**
   * Adds a waiting entry if capacity rules allow.
   *
   * @throws \InvalidArgumentException
   */
  public function joinWaitlist(
    NodeInterface $event,
    TicketTypeInterface $tier,
    string $mail,
    int $quantity,
    ?AccountInterface $account,
  ): TicketWaitlistEntry {
    $mailDisplay = trim($mail);
    if ($mailDisplay === '' || !filter_var($mailDisplay, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('A valid email address is required.');
    }
    if ($quantity < 1) {
      throw new \InvalidArgumentException('Quantity must be at least 1.');
    }
    if (!$tier->hasField('waitlist_enabled') || !$tier->get('waitlist_enabled')->value) {
      throw new \InvalidArgumentException('Waitlist is not enabled for this ticket type.');
    }
    if ($tier->getTicketKind() !== 'paid') {
      throw new \InvalidArgumentException('Waitlist applies to paid tiers only.');
    }

    $status = TicketStatusEvaluator::evaluate($tier, $this->variationSold, $this->time, $event);
    if ($status !== TicketStatusEvaluator::STATUS_SOLD_OUT) {
      throw new \InvalidArgumentException('Waitlist is only available when this tier is sold out.');
    }

    if (!$this->waitlistSchemaReady()) {
      $this->loggerFactory->get('myeventlane_commerce')->error(
        'Tier waitlist join blocked: database table for mel_ticket_waitlist_entry is missing. Run database updates (drush updb).'
      );
      throw new \InvalidArgumentException('Waitlist is temporarily unavailable. Please try again later.');
    }

    if (!$tier->get('waitlist_capacity')->isEmpty()) {
      $cap = (int) $tier->get('waitlist_capacity')->value;
      if ($cap > 0) {
        $waiting = $this->countByStatus($event, $tier, TicketWaitlistEntry::STATUS_WAITING);
        if ($waiting >= $cap) {
          throw new \InvalidArgumentException('The waitlist is full for this ticket type.');
        }
      }
    }

    $mailHash = hash('sha256', mb_strtolower($mailDisplay));

    $storage = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry');
    /** @var \Drupal\mel_ticket\Entity\TicketWaitlistEntry $entry */
    $entry = $storage->create([
      'event' => ['target_id' => $event->id()],
      'ticket_type' => ['target_id' => $tier->id()],
      'mail' => $mailDisplay,
      'mail_hash' => $mailHash,
      'quantity' => $quantity,
      'status' => TicketWaitlistEntry::STATUS_WAITING,
      'uid' => ($account instanceof AccountInterface && $account->isAuthenticated())
        ? ['target_id' => (int) $account->id()]
        : NULL,
    ]);
    $entry->save();

    $this->loggerFactory->get('myeventlane_commerce')->info(
      'Tier waitlist join: event @e tier @t email @m qty @q',
      [
        '@e' => (string) $event->id(),
        '@t' => (string) $tier->id(),
        '@m' => $mailDisplay,
        '@q' => (string) $quantity,
      ]
    );

    return $entry;
  }

  public function loadOfferByToken(string $token): ?TicketWaitlistEntry {
    $token = trim($token);
    if ($token === '' || !$this->waitlistSchemaReady()) {
      return NULL;
    }
    $ids = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry')->getQuery()
      ->accessCheck(FALSE)
      ->condition('offer_token', $token)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $id = reset($ids);
    $entry = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry')->load($id);
    return $entry instanceof TicketWaitlistEntry ? $entry : NULL;
  }

  public function isOfferClaimable(TicketWaitlistEntry $entry): bool {
    if ($entry->get('status')->value !== TicketWaitlistEntry::STATUS_OFFERED) {
      return FALSE;
    }
    if ($entry->get('offer_expires')->isEmpty()) {
      return FALSE;
    }
    $expires = (int) $entry->get('offer_expires')->value;
    return $expires > $this->time->getRequestTime();
  }

  public function sumActiveOfferReserved(int $eventId, int $tierId): int {
    if ($this->capacity !== NULL) {
      return $this->capacity->getHeld($eventId, $tierId);
    }
    return $this->sumActiveOfferReservedLegacy($eventId, $tierId);
  }

  /**
   * Held seats from active waitlist offers (when TicketCapacityService is not wired).
   */
  private function sumActiveOfferReservedLegacy(int $eventId, int $tierId): int {
    if (!$this->waitlistSchemaReady()) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $ids = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry')->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('ticket_type', $tierId)
      ->condition('status', TicketWaitlistEntry::STATUS_OFFERED)
      ->condition('offer_expires', $now, '>')
      ->execute();
    if (!$ids) {
      return 0;
    }
    $entries = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry')->loadMultiple($ids);
    $sum = 0;
    foreach ($entries as $entry) {
      if ($entry instanceof TicketWaitlistEntry) {
        $sum += (int) ($entry->get('offer_reserved')->value ?? 0);
      }
    }
    return $sum;
  }

  /**
   * UI-oriented summary for the session-claimed waitlist offer (not an access check).
   *
   * Clears the session claim when the entry is missing, cross-event, not offered,
   * expired, or has no reserved quantity.
   *
   * @return array<string, mixed>|null
   *   Keys include entry_id and ticket_type_id for server-side use only; do not
   *   surface entry_id in templates.
   */
  public function getActiveOfferSummaryForEvent(int $eventId, ?int $claimedEntryId): ?array {
    if ($claimedEntryId === NULL || $claimedEntryId < 1 || $this->bookingSession === NULL || $this->stringTranslation === NULL) {
      return NULL;
    }
    if (!$this->waitlistSchemaReady()) {
      $this->bookingSession->clearWaitlistClaimEntryId($eventId);
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry');
    $entry = $storage->load($claimedEntryId);
    if (!$entry instanceof TicketWaitlistEntry) {
      $this->loggerFactory->get('myeventlane_commerce')->notice(
        'Cleared stale waitlist session claim: entry @id not found (event @e).',
        ['@id' => (string) $claimedEntryId, '@e' => (string) $eventId]
      );
      $this->bookingSession->clearWaitlistClaimEntryId($eventId);
      return NULL;
    }
    if ((int) $entry->get('event')->target_id !== $eventId) {
      $this->loggerFactory->get('myeventlane_commerce')->notice(
        'Cleared waitlist session claim: entry @id does not match event @e.',
        ['@id' => (string) $claimedEntryId, '@e' => (string) $eventId]
      );
      $this->bookingSession->clearWaitlistClaimEntryId($eventId);
      return NULL;
    }
    $status = (string) $entry->get('status')->value;
    $reserved = (int) ($entry->get('offer_reserved')->value ?? 0);
    $expires = $entry->get('offer_expires')->isEmpty() ? 0 : (int) $entry->get('offer_expires')->value;
    $now = $this->time->getRequestTime();
    if ($status !== TicketWaitlistEntry::STATUS_OFFERED || $reserved < 1 || $expires <= $now || !$this->isOfferClaimable($entry)) {
      $this->loggerFactory->get('myeventlane_commerce')->notice(
        'Cleared waitlist session claim: entry @id no longer a valid offer (status @s, reserved @r).',
        ['@id' => (string) $claimedEntryId, '@s' => $status, '@r' => (string) $reserved]
      );
      $this->bookingSession->clearWaitlistClaimEntryId($eventId);
      return NULL;
    }
    $tier = $entry->get('ticket_type')->entity;
    $tier_title = $tier instanceof TicketTypeInterface ? $tier->label() : (string) $this->stringTranslation->translate('Your ticket');
    if (strpos($tier_title, ' – ') !== FALSE) {
      $parts = explode(' – ', $tier_title, 2);
      $tier_title = $parts[1] ?? $tier_title;
    }
    $seconds_remaining = max(0, $expires - $now);
    $dt = (new \DateTimeImmutable('@' . $expires))->setTimezone(new \DateTimeZone('UTC'));
    return [
      'entry_id' => (int) $entry->id(),
      'ticket_type_id' => $tier instanceof TicketTypeInterface ? (int) $tier->id() : 0,
      'tier_title' => $tier_title,
      'reserved_quantity' => $reserved,
      'expires_timestamp' => $expires,
      'expires_iso8601' => $dt->format('c'),
      'seconds_remaining' => $seconds_remaining,
      'claim_active' => TRUE,
      'status_label' => (string) $this->stringTranslation->translate('Reserved for you'),
      'helper_message' => (string) $this->stringTranslation->translate('Complete your booking before this reserved window closes.'),
    ];
  }

  public function findActiveOfferForBuyer(int $eventId, int $tierId, ?AccountInterface $account, ?int $claimedEntryId): ?TicketWaitlistEntry {
    if (!$this->waitlistSchemaReady()) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry');
    if ($claimedEntryId) {
      $entry = $storage->load($claimedEntryId);
      if ($entry instanceof TicketWaitlistEntry
        && (int) $entry->get('event')->target_id === $eventId
        && (int) $entry->get('ticket_type')->target_id === $tierId
        && $this->isOfferClaimable($entry)) {
        return $entry;
      }
    }
    if ($account instanceof AccountInterface && $account->isAuthenticated()) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventId)
        ->condition('ticket_type', $tierId)
        ->condition('status', TicketWaitlistEntry::STATUS_OFFERED)
        ->condition('uid', (int) $account->id())
        ->sort('id')
        ->range(0, 5)
        ->execute();
      foreach ($ids ?: [] as $id) {
        $entry = $storage->load($id);
        if ($entry instanceof TicketWaitlistEntry && $this->isOfferClaimable($entry)) {
          return $entry;
        }
      }
    }
    return NULL;
  }

  public function expireStaleOffers(): int {
    if (!$this->waitlistSchemaReady()) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $storage = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TicketWaitlistEntry::STATUS_OFFERED)
      ->condition('offer_expires', $now, '<=')
      ->execute();
    if (!$ids) {
      return 0;
    }
    $count = 0;
    foreach ($storage->loadMultiple($ids) as $entry) {
      if (!$entry instanceof TicketWaitlistEntry) {
        continue;
      }
      $entry->set('status', TicketWaitlistEntry::STATUS_EXPIRED);
      $entry->set('offer_reserved', 0);
      $entry->set('offer_token', NULL);
      $entry->set('offer_expires', NULL);
      $entry->save();
      $count++;
    }
    return $count;
  }

  public function processAutoPromotions(): int {
    if (!$this->waitlistSchemaReady()) {
      return 0;
    }
    $offered = 0;
    $storage = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry');
    $waiting_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TicketWaitlistEntry::STATUS_WAITING)
      ->sort('id', 'ASC')
      ->execute();
    if (!$waiting_ids) {
      return 0;
    }

    $entries = $storage->loadMultiple($waiting_ids);
    foreach ($entries as $entry) {
      if (!$entry instanceof TicketWaitlistEntry) {
        continue;
      }
      $event = $entry->get('event')->entity;
      $tier = $entry->get('ticket_type')->entity;
      if (!$event instanceof NodeInterface || !$tier instanceof TicketTypeInterface) {
        continue;
      }
      if (!$tier->hasField('auto_promote_waitlist') || !$tier->get('auto_promote_waitlist')->value) {
        continue;
      }
      $status = TicketStatusEvaluator::evaluate($tier, $this->variationSold, $this->time, $event);
      if ($status !== TicketStatusEvaluator::STATUS_ACTIVE) {
        continue;
      }

      $cap = $tier->get('capacity')->isEmpty() ? 0 : (int) $tier->get('capacity')->value;
      if ($cap < 1) {
        continue;
      }
      $variationId = $tier->get('commerce_variation')->isEmpty()
        ? 0
        : (int) $tier->get('commerce_variation')->target_id;
      $eventId = (int) $event->id();
      $tierId = (int) $tier->id();
      if ($variationId < 1) {
        continue;
      }

      $lockName = 'mel_waitlist_autopromote:' . $eventId . ':' . $tierId;
      if ($this->lock !== NULL) {
        if (!$this->lock->acquire($lockName, self::PROMOTE_LOCK_TIMEOUT)) {
          $this->loggerFactory->get('myeventlane_commerce')->notice(
            'Waitlist auto-promotion skipped (lock busy): event @e tier @t entry @id.',
            [
              '@e' => (string) $eventId,
              '@t' => (string) $tierId,
              '@id' => (string) $entry->id(),
            ]
          );
          continue;
        }
      }

      try {
        // Recompute sold and held under the tier lock so concurrent cron
        // workers and same-run promotions cannot over-offer capacity.
        $sold = $this->capacity !== NULL
          ? $this->capacity->getSold($eventId, $variationId)
          : $this->variationSold->countCompletedSoldForVariation($eventId, $variationId);
        $held = $this->capacity !== NULL
          ? $this->capacity->getHeld($eventId, $tierId)
          : $this->sumActiveOfferReservedLegacy($eventId, $tierId);
        $available = $cap - $sold - $held;

        $want = (int) $entry->get('quantity')->value;
        if ($want > $available && $available >= 0) {
          $this->loggerFactory->get('myeventlane_commerce')->notice(
            'Waitlist auto-promotion clamp: entry @id wanted @w seats, only @a available for tier @t (event @e).',
            [
              '@id' => (string) $entry->id(),
              '@w' => (string) $want,
              '@a' => (string) max(0, $available),
              '@t' => (string) $tierId,
              '@e' => (string) $eventId,
            ]
          );
        }

        if ($available < 1) {
          continue;
        }

        $give = min($want, $available);
        if ($give < 1) {
          continue;
        }

        $entry->set('status', TicketWaitlistEntry::STATUS_OFFERED);
        $entry->set('offer_reserved', $give);
        $entry->set('offer_expires', $this->time->getRequestTime() + self::OFFER_TTL);
        $entry->set('offer_token', Crypt::randomBytesBase64(32));
        $entry->set('offer_mail_sent', FALSE);
        $entry->save();
        $offered++;

        $this->loggerFactory->get('myeventlane_commerce')->info(
          'Tier waitlist offer: entry @id tier @t qty @q',
          ['@id' => (string) $entry->id(), '@t' => (string) $tierId, '@q' => (string) $give]
        );

        try {
          $this->queueFactory->get('mel_ticket_waitlist_offer_mail')
            ->createItem(['entry_id' => (int) $entry->id()]);
        }
        catch (\Throwable $e) {
          $this->loggerFactory->get('myeventlane_commerce')->error(
            'Failed to queue waitlist offer email for entry @id: @message',
            ['@id' => (string) $entry->id(), '@message' => $e->getMessage()]
          );
        }
      }
      finally {
        if ($this->lock !== NULL) {
          $this->lock->release($lockName);
        }
      }
    }

    return $offered;
  }

  public function reconcileCompletedPurchase(
    int $eventId,
    int $tierId,
    int $quantity,
    ?UserInterface $buyer,
    ?string $customerMail = NULL,
  ): void {
    if ($quantity < 1) {
      return;
    }
    if (!$this->waitlistSchemaReady()) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $eventId)
      ->condition('ticket_type', $tierId)
      ->condition('status', TicketWaitlistEntry::STATUS_OFFERED)
      ->sort('id', 'ASC');
    if ($buyer && $buyer->isAuthenticated()) {
      $query->condition('uid', (int) $buyer->id());
    }
    else {
      $mail = $customerMail !== NULL ? trim($customerMail) : '';
      if ($mail === '') {
        $this->loggerFactory->get('myeventlane_commerce')->notice(
          'Skipping tier waitlist reconcile for event @e tier @t: no customer user or email.',
          ['@e' => (string) $eventId, '@t' => (string) $tierId]
        );
        return;
      }
      $query->condition('mail_hash', hash('sha256', mb_strtolower($mail)));
    }
    $ids = $query->range(0, 10)->execute();
    if (!$ids) {
      return;
    }
    $remaining = $quantity;
    foreach ($ids as $id) {
      if ($remaining < 1) {
        break;
      }
      $entry = $storage->load($id);
      if (!$entry instanceof TicketWaitlistEntry || !$this->isOfferClaimable($entry)) {
        continue;
      }
      $reserved = (int) $entry->get('offer_reserved')->value;
      if ($reserved < 1) {
        continue;
      }
      if ($remaining >= $reserved) {
        $entry->set('status', TicketWaitlistEntry::STATUS_ACCEPTED);
        $entry->set('offer_reserved', 0);
        $entry->set('offer_token', NULL);
        $entry->set('offer_expires', NULL);
        $entry->save();
        $remaining -= $reserved;
      }
    }
  }

  public function getWaitingPosition(TicketWaitlistEntry $entry): ?int {
    if ($entry->get('status')->value !== TicketWaitlistEntry::STATUS_WAITING) {
      return NULL;
    }
    if (!$this->waitlistSchemaReady()) {
      return NULL;
    }
    $event = $entry->get('event')->target_id;
    $tier = $entry->get('ticket_type')->target_id;
    $id = (int) $entry->id();
    $ids = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry')->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event)
      ->condition('ticket_type', $tier)
      ->condition('status', TicketWaitlistEntry::STATUS_WAITING)
      ->condition('id', $id, '<=')
      ->sort('id', 'ASC')
      ->execute();
    return $ids ? count($ids) : NULL;
  }

  public function runCronMaintenance(): void {
    $expired = $this->expireStaleOffers();
    $promoted = $this->processAutoPromotions();
    if ($expired > 0 || $promoted > 0) {
      $this->loggerFactory->get('myeventlane_commerce')->notice(
        'Tier waitlist cron: expired @e offers, created @p new offers.',
        ['@e' => (string) $expired, '@p' => (string) $promoted]
      );
    }
  }

  private function countByStatus(NodeInterface $event, TicketTypeInterface $tier, string $status): int {
    if (!$this->waitlistSchemaReady()) {
      return 0;
    }
    $query = $this->entityTypeManager->getStorage('mel_ticket_waitlist_entry')->getQuery()
      ->accessCheck(FALSE)
      ->condition('event', $event->id())
      ->condition('ticket_type', $tier->id())
      ->condition('status', $status)
      ->count();
    return (int) $query->execute();
  }

}
