<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides footer context data for authenticated vendor/admin users.
 *
 * Centralizes data retrieval; Twig stays dumb. Global footer rendering must
 * not call external payment APIs because it runs on every organiser page.
 */
final class FooterContextService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets footer context for the current user.
   *
   * @return array{
   *   store_name: string|null,
   *   payout_balance: string|null,
   *   environment: string,
   *   open_tickets: int,
   *   is_vendor: bool,
   *   is_admin: bool,
   *   }
   */
  public function getContext(): array {
    $context = [
      'store_name' => NULL,
      'payout_balance' => NULL,
      'environment' => getenv('SITE_ENV') ?: 'Production',
      'open_tickets' => 0,
      'is_vendor' => $this->currentUser->hasRole('vendor'),
      'is_admin' => $this->currentUser->hasRole('administrator'),
    ];

    if (!$this->currentUser->isAuthenticated()) {
      return $context;
    }

    if ($context['is_vendor']) {
      $store = $this->getStoreForCurrentUser();
      if ($store instanceof StoreInterface) {
        $context['store_name'] = $store->label();
      }

      $context['open_tickets'] = $this->countOpenEscalations();
    }

    return $context;
  }

  /**
   * Gets the store for the current user's vendor.
   */
  private function getStoreForCurrentUser(): ?StoreInterface {
    $vendor = $this->vendorResolver->resolveFromCurrentUser();
    if (!$vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return NULL;
    }

    $store = $vendor->get('field_vendor_store')->entity;
    return $store instanceof StoreInterface ? $store : NULL;
  }

  /**
   * Counts open escalations (not resolved/closed) for the current vendor.
   */
  private function countOpenEscalations(): int {
    if (!$this->moduleHandler->moduleExists('myeventlane_escalations')) {
      return 0;
    }

    $vendor = $this->vendorResolver->resolveFromCurrentUser();
    if (!$vendor) {
      return 0;
    }

    try {
      $query = $this->entityTypeManager->getStorage('escalation')->getQuery()
        ->accessCheck(FALSE)
        ->condition('vendor_id', $vendor->id())
        ->condition('status', ['resolved', 'closed'], 'NOT IN');

      return (int) $query->count()->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('FooterContextService: escalation count failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

}
