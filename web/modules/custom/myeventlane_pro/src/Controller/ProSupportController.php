<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Organiser-facing guidance for MEL Pro.
 */
final class ProSupportController extends ControllerBase {

  /**
   * Builds the MEL Pro help page.
   */
  public function page(): array {
    $price = (float) ($this->config('myeventlane_pro.settings')
      ->get('pro_price') ?? 49);

    return [
      '#theme' => 'vendor_pro_support',
      '#pro_price' => 'A$' . number_format($price, 2),
      '#manage_url' => Url::fromRoute('myeventlane_pro.manage')->toString(),
      '#plans_url' => Url::fromRoute('myeventlane_pro.overview')->toString(),
      '#payment_method_url' => Url::fromRoute('myeventlane_pro.payment_method_update')->toString(),
      '#analytics_url' => Url::fromRoute('myeventlane_analytics.dashboard')->toString(),
      '#messages_url' => Url::fromRoute('myeventlane_vendor.console.messages')->toString(),
      '#marketing_url' => Url::fromRoute('myeventlane_vendor.console.marketing')->toString(),
      '#events_url' => Url::fromRoute('myeventlane_vendor.console.events')->toString(),
      '#contact_support_url' => Url::fromUri('internal:/vendor/support/add')->toString(),
      '#attached' => [
        'library' => ['myeventlane_pro/pro'],
      ],
      '#cache' => [
        'contexts' => ['user.roles'],
        'tags' => ['config:myeventlane_pro.settings'],
      ],
    ];
  }

}
