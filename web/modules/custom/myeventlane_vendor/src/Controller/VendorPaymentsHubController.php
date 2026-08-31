<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorPaymentsHubBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Organiser Payments Hub (Trust Centre).
 *
 * Product language: Payments, Payouts, Refunds, Tax, Payment health.
 * Never Commerce / Gateway / Store / Plugin.
 */
final class VendorPaymentsHubController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly VendorPaymentsHubBuilder $hubBuilder,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_vendor.payments_hub_builder'),
    );
  }

  /**
   * Renders /vendor/payments.
   */
  public function hub(): array {
    $hub = $this->hubBuilder->build();

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      // The hub owns the organiser-facing page heading so it can use the
      // shared Dashboard hierarchy without rendering a second H1.
      'title' => NULL,
      'body' => [
        '#theme' => 'myeventlane_vendor_payments_hub',
        '#hub' => $hub,
        '#attached' => [
          'drupalSettings' => [
            'melPaymentsHub' => [
              'analytics' => $hub['analytics'] ?? [],
            ],
          ],
        ],
      ],
      // Stripe balances/payouts are live API reads — do not page-cache KPIs.
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'max-age' => 0,
      ],
    ]);
  }

}
