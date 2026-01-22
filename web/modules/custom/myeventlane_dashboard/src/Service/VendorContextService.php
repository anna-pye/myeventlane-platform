<?php

declare(strict_types=1);

namespace Drupal\myeventlane_dashboard\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Access\AccessDeniedHttpException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves vendor context (store) and enforces access rules.
 *
 * Contract:
 * - Vendor ownership is store-based.
 * - Admin can view any store (optional query arg).
 * - Vendors can view only their own store(s).
 */
final class VendorContextService implements VendorContextServiceInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getCurrentVendorStore(): StoreInterface {
    $store_storage = $this->entityTypeManager->getStorage('commerce_store');

    $request = $this->requestStack->getCurrentRequest();
    $requested_store_id = $request ? (string) $request->query->get('store') : '';

    // Admin can switch store context via ?store={id}.
    if ($requested_store_id !== '' && $this->currentUser->hasPermission('administer commerce_store')) {
      /** @var \Drupal\commerce_store\Entity\StoreInterface|null $store */
      $store = $store_storage->load($requested_store_id);
      if ($store instanceof StoreInterface) {
        return $store;
      }
      throw new AccessDeniedHttpException('Invalid store context.');
    }

    // Vendor: resolve store(s) owned by current user.
    // This assumes the store owner UID is meaningful in MEL (common pattern).
    // If you later implement a vendor entity, adjust here only.
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      throw new AccessDeniedHttpException('You must be logged in.');
    }

    $stores = $store_storage->loadByProperties([
      'uid' => $uid,
      'type' => 'online',
    ]);

    if (count($stores) === 1) {
      /** @var \Drupal\commerce_store\Entity\StoreInterface $store */
      $store = reset($stores);
      return $store;
    }

    if (count($stores) > 1) {
      // Multiple stores: require explicit selection (admin can do this via ?store=).
      // Vendor selection UI can be added later; for now, deny to avoid leakage.
      throw new AccessDeniedHttpException('Multiple vendor stores detected. Store selection is required.');
    }

    // Final fallback: deny. We do NOT guess.
    throw new AccessDeniedHttpException('No vendor store found for your account.');
  }

  /**
   * {@inheritdoc}
   */
  public function getVendorDisplayName(StoreInterface $store): string {
    // Store label is a stable, user-facing vendor name in MEL.
    $label = trim((string) $store->label());
    return $label !== '' ? $label : 'Vendor';
  }

}
