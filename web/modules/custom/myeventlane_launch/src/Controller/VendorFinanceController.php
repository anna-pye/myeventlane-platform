<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Controller;

use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Legacy finance landing redirects into the Payments Hub (VX2-07).
 */
final class VendorFinanceController extends VendorConsoleBaseController {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
    );
  }

  /**
   * Redirects /vendor/finance to the Payments Hub overview.
   */
  public function finance(): RedirectResponse {
    $this->assertVendorAccess();
    return new RedirectResponse(
      Url::fromRoute('myeventlane_vendor.console.payments', [], [
        'fragment' => 'overview',
      ])->toString(),
      302,
    );
  }

}
