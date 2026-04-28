<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Psr\Log\LoggerInterface;

/**
 * Server-side guard: publishing paid/hybrid ticket events requires Stripe charge readiness.
 *
 * Uses Commerce store fields only (no live Stripe API calls). Aligns with
 * VendorConsoleBaseController::assertStripeConnected store resolution.
 */
final class PaidPublishStripeGate {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UserVendorMembershipQuery $membershipQuery,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Returns NULL when the account may publish paid/both events; else a translated message.
   */
  public function validatePaidPublishAllowed(AccountInterface $account, ?int $eventNodeId = NULL): ?string {
    if ((int) $account->id() === 1 || $account->hasPermission('administer site configuration')) {
      return NULL;
    }

    $vendorId = $this->resolveVendorIdForAccount($account);
    $store = $this->resolveCommerceStoreForAccount($account);
    $storeId = $store instanceof StoreInterface ? (int) $store->id() : NULL;

    if (!$store instanceof StoreInterface) {
      $this->logBlocked((int) $account->id(), $vendorId, $storeId, $eventNodeId, 'no_store');
      return $this->blockedMessage();
    }

    if (!$store->hasField('field_stripe_account_id')) {
      $this->logBlocked((int) $account->id(), $vendorId, $storeId, $eventNodeId, 'missing_account_id_field');
      return $this->blockedMessage();
    }
    if ($store->get('field_stripe_account_id')->isEmpty()) {
      $this->logBlocked((int) $account->id(), $vendorId, $storeId, $eventNodeId, 'empty_account_id');
      return $this->blockedMessage();
    }
    $acct = trim((string) $store->get('field_stripe_account_id')->value);
    if ($acct === '' || !str_starts_with($acct, 'acct_')) {
      $this->logBlocked((int) $account->id(), $vendorId, $storeId, $eventNodeId, 'invalid_account_id');
      return $this->blockedMessage();
    }

    if (!$store->hasField('field_stripe_charges_enabled')) {
      $this->logBlocked((int) $account->id(), $vendorId, $storeId, $eventNodeId, 'missing_charges_field');
      return $this->blockedMessage();
    }
    if ($store->get('field_stripe_charges_enabled')->isEmpty() || !(bool) $store->get('field_stripe_charges_enabled')->value) {
      $this->logBlocked((int) $account->id(), $vendorId, $storeId, $eventNodeId, 'charges_not_enabled');
      return $this->blockedMessage();
    }

    return NULL;
  }

  private function blockedMessage(): string {
    return (string) $this->t('Connect Stripe before publishing paid tickets. Stripe must be ready to accept charges before this event can go live.');
  }

  /**
   * @param int|null $vendorId
   * @param int|null $storeId
   */
  private function logBlocked(int $uid, ?int $vendorId, ?int $storeId, ?int $eventNid, string $reason): void {
    $this->logger->notice('Paid publish blocked (Stripe not charge-ready): reason=@reason uid=@uid vendor_id=@vid store_id=@sid event_nid=@nid', [
      '@reason' => $reason,
      '@uid' => (string) $uid,
      '@vid' => $vendorId !== NULL ? (string) $vendorId : 'none',
      '@sid' => $storeId !== NULL ? (string) $storeId : 'none',
      '@nid' => $eventNid !== NULL ? (string) $eventNid : 'none',
    ]);
  }

  private function resolveVendorIdForAccount(AccountInterface $account): ?int {
    $ids = $this->membershipQuery->getVendorIdsForUser((int) $account->id());
    if ($ids === []) {
      return NULL;
    }
    return $ids[0];
  }

  private function resolveCommerceStoreForAccount(AccountInterface $account): ?StoreInterface {
    $vid = $this->resolveVendorIdForAccount($account);
    if ($vid !== NULL) {
      $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vid);
      if ($vendor instanceof Vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
        $cand = $vendor->get('field_vendor_store')->entity;
        if ($cand instanceof StoreInterface) {
          return $cand;
        }
      }
    }

    $uid = (int) $account->id();
    $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
    $storeIds = $storeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();
    if (!empty($storeIds)) {
      $loaded = $storeStorage->load(reset($storeIds));
      return $loaded instanceof StoreInterface ? $loaded : NULL;
    }

    return NULL;
  }

}
