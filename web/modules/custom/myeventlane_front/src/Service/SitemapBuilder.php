<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_legal\Service\LegalSettingsService;

/**
 * Builds grouped sitemap link sections aligned with the live public footer.
 */
final class SitemapBuilder {

  public function __construct(
    private readonly PublicFooterNavigationBuilder $footerNavigation,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?LegalSettingsService $legalSettings = NULL,
  ) {}

  /**
   * @return list<array{title: string, links: list<array{title: string, url: string}>}>
   */
  public function buildSections(): array {
    $sections = [];
    foreach ($this->footerNavigation->buildSections() as $section) {
      $links = [];
      foreach ($section['links'] as $link) {
        $links[] = [
          'title' => (string) $link['title'],
          'url' => $link['url'],
        ];
      }
      $sections[] = [
        'title' => (string) $section['title'],
        'links' => $links,
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

    $links[] = [
      'title' => 'Sitemap',
      'url' => Url::fromRoute('myeventlane_front.sitemap')->toString(),
    ];

    return $links;
  }

}
