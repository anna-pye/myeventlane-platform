<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_launch\Service\VendorFinanceSummaryBuilder;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finance landing page for vendor launch readiness.
 */
final class VendorFinanceController extends VendorConsoleBaseController {

  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly VendorFinanceSummaryBuilder $financeSummaryBuilder,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_launch.vendor_finance_summary'),
    );
  }

  /**
   * Builds /vendor/finance landing page.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function finance(): array {
    $summary = $this->financeSummaryBuilder->buildCurrentVendorSummary();

    $body = [
      '#theme' => 'myeventlane_launch_vendor_finance_page',
      '#summary' => $summary,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['commerce_order_list'],
        'max-age' => 300,
      ],
    ];

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => (string) $this->t('Finance'),
      'body' => $body,
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['commerce_order_list'],
        'max-age' => 300,
      ],
    ]);
  }

}
