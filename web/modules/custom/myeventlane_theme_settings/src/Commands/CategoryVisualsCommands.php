<?php

declare(strict_types=1);

namespace Drupal\myeventlane_theme_settings\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for MyEventLane category visuals (pill colour, slug).
 */
final class CategoryVisualsCommands extends DrushCommands {

  /**
   * Maps slug (from homepage/SCSS) to field_category_pill_color token.
   *
   * Based on _mel-category-pills.scss: arts→mint, community→blue, family→pink,
   * food→yellow, lgbtqia→purple, markets→green, movie/film→pink, music→blue,
   * workshop→coral, sport→coral. Unmapped → leave empty (Twig uses default).
   */
  private const SLUG_TO_TOKEN = [
    'arts' => 'mint',
    'lgbtqia' => 'purple',
    'lgbtq' => 'purple',
    'lgbtqi' => 'purple',
    'community' => 'blue',
    'family' => 'pink',
    'food' => 'yellow',
    'food-drink' => 'yellow',
    'food-and-drink' => 'yellow',
    'food_drink' => 'yellow',
    'foodanddrink' => 'yellow',
    'food-drink-' => 'yellow',
    'markets' => 'green',
    'movie' => 'pink',
    'film' => 'pink',
    'movies' => 'pink',
    'music' => 'blue',
    'workshop' => 'coral',
    'sport' => 'coral',
    'sports' => 'coral',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Sets pill colour and slug on all categories to match the homepage/SCSS.
   *
   * - field_category_pill_color: from slug→token map.
   * - field_mel_slug: when empty, set to sanitized term name.
   */
  #[CLI\Command(name: 'mel:category-visuals-sync', aliases: ['mel-cat-visuals'])]
  #[CLI\Usage(name: 'drush mel:category-visuals-sync', description: 'Set pill colour and slug on categories.')]
  public function sync(): void {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['vid' => 'categories']);
    if (empty($terms)) {
      $this->logger()->notice('No categories terms found.');
      return;
    }

    $updated = 0;
    foreach ($terms as $term) {
      if (!$term instanceof TermInterface || $term->bundle() !== 'categories') {
        continue;
      }

      $slug = $this->getSlug($term);
      $changed = FALSE;

      // Set field_mel_slug when empty (so slug is explicit).
      if ($term->hasField('field_mel_slug')) {
        $cur = $term->get('field_mel_slug')->value;
        $cur = is_string($cur) ? trim($cur) : '';
        if ($cur === '' && $slug !== 'default') {
          $term->set('field_mel_slug', $slug);
          $changed = TRUE;
        }
      }

      // Set field_category_pill_color from slug→token map.
      if ($term->hasField('field_category_pill_color')) {
        $token = self::SLUG_TO_TOKEN[$slug] ?? NULL;
        $cur = $term->get('field_category_pill_color')->value;
        $cur = is_string($cur) ? $cur : '';
        $new = $token ?? '';
        if ($new !== $cur) {
          $term->set('field_category_pill_color', $new ?: NULL);
          $changed = TRUE;
        }
      }

      if ($changed) {
        $term->save();
        $token = self::SLUG_TO_TOKEN[$slug] ?? '-';
        $this->logger()->notice('Updated %name (tid %tid): slug=%slug pill_color=%token', [
          '%name' => $term->label(),
          '%tid' => $term->id(),
          '%slug' => $slug,
          '%token' => $token,
        ]);
        $updated++;
      }
    }

    $this->logger()->success("Category visuals sync: {$updated} term(s) updated.");
  }

  /**
   * Slug for a category term (mirrors theme's _myeventlane_theme_get_category_slug).
   */
  private function getSlug(TermInterface $term): string {
    if ($term->hasField('field_mel_slug') && !$term->get('field_mel_slug')->isEmpty()) {
      $v = $term->get('field_mel_slug')->value;
      if (is_string($v) && trim($v) !== '') {
        $slug = trim(strtolower($v));
        if (in_array($slug, ['lgbtqi', 'lgbtq', 'lgbtqi+', 'lgbtqia+', 'lgbtq+'], TRUE)) {
          return 'lgbtqia';
        }
        return $slug;
      }
    }
    $name = $term->label();
    $slug = str_replace('&', 'and', strtolower((string) $name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if (in_array($slug, ['lgbtqi', 'lgbtq', 'lgbtqi+', 'lgbtqia+', 'lgbtq+'], TRUE)) {
      return 'lgbtqia';
    }
    return $slug !== '' ? $slug : 'default';
  }

}
