<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Builds schema.org Article and BreadcrumbList JSON-LD for blog posts.
 */
final class BlogStructuredDataBuilder {

  private const WORDS_PER_MINUTE = 200;

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * @return list<array<string, mixed>>
   */
  public function build(NodeInterface $node): array {
    if ($node->bundle() !== 'blog_post' || !$node->isPublished()) {
      return [];
    }

    $schemas = [];
    $article = $this->buildArticle($node);
    if ($article !== NULL) {
      $schemas[] = $article;
    }

    $breadcrumb = $this->buildBreadcrumb($node);
    if ($breadcrumb !== NULL) {
      $schemas[] = $breadcrumb;
    }

    return $schemas;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function buildArticle(NodeInterface $node): ?array {
    $canonical = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $description = '';
    if ($node->hasField('field_seo_description') && !$node->get('field_seo_description')->isEmpty()) {
      $description = trim((string) $node->get('field_seo_description')->value);
    }
    elseif ($node->hasField('field_excerpt') && !$node->get('field_excerpt')->isEmpty()) {
      $description = trim((string) $node->get('field_excerpt')->value);
    }
    elseif ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $description = trim(Html::decodeEntities(strip_tags((string) $node->get('body')->summary ?: $node->get('body')->value)));
    }

    $data = [
      '@context' => 'https://schema.org',
      '@type' => 'Article',
      'headline' => $node->label(),
      'url' => $canonical,
      'mainEntityOfPage' => $canonical,
      'dateModified' => $this->formatIso((int) $node->getChangedTime()),
      'datePublished' => $this->resolvePublishedDate($node),
    ];

    if ($description !== '') {
      $data['description'] = $description;
    }

    $owner = $node->getOwner();
    if ($owner instanceof UserInterface && !$owner->isAnonymous()) {
      $authorName = trim($owner->getDisplayName());
      if ($authorName !== '') {
        $data['author'] = [
          '@type' => 'Person',
          'name' => $authorName,
        ];
      }
    }

    $minutes = $this->estimateReadingMinutes($node);
    if ($minutes !== NULL) {
      $data['timeRequired'] = 'PT' . $minutes . 'M';
    }

    return $data;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function buildBreadcrumb(NodeInterface $node): ?array {
    $items = [
      [
        '@type' => 'ListItem',
        'position' => 1,
        'name' => 'Resources',
        'item' => Url::fromRoute('myeventlane_front.organiser_hub', [], ['absolute' => TRUE])->toString(),
      ],
      [
        '@type' => 'ListItem',
        'position' => 2,
        'name' => 'Guides',
        'item' => Url::fromRoute('view.mel_blog.page_blog', [], ['absolute' => TRUE])->toString(),
      ],
      [
        '@type' => 'ListItem',
        'position' => 3,
        'name' => $node->label(),
        'item' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ],
    ];

    return [
      '@context' => 'https://schema.org',
      '@type' => 'BreadcrumbList',
      'itemListElement' => $items,
    ];
  }

  private function resolvePublishedDate(NodeInterface $node): string {
    if ($node->hasField('field_publish_date') && !$node->get('field_publish_date')->isEmpty()) {
      $date = $node->get('field_publish_date')->date;
      if ($date !== NULL) {
        return $this->formatIso($date->getTimestamp());
      }
    }
    return $this->formatIso((int) $node->getCreatedTime());
  }

  private function formatIso(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'custom', 'c');
  }

  private function estimateReadingMinutes(NodeInterface $node): ?int {
    $text = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $text = (string) $node->get('body')->value;
    }
    $plain = Html::decodeEntities(strip_tags($text));
    $words = preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY);
    $count = is_array($words) ? count($words) : 0;
    if ($count === 0) {
      return NULL;
    }
    return max(1, (int) ceil($count / self::WORDS_PER_MINUTE));
  }

}
