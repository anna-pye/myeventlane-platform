<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;

/**
 * Builds a themed support panel render array from YAML config.
 */
final class SupportPanelBuilder {

  public function __construct(
    private readonly HelpContentRepository $helpContentRepository,
    private readonly PathValidatorInterface $pathValidator,
    private readonly HelpPanelAnalytics $helpPanelAnalytics,
    private readonly HelpPanelIntelligence $helpPanelIntelligence,
  ) {}

  /**
   * @return array<string, mixed>
   *   Render array, or empty if the key is missing or invalid.
   */
  public function build(string $key, ?string $surface = NULL, ?string $routeName = NULL): array {
    $panel = $this->helpContentRepository->getSupportPanel($key);
    return $panel === NULL ? [] : $this->buildPanel($key, $panel, $surface, $routeName);
  }

  /**
   * Builds the first matching panel for a given route.
   *
   * @return array<string, mixed>|null
   *   Render array when a match is found, NULL otherwise.
   */
  public function buildForRoute(string $routeName, ?string $surface = NULL): ?array {
    $panels = $this->helpContentRepository->getSupportPanels();
    if (!is_array($panels) || $panels === []) {
      return NULL;
    }

    $orderedKeys = $this->helpPanelIntelligence->getTopPanels($panels);
    if ($orderedKeys === []) {
      $orderedKeys = array_keys($panels);
    }

    foreach ($orderedKeys as $key) {
      $panel = $panels[$key] ?? NULL;
      if (!is_array($panel)) {
        continue;
      }
      $routeNames = $this->normalizeStringList($panel['route_names'] ?? []);
      if ($routeNames !== [] && !in_array($routeName, $routeNames, TRUE)) {
        continue;
      }
      $built = $this->buildPanel((string) $key, $panel, $surface, $routeName);
      if ($built !== []) {
        return $built;
      }
    }
    return NULL;
  }

  /**
   * @param array<int|string, string>|string $values
   *
   * @return array<int, string>
   */
  private function normalizeStringList(array|string $values): array {
    if (!is_array($values)) {
      $values = $values === '' ? [] : [$values];
    }
    $out = [];
    foreach ($values as $value) {
      $value = trim((string) $value);
      if ($value !== '') {
        $out[] = $value;
      }
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $panel
   *
   * @return array<string, mixed>
   */
  private function buildPanel(string $key, array $panel, ?string $surface, ?string $routeName): array {
    $surfaces = $this->normalizeStringList($panel['surfaces'] ?? []);
    if ($surface !== NULL && $surfaces !== [] && !in_array($surface, $surfaces, TRUE)) {
      return [];
    }
    $routeNames = $this->normalizeStringList($panel['route_names'] ?? []);
    if ($routeName !== NULL && $routeNames !== [] && !in_array($routeName, $routeNames, TRUE)) {
      return [];
    }
    $title = trim((string) ($panel['title'] ?? ''));
    $linkPath = trim((string) ($panel['link_path'] ?? ''));
    if ($title === '' || $linkPath === '' || !str_starts_with($linkPath, '/')) {
      return [];
    }

    $url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($linkPath);
    if (!$url instanceof Url || $url->isExternal()) {
      return [];
    }

    $cacheContexts = [];
    if ($routeNames !== []) {
      $cacheContexts[] = 'route';
    }

    $cache = [
      'tags' => ['config:myeventlane_help_centre.help_content'],
      'max-age' => 0,
    ];
    if ($cacheContexts !== []) {
      $cache['contexts'] = $cacheContexts;
    }

    $this->helpPanelAnalytics->logImpression($key);

    $classes = ['mel-support-panel'];
    if ($surface === 'checkout') {
      $classes[] = 'mel-support-panel--checkout';
    }
    elseif ($surface === 'vendor_dashboard') {
      $classes[] = 'mel-support-panel--vendor';
    }

    return [
      '#theme' => 'mel_support_panel',
      '#attributes' => [
        'class' => $classes,
      ],
      '#title' => $title,
      '#summary' => trim((string) ($panel['summary'] ?? '')),
      '#url' => $url->setAbsolute(FALSE)->toString(),
      '#analytics_key' => $key,
      '#cache' => $cache,
      '#attached' => [
        'library' => ['myeventlane_help_centre/support_panel_analytics'],
      ],
    ];
  }

}
