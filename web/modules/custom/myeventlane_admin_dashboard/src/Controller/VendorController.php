<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendors tab – vendor management overview.
 */
final class VendorController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static();
  }

  /**
   * Returns the Vendors overview page.
   */
  public function overview(): array {
    $vendorUrl = Url::fromRoute('entity.myeventlane_vendor.collection')->toString();

    return [
      '#theme' => 'platform_control_centre_vendors',
      '#vendor_url' => $vendorUrl,
      '#cache' => [
        'contexts' => ['user.roles'],
        'max-age' => 300,
      ],
    ];
  }

}
