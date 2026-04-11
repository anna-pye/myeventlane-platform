<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Determines best help panels and article recommendations.
 */
final class HelpRecommendationEngine {

  private const MAX_ARTICLES = 3;

  public function __construct(
    private readonly HelpAnalyticsSummary $analyticsSummary,
    private readonly HelpContentRepository $repository,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Get recommendations for a given route + surface.
   *
   * @return array{panel_key: string, articles: array<int, string>}
   *   Article values are help_article field_help_seed_key values (max 3).
   */
  public function getRecommendations(string $routeName, string $surface): array {
    $panels = $this->repository->getSupportPanels();
    $panelKeys = $this->panelConfigKeys($panels);
    $analytics = $this->analyticsSummary->getPanelSummary($panelKeys);

    $scored = [];

    foreach ($panels as $key => $panel) {
      if (!is_array($panel)) {
        continue;
      }

      $routeNames = $panel['route_names'] ?? [];
      $surfaces = $panel['surfaces'] ?? [];

      if (!empty($routeNames) && !in_array($routeName, (array) $routeNames, TRUE)) {
        continue;
      }

      if (!empty($surfaces) && !in_array($surface, (array) $surfaces, TRUE)) {
        continue;
      }

      $metrics = $analytics[$key] ?? [
        'ctr' => 0,
        'helpful_ratio' => 0,
      ];

      $score = ((float) ($metrics['ctr'] ?? 0) * 0.6) + ((float) ($metrics['helpful_ratio'] ?? 0) * 0.4);

      $scored[$key] = [
        'score' => $score,
        'panel' => $panel,
      ];
    }

    if ($scored === []) {
      return [];
    }

    uasort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

    $bestKey = (string) array_key_first($scored);
    $bestPanel = $scored[$bestKey]['panel'];

    return [
      'panel_key' => $bestKey,
      'articles' => $this->resolveArticleSeedKeys($bestKey, $bestPanel, $surface, $analytics, $panels),
    ];
  }

  /**
   * Resolves article seed keys for a known panel (e.g. after buildForRoute fallback).
   *
   * @return array<int, string>
   */
  public function getArticleSeedKeysForPanel(string $panelKey, string $surface): array {
    $panels = $this->repository->getSupportPanels();
    $panel = $panels[$panelKey] ?? NULL;
    if (!is_array($panel)) {
      return [];
    }
    $analytics = $this->analyticsSummary->getPanelSummary($this->panelConfigKeys($panels));
    return $this->resolveArticleSeedKeys($panelKey, $panel, $surface, $analytics, $panels);
  }

  /**
   * @param array<string, array<string, mixed>|mixed> $panels
   * @param array<string, array<string, float|int>> $analytics
   *
   * @return array<int, string>
   */
  private function resolveArticleSeedKeys(
    string $panelKey,
    array $panel,
    string $surface,
    array $analytics,
    array $panels,
  ): array {
    $seeds = $this->repository->getHelpArticleSeeds();
    $audienceFilter = $this->audienceLabelsForSurface($surface);
    $configOrdered = $this->filterSeedsByAudience($seeds, $audienceFilter);

    $primary = trim((string) ($panel['article_seed_key'] ?? ''));
    if ($primary === '' && $configOrdered !== []) {
      $primary = $configOrdered[0];
    }

    if ($primary === '' && $seeds !== []) {
      foreach ($seeds as $row) {
        if (!is_array($row)) {
          continue;
        }
        $sk = trim((string) ($row['seed_key'] ?? ''));
        if ($sk !== '') {
          $primary = $sk;
          break;
        }
      }
    }

    if ($primary === '') {
      $this->logger->warning('No article seed could be resolved for support panel @panel on surface @surface.', [
        '@panel' => $panelKey,
        '@surface' => $surface,
      ]);
      return [];
    }

    $pool = [$primary];
    foreach ($configOrdered as $sk) {
      if (!in_array($sk, $pool, TRUE)) {
        $pool[] = $sk;
      }
    }

    $configIndex = [];
    foreach (array_values($pool) as $i => $sk) {
      $configIndex[$sk] = $i;
    }

    $rest = array_values(array_filter($pool, static fn (string $sk): bool => $sk !== $primary));

    usort($rest, function (string $a, string $b) use ($analytics, $panels, $configIndex): int {
      $ctrA = $this->ctrForArticleSeed($a, $analytics, $panels);
      $ctrB = $this->ctrForArticleSeed($b, $analytics, $panels);
      if ($ctrA !== $ctrB) {
        return $ctrB <=> $ctrA;
      }
      return ($configIndex[$a] ?? 9999) <=> ($configIndex[$b] ?? 9999);
    });

    $merged = array_merge([$primary], $rest);
    $merged = array_values(array_unique($merged, SORT_STRING));

    return array_slice($merged, 0, self::MAX_ARTICLES);
  }

  /**
   * @param array<string, array<string, mixed>|mixed> $panels
   *
   * @return array<int, string>
   */
  private function panelConfigKeys(array $panels): array {
    $keys = [];
    foreach ($panels as $key => $panel) {
      if (is_array($panel)) {
        $keys[] = (string) $key;
      }
    }
    return $keys;
  }

  /**
   * CTR associated with a seed: max CTR among panels whose article_seed_key matches.
   *
   * @param array<string, array<string, float|int>> $analytics
   * @param array<string, array<string, mixed>|mixed> $panels
   */
  private function ctrForArticleSeed(string $seed, array $analytics, array $panels): float {
    $max = 0.0;
    foreach ($panels as $pk => $p) {
      if (!is_array($p)) {
        continue;
      }
      if (($p['article_seed_key'] ?? '') !== $seed) {
        continue;
      }
      $ctr = (float) ($analytics[$pk]['ctr'] ?? 0.0);
      $max = max($max, $ctr);
    }
    return $max;
  }

  /**
   * Ordered seed keys from help_articles YAML that match the surface audience.
   *
   * @param array<string, array<string, mixed>> $seeds
   * @param array<int, string> $audienceFilter
   *
   * @return array<int, string>
   */
  private function filterSeedsByAudience(array $seeds, array $audienceFilter): array {
    if ($audienceFilter === []) {
      return [];
    }
    $out = [];
    foreach ($seeds as $row) {
      if (!is_array($row)) {
        continue;
      }
      $audiences = $row['audience'] ?? [];
      $audiences = is_array($audiences) ? $audiences : [$audiences];
      if (array_intersect($audienceFilter, $audiences) === []) {
        continue;
      }
      $sk = trim((string) ($row['seed_key'] ?? ''));
      if ($sk !== '') {
        $out[] = $sk;
      }
    }
    return $out;
  }

  /**
   * @return array<int, string>
   */
  private function audienceLabelsForSurface(string $surface): array {
    return match ($surface) {
      'checkout' => ['Attendees'],
      'vendor_dashboard', 'vendor' => ['Vendors', 'General'],
      'event_creation_wizard' => ['Organisers'],
      default => [],
    };
  }

}
