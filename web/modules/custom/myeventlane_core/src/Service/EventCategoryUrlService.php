<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Canonical URLs and slug resolution for public event category discovery.
 *
 * Category listings live at /events/category/{slug} (view.upcoming_events.page_category).
 * Taxonomy term canonical routes and legacy /events/category/{tid} URLs redirect here.
 */
final class EventCategoryUrlService {

  private const VOCABULARY = 'categories';

  /**
   * Reserved first segments under /events/ (not category slugs).
   */
  private const RESERVED_EVENT_PATHS = [
    'category',
    'today',
    'this-weekend',
    'free',
    'popular',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Builds the canonical category listing URL for a term.
   */
  public function getCategoryUrl(TermInterface $term): Url {
    return Url::fromRoute('view.upcoming_events.page_category', [
      'arg_0' => $this->getSlug($term),
    ]);
  }

  /**
   * Returns the canonical absolute URL string for a category term.
   */
  public function getCategoryUrlString(TermInterface $term): string {
    return $this->getCategoryUrl($term)->toString();
  }

  /**
   * Resolves a route or view argument (slug or legacy numeric tid) to a term.
   */
  public function resolveTerm(int|string|null $argument): ?TermInterface {
    if ($argument === NULL || $argument === '') {
      return NULL;
    }

    $arg = (string) $argument;
    if (ctype_digit($arg)) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($arg);
      return ($term instanceof TermInterface && $term->bundle() === self::VOCABULARY)
        ? $term
        : NULL;
    }

    $normalized = strtolower(trim($arg));
    if ($normalized === '') {
      return NULL;
    }

    $map = $this->getSlugToTermMap();
    return $map[$normalized] ?? NULL;
  }

  /**
   * Whether the argument is a legacy numeric term ID in the URL path.
   */
  public function isLegacyTidArgument(int|string|null $argument): bool {
    if ($argument === NULL || $argument === '') {
      return FALSE;
    }
    $arg = (string) $argument;
    return ctype_digit($arg) && $this->resolveTerm($arg) instanceof TermInterface;
  }

  /**
   * Returns a category slug suitable for URL paths.
   */
  public function getSlug(TermInterface $term): string {
    if ($term->bundle() !== self::VOCABULARY) {
      return 'default';
    }

    if ($term->hasField('field_mel_slug') && !$term->get('field_mel_slug')->isEmpty()) {
      $slug_value = $term->get('field_mel_slug')->value;
      if (is_string($slug_value) && trim($slug_value) !== '') {
        return $this->normalizeSlug(trim(strtolower($slug_value)));
      }
    }

    $name = $term->label();
    $slug = strtolower($name);
    $slug = str_replace('&', 'and', $slug);
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    return $this->normalizeSlug($slug !== '' ? $slug : 'default');
  }

  /**
   * Whether a slug would collide with a reserved /events/* discovery path.
   */
  public function isReservedSlug(string $slug): bool {
    return in_array(strtolower(trim($slug)), self::RESERVED_EVENT_PATHS, TRUE);
  }

  /**
   * Normalizes known LGBTQI+ slug variants.
   */
  private function normalizeSlug(string $slug): string {
    if (in_array($slug, ['lgbtqi', 'lgbtq', 'lgbtqi+', 'lgbtqia+', 'lgbtq+'], TRUE)) {
      return 'lgbtqia';
    }
    return $slug !== '' ? $slug : 'default';
  }

  /**
   * Builds a cached slug => term map for the categories vocabulary.
   *
   * @return array<string, \Drupal\taxonomy\TermInterface>
   *   Slug-keyed terms.
   */
  private function getSlugToTermMap(): array {
    $cid = 'myeventlane:event_category_slug_map:v1';
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', self::VOCABULARY)
      ->execute();

    $map = [];
    if ($ids) {
      $terms = $storage->loadMultiple($ids);
      foreach ($terms as $term) {
        if (!$term instanceof TermInterface) {
          continue;
        }
        $map[$this->getSlug($term)] = $term;
      }
    }

    $tags = ['taxonomy_term_list:' . self::VOCABULARY];
    $this->cache->set($cid, $map, $this->time->getRequestTime() + 3600, $tags);
    return $map;
  }

}
