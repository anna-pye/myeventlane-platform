<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendors tab – vendor management overview.
 */
final class VendorController extends ControllerBase {

  public function __construct(
    private readonly MelAdminShellBuilder $adminShellBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_core.mel_admin_shell_builder'),
    );
  }

  /**
   * Returns the Vendors overview page.
   */
  public function overview(): array {
    $vendorUrl = Url::fromRoute('entity.myeventlane_vendor.collection')->toString();

    $main = [
      '#theme' => 'platform_control_centre_vendors',
      '#vendor_url' => $vendorUrl,
      '#cache' => [
        'contexts' => ['user.roles'],
        'max-age' => 300,
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->t('Vendors'),
      $this->t('Vendor accounts, stores, and Stripe Connect.'),
    );
  }

}
