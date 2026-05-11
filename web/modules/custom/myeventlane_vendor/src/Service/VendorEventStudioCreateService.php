<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Shared draft lookup and Stripe readiness for Event Studio entry (create flow).
 *
 * Keeps CreateEventGatewayController and Event Studio create aligned.
 */
final class VendorEventStudioCreateService {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly LoggerInterface $logger,
    private readonly MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Latest unpublished event owned by the user (single-draft resume).
   */
  public function findLatestUnpublishedEventNidForUser(int $uid): ?int {
    if ($uid <= 0) {
      return NULL;
    }
    try {
      $ids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'event')
        ->condition('uid', $uid)
        ->condition('status', 0)
        ->sort('changed', 'DESC')
        ->range(0, 1)
        ->execute();
      if (empty($ids)) {
        return NULL;
      }
      $nid = (int) reset($ids);
      return $nid > 0 ? $nid : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->warning('VendorEventStudioCreateService: draft lookup failed for uid=@uid: @m', [
        '@uid' => (string) $uid,
        '@m' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Creates the minimal draft event scaffold used by the canonical Studio flow.
   */
  public function createDraftEventForUser(int $uid): NodeInterface {
    if ($uid <= 0) {
      throw new \InvalidArgumentException('A valid user id is required to create a draft event.');
    }

    try {
      $storage = $this->entityTypeManager->getStorage('node');
      /** @var \Drupal\node\NodeInterface $node */
      $node = $storage->create([
        'type' => 'event',
        'title' => $this->t('Untitled event'),
        'uid' => $uid,
        'status' => 0,
      ]);
      EventNodeRevisionSave::prepare($node, 'Event Studio draft created.');
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('VendorEventStudioCreateService: failed to persist draft event uid=@uid @message', [
        '@uid' => (string) $uid,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }

    return $node;
  }

  /**
   * Redirects to Stripe Connect when the organiser cannot sell (matches gateway).
   */
  public function redirectIfStripeNotReady(AccountInterface $account, string $destinationPath): ?RedirectResponse {
    if ($account->isAnonymous()) {
      return NULL;
    }
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return NULL;
    }
    if ($account->hasPermission('administer site configuration') || $uid === 1) {
      return NULL;
    }

    $vendor_ids = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
    if ($vendor_ids === []) {
      return NULL;
    }

    $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load(reset($vendor_ids));
    if (!$vendor instanceof Vendor) {
      return NULL;
    }

    if ($this->isVendorStripeConnected($vendor, $uid, $account)) {
      return NULL;
    }

    $this->logger->notice('VendorEventStudioCreateService: Stripe not ready, redirecting uid=@uid', [
      '@uid' => (string) $uid,
    ]);
    $this->messenger->addError($this->t('Connect your Stripe account to continue creating events.'));
    $url = Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
      'query' => ['destination' => $destinationPath],
    ]);

    return new RedirectResponse($url->toString());
  }

  /**
   * Whether the vendor's commerce store is ready to sell (matches vendor console).
   */
  public function isVendorStripeConnected(Vendor $vendor, int $uid, AccountInterface $account): bool {
    if ($account->hasPermission('administer site configuration') || (int) $account->id() === 1) {
      return TRUE;
    }

    $store = $this->resolveCommerceStoreForVendor($vendor, $uid);
    if (!$store instanceof StoreInterface) {
      return FALSE;
    }

    $connected = FALSE;
    if ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
      $connected = (bool) $store->get('field_stripe_connected')->value;
    }
    if (!$connected && $store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
      $connected = (bool) $store->get('field_stripe_charges_enabled')->value;
    }

    return $connected;
  }

  /**
   * Resolves the organiser's store, linking it on the vendor when found by uid.
   */
  private function resolveCommerceStoreForVendor(Vendor $vendor, int $uid): ?StoreInterface {
    $store = NULL;
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $e = $vendor->get('field_vendor_store')->entity;
      $store = $e instanceof StoreInterface ? $e : NULL;
    }

    if (!$store && $this->entityTypeManager->hasDefinition('commerce_store')) {
      $store_storage = $this->entityTypeManager->getStorage('commerce_store');
      $store_ids = $store_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->range(0, 1)
        ->execute();
      if (!empty($store_ids)) {
        $loaded = $store_storage->load(reset($store_ids));
        if ($loaded instanceof StoreInterface && $vendor->hasField('field_vendor_store')) {
          $vendor->set('field_vendor_store', $loaded->id());
          $vendor->save();
        }
        $store = $loaded instanceof StoreInterface ? $loaded : NULL;
      }
    }

    return $store instanceof StoreInterface ? $store : NULL;
  }

}
