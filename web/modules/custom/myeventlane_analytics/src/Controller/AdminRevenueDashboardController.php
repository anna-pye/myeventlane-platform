<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_analytics\Phase7\Service\AdminRevenueQueryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Admin revenue dashboard: ticket net (Phase 7, Boost excluded) and boost net.
 *
 * Access requires permission "access myeventlane admin revenue dashboard".
 * Does not reuse vendor dashboard services.
 */
final class AdminRevenueDashboardController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\myeventlane_analytics\Phase7\Service\AdminRevenueQueryService $adminRevenueQuery
   *   Admin revenue query service.
   */
  public function __construct(
    private readonly AdminRevenueQueryService $adminRevenueQuery,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_analytics.admin_revenue_query'),
    );
  }

  /**
   * Builds the admin revenue dashboard.
   *
   * @return array
   *   Render array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the user does not have the admin revenue permission.
   */
  public function build(): array {
    if (!$this->currentUser()->hasPermission('access myeventlane admin revenue dashboard')) {
      throw new AccessDeniedHttpException();
    }

    $store_ids = $this->getAllStoreIds();
    $end = (int) time();
    $start = $end - (30 * 24 * 60 * 60);
    $currency = 'AUD';

    $revenue = $this->adminRevenueQuery->getPlatformRevenue($store_ids, $start, $end, $currency);

    return [
      '#theme' => 'admin_revenue_dashboard',
      '#ticket_net_cents' => $revenue['ticket_net_cents'],
      '#boost_net_cents' => $revenue['boost_net_cents'],
      '#total_cents' => $revenue['total_cents'],
      '#currency' => $currency,
      '#range_days' => 30,
    ];
  }

  /**
   * Returns all commerce store IDs for platform aggregation.
   *
   * @return list<int>
   */
  private function getAllStoreIds(): array {
    $storage = $this->entityTypeManager()->getStorage('commerce_store');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    return array_values(array_map('intval', $ids));
  }

}
