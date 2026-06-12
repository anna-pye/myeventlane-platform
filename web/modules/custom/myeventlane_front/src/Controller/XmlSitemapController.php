<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a minimal XML sitemap for public marketing and guide URLs.
 */
final class XmlSitemapController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Returns XML sitemap entries for key public pages.
   */
  public function page(): Response {
    $urls = [
      $this->urlEntry(Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(), time()),
      $this->urlEntry(Url::fromRoute('myeventlane_front.organiser_hub', [], ['absolute' => TRUE])->toString(), time()),
      $this->urlEntry(Url::fromRoute('myeventlane_front.blog_landing', [], ['absolute' => TRUE])->toString(), time()),
      $this->urlEntry(Url::fromRoute('myeventlane_help_centre.home', [], ['absolute' => TRUE])->toString(), time()),
    ];

    $storage = $this->entityTypeManager()->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'blog_post')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 500)
      ->execute();

    if (is_array($nids)) {
      $nodes = $storage->loadMultiple($nids);
      foreach ($nids as $nid) {
        if (!isset($nodes[$nid])) {
          continue;
        }
        $node = $nodes[$nid];
        $urls[] = $this->urlEntry($node->toUrl('canonical', ['absolute' => TRUE])->toString(), (int) $node->getChangedTime());
      }
    }

    $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
    $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $entry) {
      $xml[] = '  <url>';
      $xml[] = '    <loc>' . htmlspecialchars($entry['loc'], ENT_XML1) . '</loc>';
      $xml[] = '    <lastmod>' . htmlspecialchars($entry['lastmod'], ENT_XML1) . '</lastmod>';
      $xml[] = '  </url>';
    }
    $xml[] = '</urlset>';

    return new Response(implode("\n", $xml), 200, [
      'Content-Type' => 'application/xml; charset=utf-8',
      'Cache-Control' => 'public, max-age=3600',
    ]);
  }

  /**
   * @return array{loc: string, lastmod: string}
   */
  private function urlEntry(string $loc, int $changed): array {
    return [
      'loc' => $loc,
      'lastmod' => $this->dateFormatter->format($changed, 'custom', 'Y-m-d'),
    ];
  }

}
