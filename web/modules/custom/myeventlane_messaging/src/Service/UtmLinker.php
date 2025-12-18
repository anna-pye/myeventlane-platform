<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

/**
 * Naive HTML link UTM tagger.
 */
final class UtmLinker {

  /**
   * Appends UTM query params to <a href="..."> links.
   *
   * @param string $html
   *   The HTML content.
   * @param array $params
   *   The UTM query parameters.
   *
   * @return string
   *   The updated HTML.
   */
  public function apply(string $html, array $params): string {
    if (!$params) {
      return $html;
    }

    $q = http_build_query($params);

    return preg_replace_callback(
      '#href="([^"]+)"#i',
      function (array $m) use ($q): string {
        $url = $m[1];

        if (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
          return $m[0];
        }

        $sep = (str_contains($url, '?') ? '&' : '?');
        return 'href="' . $url . $sep . $q . '"';
      },
      $html,
    ) ?? $html;
  }

}
