<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Builds the organiser Payments Hub view model (Trust Centre).
 *
 * Deep-links existing Stripe / payout / refund / tax / billing surfaces.
 * Does not invent a new payment architecture.
 */
final class VendorPaymentsHubBuilder {

  use StringTranslationTrait;

  /**
   * Constructs the hub builder.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   Current user.
   * @param \Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface $vendorResolver
   *   Vendor resolver.
   * @param \Drupal\myeventlane_vendor\Service\VendorPaymentsHealthService $paymentsHealth
   *   Payment health service.
   * @param \Drupal\myeventlane_vendor\Service\TicketSalesService $ticketSales
   *   Ticket sales totals.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   String translation.
   * @param object|null $stripePayout
   *   Optional myeventlane_stripe.vendor_payout.
   * @param object|null $refundsRepository
   *   Optional myeventlane_escalations_refunds.repository.
   * @param object|null $refundsMetrics
   *   Optional myeventlane_escalations_refunds.metrics.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly VendorPaymentsHealthService $paymentsHealth,
    private readonly TicketSalesService $ticketSales,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
    private readonly ?object $stripePayout = NULL,
    private readonly ?object $refundsRepository = NULL,
    private readonly ?object $refundsMetrics = NULL,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds the Payments Hub payload for the current organiser.
   *
   * @return array<string, mixed>
   *   Hub view model for Twig.
   */
  public function build(): array {
    $uid = (int) $this->currentUser->id();
    $vendor = $this->vendorResolver->resolveFromCurrentUser();
    $health = $this->paymentsHealth->buildForCurrentUser($vendor);
    $store = $this->paymentsHealth->resolveStore($vendor);

    $revenue = $this->ticketSales->getManagedVendorRevenue($uid);
    $available = '$0.00';
    $pending = '$0.00';
    $lastPayout = NULL;
    if ($store !== NULL && $this->stripePayout !== NULL
      && method_exists($this->stripePayout, 'getAvailableBalanceFormatted')
      && method_exists($this->stripePayout, 'getPendingBalanceFormatted')
      && method_exists($this->stripePayout, 'getLatestPayoutSummary')) {
      try {
        $available = $this->stripePayout->getAvailableBalanceFormatted($store);
        $pending = $this->stripePayout->getPendingBalanceFormatted($store);
        $lastPayout = $this->stripePayout->getLatestPayoutSummary($store);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Payments hub could not load Stripe balances: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $refunds = $this->buildRefundsSection();
    $billingUrl = $this->safeRouteUrl('myeventlane_donations.vendor_mel_billing');
    $supportUrl = $this->safeRouteUrl('myeventlane_escalations_portal.vendor_list')
      ?? $this->safeRouteUrl('myeventlane_help_centre.help')
      ?? '/help';

    $this->logger->info('payments_hub_opened uid=@uid state=@state', [
      '@uid' => (string) $uid,
      '@state' => (string) ($health['state'] ?? 'unknown'),
    ]);
    if (!empty($health['needs_attention'])) {
      $this->logger->info('payment_health_warning uid=@uid state=@state', [
        '@uid' => (string) $uid,
        '@state' => (string) ($health['state'] ?? 'unknown'),
      ]);
    }

    return [
      'health' => $health,
      'earnings' => [
        'gross' => $revenue['gross'] ?? '$0.00',
        'fees' => $revenue['fees'] ?? '$0.00',
        'net' => $revenue['net'] ?? '$0.00',
        'available' => $available,
        'pending' => $pending,
      ],
      'payouts' => [
        'available' => $available,
        'pending' => $pending,
        'last' => $lastPayout,
        'next_expected' => !empty($health['payouts_enabled'])
          ? (string) $this->t("On Stripe's usual schedule after sales clear")
          : (string) $this->t('Available once payouts are enabled'),
        'history_url' => $this->safeRouteUrl('myeventlane_vendor.console.payouts'),
        'empty' => $lastPayout === NULL && ($available === '$0.00' || $available === ''),
        'empty_title' => (string) $this->t('No payouts yet.'),
        'empty_body' => (string) $this->t("We'll show your first payout once ticket sales begin."),
      ],
      'refunds' => $refunds,
      'tax' => [
        'title' => (string) $this->t('Tax & GST'),
        'body' => (string) $this->t('Download GST-friendly exports for your records. These are summaries to help with your BAS — not advice from an accountant.'),
        'cta_label' => (string) $this->t('Open tax exports'),
        'cta_url' => $this->safeRouteUrl('myeventlane_finance.vendor_bas'),
        'available' => $this->safeRouteUrl('myeventlane_finance.vendor_bas') !== NULL,
      ],
      'billing' => [
        'title' => (string) $this->t('MyEventLane billing'),
        'body' => (string) $this->t('Invoices for MEL contributions and platform billing, if any apply to your account.'),
        'cta_label' => (string) $this->t('View billing'),
        'cta_url' => $billingUrl,
        'available' => $this->moduleHandler->moduleExists('myeventlane_donations') && $billingUrl !== NULL,
      ],
      'support' => [
        'title' => (string) $this->t('Need a hand?'),
        'body' => (string) $this->t('If something looks wrong with a payout or refund, our support team can help.'),
        'cta_label' => (string) $this->t('Contact support'),
        'cta_url' => $supportUrl,
        'help_label' => (string) $this->t('Help Centre'),
        'help_url' => '/help',
      ],
      'analytics' => [
        'payments_hub_opened' => TRUE,
        'payment_health_state' => $health['state'] ?? 'unknown',
      ],
    ];
  }

  /**
   * Builds the refunds section of the hub.
   *
   * @return array<string, mixed>
   *   Refund summary for Twig.
   */
  private function buildRefundsSection(): array {
    $pending = 0;
    $completed = 0;
    $declined = 0;
    $hubUrl = $this->safeRouteUrl('myeventlane_escalations_refunds.vendor_refund_summary');

    if ($this->refundsRepository !== NULL
      && $this->refundsMetrics !== NULL
      && method_exists($this->refundsRepository, 'findVendorSummary')
      && method_exists($this->refundsMetrics, 'calculateForVendor')) {
      try {
        $vendor = $this->vendorResolver->resolveFromCurrentUser();
        $owner = $vendor?->getOwner();
        if ($owner) {
          $data = $this->refundsRepository->findVendorSummary((int) $owner->id(), 90);
          $metrics = $this->refundsMetrics->calculateForVendor($data['logs'], $data['requests']);
          $byRequest = $metrics['requests_by_status'] ?? [];
          $byLog = $metrics['logs_by_status'] ?? [];
          $pending = (int) (($byRequest['pending'] ?? 0) + ($byLog['pending'] ?? 0));
          $completed = (int) (($byRequest['approved'] ?? 0) + ($byLog['completed'] ?? 0));
          $declined = (int) (($byRequest['rejected'] ?? 0) + ($byRequest['declined'] ?? 0) + ($byLog['failed'] ?? 0));
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Payments hub refund summary failed: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return [
      'pending' => $pending,
      'completed' => $completed,
      'declined' => $declined,
      'hub_url' => $hubUrl,
      'cta_label' => (string) $this->t('Review refunds'),
      'empty' => ($pending + $completed + $declined) === 0,
      'empty_body' => (string) $this->t('No refund activity yet. Requests from guests will show up here.'),
      'quick_actions' => array_values(array_filter([
        $hubUrl ? [
          'label' => (string) $this->t('Refund activity'),
          'url' => $hubUrl,
        ] : NULL,
      ])),
    ];
  }

  /**
   * Builds a route URL, or NULL if the route is missing.
   *
   * @param string $route
   *   Route name.
   * @param array<string, mixed> $params
   *   Route parameters.
   * @param array<string, mixed> $options
   *   URL options.
   *
   * @return string|null
   *   Generated URL or NULL.
   */
  private function safeRouteUrl(string $route, array $params = [], array $options = []): ?string {
    try {
      return Url::fromRoute($route, $params, $options)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
