<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Conversion analytics service.
 *
 * Provides conversion funnel and drop-off analysis.
 */
final class ConversionAnalyticsService {

  /**
   * Constructs ConversionAnalyticsService.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AnalyticsDataService $dataService,
  ) {}

  /**
   * Gets conversion funnel with drop-off rates.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array with funnel stages, counts, and conversion rates.
   */
  public function getConversionFunnel(int $eventId): array {
    $funnel = $this->dataService->getConversionFunnel($eventId);

    if (empty($funnel) || $funnel['views'] === 0) {
      return $funnel;
    }

    // Calculate conversion rates.
    $funnel['conversion_rates'] = [
      'views_to_cart' => $funnel['views'] > 0
        ? round(($funnel['cart_additions'] / $funnel['views']) * 100, 1)
        : 0.0,
      'cart_to_checkout' => $funnel['cart_additions'] > 0
        ? round(($funnel['checkout_started'] / $funnel['cart_additions']) * 100, 1)
        : 0.0,
      'checkout_to_complete' => $funnel['checkout_started'] > 0
        ? round(($funnel['completed'] / $funnel['checkout_started']) * 100, 1)
        : 0.0,
      'overall' => $funnel['views'] > 0
        ? round(($funnel['completed'] / $funnel['views']) * 100, 1)
        : 0.0,
    ];

    // Calculate drop-off rates.
    $funnel['drop_off_rates'] = [
      'views_to_cart' => $funnel['views'] > 0
        ? round((($funnel['views'] - $funnel['cart_additions']) / $funnel['views']) * 100, 1)
        : 0.0,
      'cart_to_checkout' => $funnel['cart_additions'] > 0
        ? round((($funnel['cart_additions'] - $funnel['checkout_started']) / $funnel['cart_additions']) * 100, 1)
        : 0.0,
      'checkout_to_complete' => $funnel['checkout_started'] > 0
        ? round((($funnel['checkout_started'] - $funnel['completed']) / $funnel['checkout_started']) * 100, 1)
        : 0.0,
    ];

    return $funnel;
  }

  /**
   * Identifies conversion bottlenecks.
   *
   * @param int $eventId
   *   The event node ID.
   *
   * @return array
   *   Array of bottleneck stages with recommendations.
   */
  public function identifyBottlenecks(int $eventId): array {
    $funnel = $this->getConversionFunnel($eventId);

    if (empty($funnel) || !isset($funnel['drop_off_rates'])) {
      return [];
    }

    $bottlenecks = [];
    $dropOffs = $funnel['drop_off_rates'];

    // Identify stages with > 50% drop-off as bottlenecks.
    if ($dropOffs['views_to_cart'] > 50) {
      $bottlenecks[] = [
        'stage' => 'views_to_cart',
        'label' => 'Event page to cart',
        'drop_off_rate' => $dropOffs['views_to_cart'],
        'recommendation' => 'Consider improving event page design, adding social proof, or clarifying ticket pricing.',
      ];
    }

    if ($dropOffs['cart_to_checkout'] > 50) {
      $bottlenecks[] = [
        'stage' => 'cart_to_checkout',
        'label' => 'Cart to checkout',
        'drop_off_rate' => $dropOffs['cart_to_checkout'],
        'recommendation' => 'Consider simplifying the checkout process or reducing required fields.',
      ];
    }

    if ($dropOffs['checkout_to_complete'] > 30) {
      $bottlenecks[] = [
        'stage' => 'checkout_to_complete',
        'label' => 'Checkout to completion',
        'drop_off_rate' => $dropOffs['checkout_to_complete'],
        'recommendation' => 'Consider reviewing payment options, checkout flow, or adding trust indicators.',
      ];
    }

    return $bottlenecks;
  }

}


















