<?php

declare(strict_types=1);

namespace Drupal\mel_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\mel_admin_dashboard\Service\DashboardMetricsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform Control Centre dashboard controller.
 */
class DashboardController extends ControllerBase {

  public function __construct(
    protected DashboardMetricsService $metrics,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mel_admin_dashboard.metrics'),
    );
  }

  /**
   * Renders the Platform Control Centre overview page.
   *
   * @return array
   *   Render array.
   */
  public function overview(): array {
    $admin_recent_orders = NULL;

    $view_recent = $this->entityTypeManager()->getStorage('view')->load('admin_recent_orders');
    if ($view_recent) {
      $admin_recent_orders = [
        '#type' => 'view',
        '#name' => 'admin_recent_orders',
        '#display_id' => 'block_1',
        '#arguments' => [],
      ];
    }

    return [
      '#theme' => 'mel_admin_dashboard',
      '#metrics' => $this->metrics->getMetrics(),
      '#admin_recent_orders' => $admin_recent_orders,
      '#escalations_url' => Url::fromRoute('entity.escalation.collection')->toString(),
      '#attached' => [
        'library' => [
          'mel_admin_dashboard/dashboard',
        ],
      ],
      '#cache' => [
        'contexts' => ['user.roles'],
        'max-age' => 300,
      ],
    ];
  }

}
