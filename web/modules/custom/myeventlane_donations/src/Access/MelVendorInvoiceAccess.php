<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_donations\Service\VendorContributionInvoiceService;

/**
 * Access: vendor-owned MEL contribution invoice routes.
 */
final class MelVendorInvoiceAccess {

  public function __construct(
    private readonly VendorContributionInvoiceService $vendorContributionInvoiceService,
  ) {}

  /**
   * Access for routes with {invoice} parameter.
   */
  public function accessInvoice(AccountInterface $account, string $invoice): AccessResult {
    $invoiceId = (int) $invoice;
    if ($invoiceId <= 0) {
      return AccessResult::forbidden();
    }
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return AccessResult::forbidden()->cachePerUser();
    }
    $vendorId = $this->vendorContributionInvoiceService->resolveVendorIdForUser($uid);
    if ($vendorId === NULL) {
      return AccessResult::forbidden()->cachePerUser();
    }
    $row = $this->vendorContributionInvoiceService->loadInvoice($invoiceId);
    if (!$row) {
      return AccessResult::forbidden();
    }
    if ((int) ($row['vendor_id'] ?? 0) !== $vendorId) {
      return AccessResult::forbidden()->cachePerUser();
    }
    return AccessResult::allowed()->cachePerUser();
  }

}
