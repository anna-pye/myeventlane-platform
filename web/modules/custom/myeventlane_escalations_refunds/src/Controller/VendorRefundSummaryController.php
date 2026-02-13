<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_refunds\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_escalations_refunds\Repository\RefundsCorrelationRepository;
use Drupal\myeventlane_escalations_refunds\Service\RefundsMetricsCalculator;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendor-facing aggregated refund summary.
 *
 * Shows only the current vendor's own refund metrics.
 * No PII, no customer data, no order IDs, no other vendor data.
 */
final class VendorRefundSummaryController extends ControllerBase {

  public function __construct(
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly RefundsCorrelationRepository $repository,
    private readonly RefundsMetricsCalculator $calculator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_escalations_refunds.repository'),
      $container->get('myeventlane_escalations_refunds.metrics'),
    );
  }

  /**
   * Renders the vendor's aggregated refund summary.
   */
  public function summary(): array {
    $vendor = $this->vendorResolver->resolveFromUser($this->currentUser());

    if (!$vendor) {
      return [
        '#markup' => '<p>' . $this->t('You are not associated with a vendor account.') . '</p>',
      ];
    }

    // Resolve vendor owner UID for refund table queries.
    $vendor_owner = $vendor->getOwner();
    if (!$vendor_owner) {
      return [
        '#markup' => '<p>' . $this->t('Unable to resolve vendor account.') . '</p>',
      ];
    }

    $vendor_uid = (int) $vendor_owner->id();
    $window_days = (int) ($this->config('myeventlane_escalations_refunds.settings')
      ->get('vendor_summary_window_days') ?? 90);

    $data = $this->repository->findVendorSummary($vendor_uid, $window_days);
    $metrics = $this->calculator->calculateForVendor($data['logs'], $data['requests']);

    return [
      '#theme' => 'vendor_refund_summary',
      '#metrics' => $metrics,
      '#vendor_name' => $vendor->label(),
      '#window_days' => $window_days,
      '#cache' => ['max-age' => 0],
    ];
  }

}
