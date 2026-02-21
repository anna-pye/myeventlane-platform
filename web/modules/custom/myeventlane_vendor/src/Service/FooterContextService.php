<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides footer context data for authenticated vendor/admin users.
 *
 * Centralizes data retrieval; Twig stays dumb. Payout balance via optional
 * Stripe service, cached 5 minutes to avoid heavy API calls on page load.
 */
final class FooterContextService {

  private const BALANCE_CACHE_TTL = 300;

  /**
   * Constructs the service.
   *
   * @param object|null $vendorStripe
   *   Optional myeventlane_stripe.vendor_stripe. When enabled, provides payout balance.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
    private readonly CacheBackendInterface $cache,
    private readonly ?object $vendorStripe = NULL,
  ) {}

  /**
   * Gets footer context for the current user.
   *
   * @return array{
   *   store_name: string|null,
   *   payout_balance: string,
   *   environment: string,
   *   open_tickets: int,
   *   is_vendor: bool,
   *   is_admin: bool,
   * }
   */
  public function getContext(): array {
    $context = [
      'store_name' => NULL,
      'payout_balance' => '$0.00',
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
        $context['payout_balance'] = $this->getPayoutBalanceFormatted($store);
      }

      $context['open_tickets'] = $this->countOpenEscalations();
    }

    return $context;
  }

  /**
   * Gets Stripe payout balance, cached to avoid heavy API calls on page load.
   */
  private function getPayoutBalanceFormatted(StoreInterface $store): string {
    if ($this->vendorStripe === NULL || !method_exists($this->vendorStripe, 'getAvailableBalanceFormatted')) {
      return '$0.00';
    }

    $cid = 'footer_balance:' . $store->id();
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && isset($cached->data)) {
      return (string) $cached->data;
    }

    $balance = (string) $this->vendorStripe->getAvailableBalanceFormatted($store);
    $this->cache->set($cid, $balance, time() + self::BALANCE_CACHE_TTL, [
      'commerce_store:' . $store->id(),
    ]);

    return $balance;
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
