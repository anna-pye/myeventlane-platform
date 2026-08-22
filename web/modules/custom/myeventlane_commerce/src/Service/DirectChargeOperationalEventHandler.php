<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Psr\Log\LoggerInterface;
use Stripe\Event;

/**
 * Queues organiser alerts for critical connected-account Stripe events.
 */
final class DirectChargeOperationalEventHandler {

  /**
   * Critical Connect events approved for organiser email notification.
   *
   * Routine payout success events are deliberately excluded.
   *
   * @var string[]
   */
  public const SUPPORTED_EVENTS = [
    Event::CHARGE_DISPUTE_CREATED,
    Event::ACCOUNT_UPDATED,
    Event::PAYOUT_FAILED,
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessagingManager $messagingManager,
    private readonly LoggerInterface $logger,
    private readonly DomainDetector $domainDetector,
  ) {}

  /**
   * Whether this handler owns the event type.
   */
  public function supports(string $eventType): bool {
    return in_array($eventType, self::SUPPORTED_EVENTS, TRUE);
  }

  /**
   * Queues the approved organiser notification when the event is actionable.
   *
   * @return array{handled: bool, reason: string}
   *   The webhook disposition. A handled duplicate remains handled because the
   *   message queue is idempotent by Stripe event ID and template.
   */
  public function handle(Event $event): array {
    $eventType = trim((string) ($event->type ?? ''));
    if (!$this->supports($eventType)) {
      return ['handled' => FALSE, 'reason' => 'Connect operational event is not supported.'];
    }

    if ($eventType === Event::ACCOUNT_UPDATED && !self::accountBecameRestricted($event)) {
      return ['handled' => FALSE, 'reason' => 'Stripe account update did not introduce a restriction.'];
    }

    $eventId = trim((string) ($event->id ?? ''));
    $accountId = trim((string) ($event->account ?? ''));
    if ($eventId === '' || !str_starts_with($accountId, 'acct_')) {
      return ['handled' => FALSE, 'reason' => 'Stripe event or connected account identifier is invalid.'];
    }

    $store = $this->resolveUniqueStore($accountId);
    if (!$store instanceof StoreInterface) {
      return ['handled' => FALSE, 'reason' => 'No unique organiser store matched the connected account.'];
    }

    $recipient = trim((string) ($store->getOwner()?->getEmail() ?? ''));
    if ($recipient === '') {
      $recipient = trim((string) $store->getEmail());
    }
    if ($recipient === '') {
      $this->logger->warning('Critical Stripe event @event_type for store @store has no organiser email.', [
        '@event_type' => $eventType,
        '@store' => (string) $store->id(),
        'stripe_event_id' => $eventId,
        'store_id' => (int) $store->id(),
      ]);
      return ['handled' => FALSE, 'reason' => 'The organiser store has no notification email.'];
    }

    $template = self::templateForEventType($eventType);
    $context = $this->buildContext($event, $store);
    $messageId = $this->messagingManager->queue($template, $recipient, $context, [
      'langcode' => $store->language()->getId(),
      'idempotency_key' => sprintf('stripe-event:%s:%s', $eventId, $template),
    ]);

    $this->logger->notice('Critical Stripe event @event_type accepted for organiser notification for store @store.', [
      '@event_type' => $eventType,
      '@store' => (string) $store->id(),
      'message_id' => $messageId,
      'message_type' => $template,
      'stripe_event_id' => $eventId,
      'store_id' => (int) $store->id(),
    ]);

    return ['handled' => TRUE, 'reason' => 'Organiser notification accepted idempotently.'];
  }

  /**
   * Detects a transition from available to restricted account operations.
   */
  public static function accountBecameRestricted(Event $event): bool {
    $data = self::stripeValueToArray($event->data ?? NULL);
    $account = self::stripeValueToArray($data['object'] ?? NULL);
    $previous = self::stripeValueToArray($data['previous_attributes'] ?? NULL);

    if (array_key_exists('charges_enabled', $previous)
      && (bool) $previous['charges_enabled']
      && empty($account['charges_enabled'])) {
      return TRUE;
    }
    if (array_key_exists('payouts_enabled', $previous)
      && (bool) $previous['payouts_enabled']
      && empty($account['payouts_enabled'])) {
      return TRUE;
    }

    $currentCapabilities = self::stripeValueToArray($account['capabilities'] ?? NULL);
    $previousCapabilities = self::stripeValueToArray($previous['capabilities'] ?? NULL);
    if (array_key_exists('card_payments', $previousCapabilities)
      && $previousCapabilities['card_payments'] === 'active'
      && ($currentCapabilities['card_payments'] ?? NULL) !== 'active') {
      return TRUE;
    }

    $currentRequirements = self::stripeValueToArray($account['requirements'] ?? NULL);
    $previousRequirements = self::stripeValueToArray($previous['requirements'] ?? NULL);
    $currentReason = trim((string) ($currentRequirements['disabled_reason'] ?? ''));
    $previousReason = trim((string) ($previousRequirements['disabled_reason'] ?? ''));
    return array_key_exists('disabled_reason', $previousRequirements)
      && $currentReason !== ''
      && $currentReason !== $previousReason;
  }

