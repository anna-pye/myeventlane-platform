<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

/**
 * HTML link UTM tagger with safety: never rewrites unsubscribe, mailto, tel, or signed URLs.
 */
final class UtmLinker {

  /**
   * Appends UTM query params to <a href="..."> links.
   *
   * Safeguards (never rewritten):
   * - mailto: and tel:
   * - Unsubscribe links (path or query contains "unsubscribe")
   * - Signed URLs (query contains h=, signature=, token=, sig=, etc.)
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

        $urlLower = strtolower($url);
        if (str_contains($urlLower, 'unsubscribe')) {
          return $m[0];
        }

        if (preg_match('/[?&](h|signature|token|sig)=/i', $url)) {
          return $m[0];
        }

        $sep = (str_contains($url, '?') ? '&' : '?');
        return 'href="' . $url . $sep . $q . '"';
      },
      $html,
    ) ?? $html;
  }

}
