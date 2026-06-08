<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\GovernedOperationalTemplates;
use Drupal\myeventlane_core\Service\VendorFollowService;
use Drupal\myeventlane_vendor\Service\VendorCardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lists organisers the customer follows (myeventlane_vendor_follow).
 */
final class FollowedOrganisersController extends ControllerBase {

  public function __construct(
    private readonly VendorFollowService $vendorFollowService,
    private readonly VendorCardBuilder $vendorCardBuilder,
    private readonly GovernedOperationalTemplates $operationalTemplates,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.vendor_follow'),
      $container->get('myeventlane_vendor.vendor_card_builder'),
      $container->get('myeventlane_surface.governed_operational_templates'),
      $container->get('csrf_token'),
    );
  }

  /**
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\Response
   */
  public function page(): array|Response {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new RedirectResponse(
        Url::fromRoute('user.login', [], [
          'query' => ['destination' => '/my-organisers'],
        ])->toString(),
        302
      );
    }

    $vendors = $this->vendorFollowService->loadFollowedVendors($account);
    $cards = [];
    foreach ($vendors as $vendor) {
      $cards[] = $this->vendorCardBuilder->build($vendor, TRUE);
    }

    $cache = (new CacheableMetadata())
      ->addCacheContexts(['user', 'user.permissions'])
      ->addCacheTags(['myeventlane_vendor_follow_list', 'user:' . $account->id()]);
    foreach ($vendors as $vendor) {
      $cache->addCacheableDependency($vendor);
    }

    $build = [
      '#theme' => 'mel_account_followed_organisers',
      '#vendors' => $cards,
      '#empty' => $cards === [] ? $this->operationalTemplates->customerFollowedOrganisersEmpty() : NULL,
      '#attached' => [
        'library' => [
          'myeventlane_theme/global-styling',
          'myeventlane_core/vendor_public',
        ],
        'drupalSettings' => [
          'melVendorPublic' => [
            'csrfToken' => $this->csrfToken->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
          ],
        ],
      ],
    ];
    $cache->applyTo($build);
    return $build;
  }

}
