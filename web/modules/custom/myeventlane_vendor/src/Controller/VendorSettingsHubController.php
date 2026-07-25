<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorSettingsHubBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Organiser Workspace Settings hub.
 *
 * Product language: Profile, Brand, Payments, Notifications, Support, Help.
 * Never Vendor / Commerce / Store / Gateway / Drupal configuration.
 */
final class VendorSettingsHubController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly VendorSettingsHubBuilder $hubBuilder,
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
      $container->get('myeventlane_vendor.settings_hub_builder'),
    );
  }

  /**
   * Renders /vendor/settings.
   */
  public function hub(): array {
    $hub = $this->hubBuilder->build();

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => (string) $this->t('Workspace Settings'),
      'body' => [
        '#theme' => 'myeventlane_vendor_settings_hub',
        '#hub' => $hub,
        '#attached' => [
          'drupalSettings' => [
            'melSettingsHub' => [
              'analytics' => $hub['analytics'] ?? [],
            ],
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'max-age' => 0,
      ],
    ]);
  }

}
