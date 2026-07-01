<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\EventCategoryUrlService;
use Drupal\taxonomy\TermInterface;

/**
 * Single source of truth for public footer navigation sections.
 */
final class PublicFooterNavigationBuilder {

  use StringTranslationTrait;

  /**
   * Curated category names for the Discover column.
   *
   * @var list<string>
   */
  private const FOOTER_CATEGORY_NAMES = [
    'Arts',
    'Community',
    'Markets',
    'Music',
    'LGBTQI+',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventCategoryUrlService $categoryUrlService,
  ) {}

  /**
   * Builds footer navigation sections for Twig and the HTML sitemap.
   *
   * @return list<array{id: string, title: \Drupal\Core\StringTranslation\TranslatableMarkup, links: list<array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, url: string, external?: bool}>}>
   */
  public function buildSections(): array {
    $discover_links = array_values(array_filter([
      $this->routeLink('All events', 'view.upcoming_events.page_events'),
      $this->routeLink('Today', 'view.upcoming_events.page_today'),
      $this->routeLink('This weekend', 'view.upcoming_events.page_this_weekend'),
      $this->routeLink('Free events', 'view.upcoming_events.page_free'),
      $this->routeLink('Community', 'view.upcoming_events.page_popular'),
    ]));
    $discover_links = array_merge($discover_links, $this->buildCategoryLinks());

    $organiser_links = array_values(array_filter([
      $this->routeLink('Create event', 'myeventlane_vendor.create_event_gateway'),
      $this->internalLink('Pricing & fees', '/pricing'),
      $this->internalLink('How it works', '/help/organisers'),
      $this->internalLink('Ticketing & RSVPs', '/help/organisers/choosing-rsvp-or-paid-tickets'),
      $this->internalLink('Manage attendees', '/help/organisers/managing-your-event-dashboard'),
      $this->routeLink('Vendor dashboard', 'myeventlane_vendor.console.dashboard'),
    ]));

    $support_links = array_values(array_filter([
      $this->routeLink('Help centre', 'myeventlane_help_centre.home'),
      $this->internalLink('Contact us', '/contact'),
      $this->internalLink('Refunds', '/help/policies/refund-policy'),
      $this->internalLink('Trust & safety', '/help/policies/trust-and-safety'),
      $this->internalLink('Community guidelines', '/help/policies/community-guidelines'),
      $this->routeLink('Trust & transparency', 'myeventlane_public_trust.page'),
      $this->internalLink('Accessibility', '/accessibility'),
    ]));

    $about_links = [
      $this->internalLink('About', '/about'),
      ...array_values(array_filter([
        $this->routeLink('Blog', 'myeventlane_front.blog_landing'),
        $this->routeLink('Organiser Hub', 'myeventlane_front.organiser_hub'),
      ])),
      $this->internalLink('Our story', '/our-story'),
      [
        'title' => $this->t('Instagram'),
        'url' => 'https://www.instagram.com/myeventlane',
        'external' => TRUE,
      ],
      [
        'title' => $this->t('Facebook'),
        'url' => 'https://www.facebook.com/myeventlane',
        'external' => TRUE,
      ],
      [
        'title' => $this->t('LinkedIn'),
        'url' => 'https://www.linkedin.com/company/myeventlane',
        'external' => TRUE,
      ],
    ];

    return [
      [
        'id' => 'discover',
        'title' => $this->t('Discover events'),
        'links' => $discover_links,
      ],
      [
        'id' => 'organisers',
        'title' => $this->t('For organisers'),
        'links' => $organiser_links,
      ],
      [
        'id' => 'support',
        'title' => $this->t('Support & trust'),
        'links' => $support_links,
      ],
      [
        'id' => 'about',
        'title' => $this->t('About MyEventLane'),
        'links' => $about_links,
      ],
    ];
  }

  /**
   * @return list<array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, url: string}>
   */
  private function buildCategoryLinks(): array {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $links = [];
    foreach (self::FOOTER_CATEGORY_NAMES as $name) {
      $terms = $term_storage->loadByProperties([
        'vid' => 'categories',
        'name' => $name,
      ]);
      if ($terms === []) {
        continue;
      }
      $term = reset($terms);
      if (!$term instanceof TermInterface) {
        continue;
      }
      if ($term->hasField('status') && !$term->isPublished()) {
        continue;
      }
      $links[] = [
        'title' => $term->label(),
        'url' => $this->categoryUrlService->getCategoryUrlString($term),
      ];
    }
    return $links;
  }

  /**
   * @return array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, url: string}|null
   */
  private function internalLink(string $title, string $path): array {
    return [
      'title' => $this->t($title),
      'url' => Url::fromUri('internal:' . $path)->toString(),
    ];
  }

  /**
   * @return array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, url: string}|null
   */
  private function routeLink(string $title, string $route_name, array $parameters = []): ?array {
    try {
      return [
        'title' => $this->t($title),
        'url' => Url::fromRoute($route_name, $parameters)->toString(),
      ];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
