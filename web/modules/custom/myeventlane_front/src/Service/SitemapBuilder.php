<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Url;
use Drupal\myeventlane_legal\Service\LegalSettingsService;

/**
 * Builds grouped sitemap link sections from deployable menus and routes.
 */
final class SitemapBuilder {

  /**
   * @var list<string>
   */
  private const FOOTER_MENUS = [
    'footer-find' => 'Discover',
    'footer-host' => 'Host',
    'footer-about' => 'About',
    'footer-community' => 'Community',
  ];

  public function __construct(
    private readonly MenuLinkTreeInterface $menuLinkTree,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?LegalSettingsService $legalSettings = NULL,
  ) {}

  /**
   * @return list<array{title: string, links: list<array{title: string, url: string}>}>
   */
  public function buildSections(): array {
    $sections = [];

    foreach (self::FOOTER_MENUS as $menuName => $title) {
      $links = $this->buildMenuLinks($menuName);
      if ($links !== []) {
        $sections[] = [
          'title' => $title,
          'links' => $links,
        ];
      }
    }

    $supportLinks = $this->buildSupportLinks();
    if ($supportLinks !== []) {
      $sections[] = [
        'title' => 'Support and trust',
        'links' => $supportLinks,
      ];
    }

    $legalLinks = $this->buildLegalLinks();
    if ($legalLinks !== []) {
      $sections[] = [
        'title' => 'Legal',
        'links' => $legalLinks,
      ];
    }

    return $sections;
  }

  /**
   * @return list<array{title: string, url: string}>
   */
  private function buildMenuLinks(string $menuName): array {
    $parameters = new MenuTreeParameters();
    $parameters->onlyEnabledLinks();
    $tree = $this->menuLinkTree->load($menuName, $parameters);
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuLinkTree->transform($tree, $manipulators);
    $links = [];
    foreach ($tree as $element) {
      $link = $element->link;
      if ($link === NULL) {
        continue;
      }
      $url = $link->getUrlObject();
      if (!$url->access()) {
        continue;
      }
      $links[] = [
        'title' => (string) $link->getTitle(),
        'url' => $url->toString(),
      ];
    }
    return $links;
  }

  /**
   * @return list<array{title: string, url: string}>
   */
  private function buildSupportLinks(): array {
    $links = [];

    if ($this->moduleHandler->moduleExists('myeventlane_help_centre')) {
      $links[] = $this->linkFromRoute('Help Centre', 'myeventlane_help_centre.home');
      $links[] = $this->linkFromRoute('Policies and trust', 'myeventlane_help_centre.policies_index');
    }

    $links[] = [
      'title' => 'Contact us',
      'url' => Url::fromUri('internal:/contact')->toString(),
    ];

    $articlePaths = [
      'Refunds' => '/help/policies/refund-policy',
      'Community guidelines' => '/help/policies/community-guidelines',
      'Trust and safety' => '/help/policies/trust-and-safety',
    ];
    foreach ($articlePaths as $title => $path) {
      $links[] = [
        'title' => $title,
        'url' => Url::fromUri('internal:' . $path)->toString(),
      ];
    }

    if ($this->moduleHandler->moduleExists('myeventlane_public_trust')) {
      $trustLink = $this->linkFromRoute('Trust and transparency', 'myeventlane_public_trust.page');
      if ($trustLink !== NULL) {
        $links[] = $trustLink;
      }
    }

    $links[] = [
      'title' => 'Accessibility',
      'url' => Url::fromUri('internal:/accessibility')->toString(),
    ];

    return array_values(array_filter($links));
  }

  /**
   * @return list<array{title: string, url: string}>
   */
  private function buildLegalLinks(): array {
    $links = [];
    if ($this->legalSettings !== NULL) {
      foreach ([
        'Privacy policy' => $this->legalSettings->getPrivacyUrl(),
        'Terms of service' => $this->legalSettings->getCustomerTermsUrl(),
        'Cookie policy' => $this->legalSettings->getCookiePolicyUrl(),
      ] as $title => $path) {
        if ($path !== '') {
          $links[] = [
            'title' => $title,
            'url' => Url::fromUri(str_starts_with($path, 'http') ? $path : 'internal:' . $path)->toString(),
          ];
        }
      }
    }

    if ($this->moduleHandler->moduleExists('myeventlane_legal')) {
      $links[] = [
        'title' => 'Cookie preferences',
        'url' => Url::fromRoute('myeventlane_legal.cookies')->toString(),
      ];
    }

    return $links;
  }

  /**
   * @return array{title: string, url: string}|null
   */
  private function linkFromRoute(string $title, string $routeName): ?array {
    try {
      $url = Url::fromRoute($routeName);
      if (!$url->access()) {
        return NULL;
      }
      return [
        'title' => $title,
        'url' => $url->toString(),
      ];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
