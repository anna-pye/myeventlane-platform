<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Resolves and enforces vendor Pro subscription state.
 */
final class VendorSubscriptionService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns whether a vendor is currently Pro.
   */
  public function isProVendor(int $vendor_id): bool {
    if ($vendor_id <= 0) {
      return FALSE;
    }

    $isPro = FALSE;
    try {
      $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor_id);
      if ($vendor && $vendor->hasField('is_pro') && !$vendor->get('is_pro')->isEmpty()) {
        $isPro = (bool) $vendor->get('is_pro')->value;
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed resolving Pro state for vendor @vendor_id: @message', [
        '@vendor_id' => $vendor_id,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    // Forward-compatible hook for future billing integrations.
    $this->moduleHandler->alter('myeventlane_vendor_is_pro_vendor', $isPro, $vendor_id);
    return $isPro;
  }

  /**
   * Throws access denied when Pro is required but inactive.
   */
  public function requirePro(int $vendor_id): void {
    if (!$this->isProVendor($vendor_id)) {
      throw new AccessDeniedHttpException('Pro subscription is required.');
    }
  }

}
