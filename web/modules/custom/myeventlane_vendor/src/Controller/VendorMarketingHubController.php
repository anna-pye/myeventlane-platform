<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorMarketingHubBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Organiser Marketing Hub (Event Growth Centre).
 *
 * Product language: Marketing, Share, Boost, Widgets.
 * Never Grow / Promote / advertising jargon in the hub chrome.
 */
final class VendorMarketingHubController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly VendorMarketingHubBuilder $hubBuilder,
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
      $container->get('myeventlane_vendor.marketing_hub_builder'),
    );
  }

  /**
   * Renders /vendor/marketing.
   */
  public function hub(): array {
    $hub = $this->hubBuilder->build();

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => NULL,
      'body' => [
        '#theme' => 'myeventlane_vendor_marketing_hub',
        '#hub' => $hub,
        '#attached' => [
          'library' => [
            'myeventlane_vendor/marketing_hub',
          ],
          'drupalSettings' => [
            'melMarketingHub' => [
              'analytics' => $hub['analytics'] ?? [],
            ],
          ],
        ],
      ],
      // Booking / Boost / share data are live reads — do not page-cache KPIs.
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'max-age' => 0,
      ],
    ]);
  }

  /**
   * Redirects legacy /vendor/boost into the Marketing hub Boost section.
   *
   * Includes both ?section=boost (JS scroll + focus) and #boost (native
   * in-page target when JavaScript is unavailable or the library fails).
   */
  public function redirectBoost(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('myeventlane_vendor.console.marketing', [], [
        'query' => ['section' => 'boost'],
        'fragment' => 'boost',
      ])->toString(),
      302,
    );
  }

}
