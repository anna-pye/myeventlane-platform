<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_escalations_analytics\Service\EscalationAnalyticsQuery;
use Drupal\myeventlane_escalations_analytics\Service\EscalationMetricsCalculator;
use Drupal\myeventlane_escalations_analytics\Service\VendorHealthEvaluator;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendor-facing analytics dashboard.
 *
 * Shows only the current vendor's own metrics and health bucket.
 * No other vendor data, no public rankings. No breach rate — too punitive.
 */
final class VendorAnalyticsController extends ControllerBase {

  public function __construct(
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly EscalationAnalyticsQuery $analyticsQuery,
    private readonly EscalationMetricsCalculator $metricsCalculator,
    private readonly VendorHealthEvaluator $healthEvaluator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_escalations_analytics.query'),
      $container->get('myeventlane_escalations_analytics.metrics'),
      $container->get('myeventlane_escalations_analytics.health'),
    );
  }

  /**
   * Renders the vendor's own analytics dashboard.
   */
  public function dashboard(): array {
    $vendor = $this->vendorResolver->resolveFromUser($this->currentUser());

    if (!$vendor) {
      return [
        '#markup' => '<p>' . $this->t('You are not associated with a vendor account.') . '</p>',
      ];
    }

    $vendor_id = (int) $vendor->id();
    $escalations = $this->analyticsQuery->query(vendor_id: $vendor_id);
    $metrics = $this->metricsCalculator->calculate($escalations);
    $health = $this->healthEvaluator->evaluate($metrics);

    return [
      '#theme' => 'escalation_analytics_vendor',
      '#metrics' => $metrics,
      '#health' => $health,
      '#vendor_name' => $vendor->label(),
      '#cache' => ['max-age' => 0],
    ];
  }

}
