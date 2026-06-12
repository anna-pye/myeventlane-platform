<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;

/**
 * Builds grouped Organiser Hub landing sections from config and taxonomy.
 */
final class OrganiserHubLandingBuilder {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * @return list<array{
   *   id: string,
   *   title: string,
   *   categories: list<array{title: string, description: string, url: string}>
   * }>
   */
  public function buildSections(): array {
    $config = $this->configFactory->get('myeventlane_front.organiser_hub');
    $rawSections = $config->get('sections') ?? [];
    if (!is_array($rawSections) || $rawSections === []) {
      return [];
    }

    $weighted = [];
    foreach ($rawSections as $sectionId => $section) {
      if (!is_string($sectionId) || !is_array($section)) {
        continue;
      }
      $title = trim((string) ($section['title'] ?? ''));
      if ($title === '') {
        continue;
      }
      $termConfigIds = $section['terms'] ?? [];
      if (!is_array($termConfigIds) || $termConfigIds === []) {
        continue;
      }

      $categories = [];
      foreach ($termConfigIds as $termSuffix) {
        $termSuffix = trim((string) $termSuffix);
        if ($termSuffix === '') {
          continue;
        }
        $term = $this->loadTermBySuffix($termSuffix);
        if (!$term instanceof TermInterface) {
          continue;
        }

        $categories[] = [
          'title' => $term->label(),
          'description' => trim(strip_tags((string) $term->getDescription())),
          'url' => Url::fromRoute('view.mel_blog.page_blog', [], [
            'query' => ['category' => (string) $term->id()],
          ])->toString(),
        ];
      }

      if ($categories === []) {
        continue;
      }

      $weighted[] = [
        'id' => $sectionId,
        'title' => $title,
        'weight' => (int) ($section['weight'] ?? 0),
        'categories' => $categories,
      ];
    }

    usort($weighted, static fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);

    return array_map(static function (array $section): array {
      unset($section['weight']);
      return $section;
    }, $weighted);
  }

  /**
   * Loads a taxonomy term using the exported config suffix.
   */
  private function loadTermBySuffix(string $suffix): ?TermInterface {
    $config = $this->configFactory->get('taxonomy.term.organiser_hub_categories.' . $suffix);
    if (!$config->isNew()) {
      $name = trim((string) $config->get('name'));
      if ($name !== '') {
        $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
          'vid' => 'organiser_hub_categories',
          'name' => $name,
        ]);
        if (is_array($terms) && $terms !== []) {
          $term = reset($terms);
          if ($term instanceof TermInterface) {
            return $term;
          }
        }
      }
    }

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $termStorage->loadByProperties(['vid' => 'organiser_hub_categories']);
    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      if ($this->slugify((string) $term->label()) === $suffix) {
        return $term;
      }
    }

    return NULL;
  }

  /**
   * Normalises a label to a config-safe slug.
   */
  private function slugify(string $label): string {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? '');
    return trim($slug, '_');
  }

}