  /**
   * Returns the template approved for a critical operational event.
   */
  public static function templateForEventType(string $eventType): string {
    return match ($eventType) {
      Event::CHARGE_DISPUTE_CREATED => 'stripe_dispute_created_vendor',
      Event::ACCOUNT_UPDATED => 'stripe_account_restricted_vendor',
      Event::PAYOUT_FAILED => 'stripe_payout_failed_vendor',
      default => throw new \InvalidArgumentException('Unsupported Connect operational event type.'),
    };
  }

  /**
   * Resolves current, pending or previous connected accounts to one store.
   */
  private function resolveUniqueStore(string $accountId): ?StoreInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_store');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $accountFields = [
      'field_stripe_account_id',
      'field_stripe_replacement_id',
      'field_stripe_previous_id',
    ];
    $or = $query->orConditionGroup();
    foreach ($accountFields as $fieldName) {
      $or->condition($fieldName, $accountId);
    }
    $storeIds = array_values($query->condition($or)->execute());

    if (count($storeIds) !== 1) {
      $this->logger->warning('Critical Stripe event account mapping was not unique: account=@account count=@count.', [
        '@account' => self::maskAccountId($accountId),
        '@count' => count($storeIds),
      ]);
      return NULL;
    }

    $store = $storage->load(reset($storeIds));
    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * Builds scalar-only context for durable queue storage.
   *
   * @return array<string, int|string|null>
   */
  private function buildContext(Event $event, StoreInterface $store): array {
    $data = self::stripeValueToArray($event->data ?? NULL);
    $object = self::stripeValueToArray($data['object'] ?? NULL);
    $eventType = (string) $event->type;
    $context = [
      'organiser_name' => (string) $store->getName(),
      'store_id' => (int) $store->id(),
      'stripe_event_id' => (string) $event->id,
      'stripe_object_id' => trim((string) ($object['id'] ?? '')),
      'stripe_manage_url' => $this->stripeManageUrl(),
    ];

    if ($eventType === Event::CHARGE_DISPUTE_CREATED) {
      $context += [
        'amount' => self::formatMinorAmount($object['amount'] ?? NULL, $object['currency'] ?? NULL),
        'reason' => trim((string) ($object['reason'] ?? 'not provided')),
        'response_deadline' => self::formatDate($object['evidence_details']['due_by'] ?? NULL),
      ];
    }
    elseif ($eventType === Event::ACCOUNT_UPDATED) {
      $requirements = self::stripeValueToArray($object['requirements'] ?? NULL);
      $context += [
        'restriction_reason' => trim((string) ($requirements['disabled_reason'] ?? 'Stripe has restricted payments or payouts.')),
      ];
    }
    elseif ($eventType === Event::PAYOUT_FAILED) {
      $context += [
        'amount' => self::formatMinorAmount($object['amount'] ?? NULL, $object['currency'] ?? NULL),
        'failure_message' => trim((string) ($object['failure_message'] ?? 'Stripe did not provide a reason.')),
      ];
    }

    return $context;
  }

  /**
   * Converts Stripe resources or scalar arrays to ordinary arrays.
   *
   * @return array<string, mixed>
   */
  private static function stripeValueToArray(mixed $value): array {
    if (is_array($value)) {
      return $value;
    }
    if (is_object($value) && method_exists($value, 'toArray')) {
      $array = $value->toArray();
      return is_array($array) ? $array : [];
    }
    return [];
  }

  private static function formatMinorAmount(mixed $amount, mixed $currency): string {
    $currencyCode = strtoupper(trim((string) $currency));
    $currencyCode = $currencyCode !== '' ? $currencyCode : 'AUD';
    return sprintf('%s %.2f', $currencyCode, ((int) $amount) / 100);
  }

  private static function formatDate(mixed $timestamp): string {
    return is_numeric($timestamp) && (int) $timestamp > 0
      ? gmdate('j M Y', (int) $timestamp)
      : 'the deadline shown in Stripe';
  }

  private static function maskAccountId(string $accountId): string {
    return strlen($accountId) > 10
      ? substr($accountId, 0, 7) . '…' . substr($accountId, -4)
      : 'acct_…';
  }

  /**
   * Builds a durable organiser URL even when a queue worker has no request.
   */
  private function stripeManageUrl(): string {
    try {
      return $this->domainDetector->buildDomainUrl('/stripe/manage', 'vendor');
    }
    catch (\Throwable) {
      return '/stripe/manage';
    }
  }

}
