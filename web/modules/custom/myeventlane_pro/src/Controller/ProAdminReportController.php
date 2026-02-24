<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProReportingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin report page for Pro subscriptions at /admin/platform/pro.
 */
final class ProAdminReportController extends ControllerBase {

  public function __construct(
    private readonly ProReportingService $reportingService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_pro.reporting'),
    );
  }

  /**
   * Renders the Pro subscription admin report.
   */
  public function report(): array {
    $metrics = $this->reportingService->getMetrics();
    $vendorRows = $this->reportingService->getVendorRows();

    $rows = [];
    foreach ($vendorRows as $row) {
      $userLink = NULL;
      $subscriptionLink = NULL;

      try {
        if ($row['uid'] > 0) {
          $userLink = Url::fromRoute('entity.user.edit_form', ['user' => $row['uid']])->toString();
        }
      }
      catch (\Exception) {
        // Route may not be accessible; skip link.
      }

      try {
        if ($row['subscription_id'] > 0) {
          $subscriptionLink = Url::fromRoute('entity.commerce_subscription.canonical', [
            'commerce_subscription' => $row['subscription_id'],
          ])->toString();
        }
      }
      catch (\Exception) {
        // Route may not exist; skip link.
      }

      $rows[] = $row + [
        'user_edit_url' => $userLink,
        'subscription_url' => $subscriptionLink,
      ];
    }

    return [
      '#theme' => 'admin_pro_report',
      '#metrics' => $metrics,
      '#rows' => $rows,
      '#attached' => [
        'library' => [
          'myeventlane_pro/admin_report',
        ],
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['commerce_subscription_list', 'user_list'],
        'max-age' => 0,
      ],
    ];
  }

}
