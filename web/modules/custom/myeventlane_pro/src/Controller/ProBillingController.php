<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProBillingPortalService;
use Drupal\myeventlane_pro\Service\ProRecoveryAnalyticsService;
use Drupal\myeventlane_pro\Service\ProSubscriptionStatusService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Vendor billing management: plan summary, payment updates, cancellation entry.
 */
final class ProBillingController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  private const BILLING_SCHEDULE = 'mel_pro_monthly';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MessengerInterface $messenger,
    private readonly LoggerChannelInterface $logger,
    private readonly ProRecoveryAnalyticsService $recoveryAnalyticsService,
    private readonly ProSubscriptionStatusService $statusService,
    private readonly ProBillingPortalService $billingPortalService,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('messenger'),
      $container->get('logger.channel.myeventlane_pro'),
      $container->get('myeventlane_pro.recovery_analytics'),
      $container->get('myeventlane_pro.subscription_status'),
      $container->get('myeventlane_pro.billing_portal'),
      $container->get('current_user'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Renders the /vendor/pro/manage billing page.
   */
  public function manage(): RedirectResponse|array {
    $overviewUrl = Url::fromRoute('myeventlane_pro.overview')->toString();
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface) {
      return new RedirectResponse($overviewUrl);
    }

    $subscription = $this->loadLatestSubscription((int) $user->id());
    if (!$subscription instanceof SubscriptionInterface) {
      $this->messenger->addWarning($this->t('No Pro subscription found.'));
      return new RedirectResponse($overviewUrl);
    }

    $status = $this->statusService->getStatusForUser($user);
    $state = $subscription->getState();
    $statusLabel = ucfirst($state->getLabel() ?? $state->getId());

    $nextBilling = NULL;
    $renewalInDays = NULL;
    $renewalDateStale = FALSE;
    if (method_exists($subscription, 'getNextRenewalTime')) {
      $timestamp = $subscription->getNextRenewalTime();
      if ($timestamp) {
        $renewalTimestamp = (int) $timestamp;
        $delta = $renewalTimestamp - $this->time->getRequestTime();
        if ($delta > 0) {
          $nextBilling = date('F j, Y', $renewalTimestamp);
          $renewalInDays = (int) ceil($delta / 86400);
        }
        else {
          $renewalDateStale = TRUE;
        }
      }
    }

    $startedDate = NULL;
    // Commerce Recurring uses start_date for the first billing period. During
    // a free trial that date is in the future, so it is not the date the
    // organiser started Pro. The entity creation time is the canonical signup
    // date for this organiser-facing label.
    if (method_exists($subscription, 'getCreatedTime')) {
      $created = (int) $subscription->getCreatedTime();
      if ($created > 0) {
        $startedDate = date('F j, Y', $created);
      }
    }
    if ($startedDate === NULL && method_exists($subscription, 'getStartDate')) {
      $start = $subscription->getStartDate();
      if ($start) {
        $startedDate = $start->format('F j, Y');
      }
    }

    $settings = $this->configFactory->get('myeventlane_pro.settings');
    $billingPortalEnabled = (bool) ($settings->get('billing_portal_enabled') ?? FALSE);
    $billingPortalUrl = Url::fromRoute('myeventlane_pro.billing_portal')->toString();
    $supportUrl = trim((string) ($settings->get('billing_support_url') ?? ''));

    $cancelUrl = NULL;
    $reactivateUrl = NULL;
    if (($status['cancel_at_period_end'] ?? FALSE)) {
      $reactivateUrl = Url::fromRoute('myeventlane_pro.reactivate')->toString();
    }
    elseif (($status['can_cancel'] ?? FALSE)) {
      $cancelUrl = Url::fromRoute('myeventlane_pro.cancel_request')->toString();
    }

    $roiSummary = $this->buildRoiSummary((int) $user->id());
    $graceDaysRemaining = $this->resolveGraceDaysRemaining($user);

    return [
      '#theme' => 'vendor_pro_manage',
      '#subscription_status' => $statusLabel,
      '#started_date' => $startedDate,
      '#next_billing_date' => $nextBilling,
      '#renewal_in_days' => $renewalInDays,
      '#renewal_date_stale' => $renewalDateStale,
      '#grace_days_remaining' => $graceDaysRemaining,
      '#cancel_url' => $cancelUrl,
      '#reactivate_url' => $reactivateUrl,
      '#billing_history' => $this->buildBillingHistory($subscription),
      '#roi_summary' => $roiSummary,
      '#pro_status' => $status,
      '#plan_label' => $status['plan_label'] ?? 'MEL Pro',
      '#billing_schedule_label' => $this->t('Monthly'),
      '#billing_portal_enabled' => $billingPortalEnabled,
      '#billing_portal_url' => $billingPortalUrl,
      '#billing_support_url' => $supportUrl !== '' ? $supportUrl : NULL,
      '#attached' => [
        'library' => ['myeventlane_pro/pro'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $this->getProCacheTags((int) $user->id()),
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Builds customer-owned Pro invoice and receipt links from Commerce orders.
   *
   * @return array<int, array<string, mixed>>
   *   Billing history rows, newest first.
   */
  private function buildBillingHistory(SubscriptionInterface $subscription): array {
    $orders = $subscription->getOrders();
    $initialOrder = $subscription->getInitialOrder();
    if ($initialOrder !== NULL) {
      $orders[(int) $initialOrder->id()] = $initialOrder;
    }

    $rows = [];
    foreach ($orders as $order) {
      $orderId = (int) ($order->id() ?? 0);
      if ($orderId <= 0 || (int) $order->getCustomerId() !== (int) $this->currentUser->id()) {
        continue;
      }
      $placed = (int) ($order->getPlacedTime() ?? 0);
      $total = $order->getTotalPrice();
      $rows[] = [
        'order_id' => $orderId,
        'date' => $placed > 0 ? date('j M Y', $placed) : $this->t('Pending'),
        'amount' => $total !== NULL ? $total->__toString() : '—',
        'status' => (string) ($order->getState()->getLabel() ?? $order->getState()->getId()),
        'receipt_url' => $placed > 0 && $total !== NULL && !$total->isZero()
          ? Url::fromRoute('myeventlane_checkout_flow.order_tax_invoice_pdf', ['commerce_order' => $orderId])->toString()
          : NULL,
        'placed' => $placed,
      ];
    }
    usort($rows, static fn(array $a, array $b): int => $b['placed'] <=> $a['placed']);
    return $rows;
  }

  /**
   * Redirects to Stripe Billing Portal when enabled and resolvable.
   */
  public function portal(): RedirectResponse {
    $fallback = Url::fromRoute('myeventlane_pro.manage')->toString();
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface) {
      return new RedirectResponse($fallback);
    }

    $url = $this->billingPortalService->createBillingPortalSessionUrl($user);
    if ($url === NULL) {
      $this->messenger->addWarning($this->t('Billing management is not available online yet. Please contact support to update your payment method.'));
      $this->logger->notice('MEL Pro billing portal redirect unavailable for user @uid.', [
        '@uid' => (string) $user->id(),
      ]);
      return new RedirectResponse($fallback);
    }

    // Stripe returns an absolute external URL. Drupal deliberately rejects
    // external targets on a normal RedirectResponse during response handling.
    return new TrustedRedirectResponse($url);
  }

  /**
   * Loads the user's latest Pro subscription.
   */
  private function loadLatestSubscription(int $uid): ?SubscriptionInterface {
    $ids = $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->sort('subscription_id', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $subscription = $this->entityTypeManager
      ->getStorage('commerce_subscription')
      ->load(reset($ids));

    return $subscription instanceof SubscriptionInterface ? $subscription : NULL;
  }

  /**
   * Resolves days remaining in grace period for the user.
   */
  private function resolveGraceDaysRemaining(UserInterface $user): ?int {
    if (!$user->hasField('field_pro_grace_expires')) {
      return NULL;
    }

    $expiry = (int) ($user->get('field_pro_grace_expires')->value ?? 0);
    if ($expiry <= 0) {
      return NULL;
    }

    $remaining = $expiry - time();
    if ($remaining <= 0) {
      return 0;
    }

    return (int) ceil($remaining / 86400);
  }

  /**
   * Builds the ROI summary for the current Pro vendor.
   *
   * @return array{pro_cost: float, recovered_revenue: float, roi_multiple: float}|null
   *   ROI summary payload, or NULL when no vendor store is resolvable.
   */
  private function buildRoiSummary(int $uid): ?array {
    if ($uid <= 0) {
      return NULL;
    }

    $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $vendorIds = $vendorStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if ($vendorIds === []) {
      return NULL;
    }

    $vendor = $vendorStorage->load((int) reset($vendorIds));
    if (!$vendor instanceof Vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return NULL;
    }

    $store = $vendor->get('field_vendor_store')->entity;
    if (!$store || $store->id() === NULL) {
      return NULL;
    }

    return $this->recoveryAnalyticsService->estimateProROI((int) $store->id(), 1);
  }

  /**
   * Builds cache tags for Pro pages.
   *
   * @return string[]
   *   Cache tag list.
   */
  private function getProCacheTags(int $uid): array {
    $tags = ['user:' . $uid];
    if ($uid <= 0) {
      return $tags;
    }

    $vendorIds = $this->entityTypeManager->getStorage('myeventlane_vendor')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();
    if ($vendorIds === []) {
      return $tags;
    }

    $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load((int) reset($vendorIds));
    if ($vendor instanceof Vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
      if ($store && $store->id() !== NULL) {
        $storeId = (int) $store->id();
        $tags[] = 'pro_subscription:' . $storeId;
        $tags[] = 'commerce_store:' . $storeId;
      }
    }

    return $tags;
  }

}
