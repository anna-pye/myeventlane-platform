<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Extracts a reviewable description and image from a venue website.
 */
final class VenueWebsiteMetadataFetcher {

  public function __construct(
    private readonly SafeRemoteContentFetcher $contentFetcher,
    private readonly PublicRemoteUrlGuard $urlGuard,
  ) {}

  /**
   * Fetches safe, reviewable metadata from a venue website.
   *
   * @return array{source_url: string, title: string, description: string, image_url: string}
   *   The source URL plus extracted plain-text and image metadata.
   */
  public function fetch(string $url): array {
    $document = $this->contentFetcher->fetchHtml($url);
    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $loaded = $dom->loadHTML($document['body'], LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
    if (!$loaded) {
      throw new \RuntimeException('The website did not return readable page metadata.');
    }

    $metadata = [];
    foreach ($dom->getElementsByTagName('meta') as $meta) {
      $key = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
      $value = trim($meta->getAttribute('content'));
      if ($key !== '' && $value !== '' && !isset($metadata[$key])) {
        $metadata[$key] = $value;
      }
    }

    $title = $this->plainText($metadata['og:title'] ?? '');
    if ($title === '') {
      $nodes = $dom->getElementsByTagName('title');
      $title = $nodes->length > 0 ? $this->plainText((string) $nodes->item(0)?->textContent) : '';
    }
    $description = $this->plainText($metadata['og:description'] ?? $metadata['description'] ?? '');
    $image = trim((string) ($metadata['og:image:secure_url'] ?? $metadata['og:image'] ?? $metadata['twitter:image'] ?? ''));
    if ($image !== '') {
      $image = (string) UriResolver::resolve(new Uri($document['url']), new Uri($image));
      $image = $this->urlGuard->validate($image)['url'];
    }

    if ($description === '' && $image === '') {
      throw new \RuntimeException('No reusable description or image metadata was found on this website.');
    }

    return [
      'source_url' => $document['url'],
      'title' => mb_substr($title, 0, 160),
      'description' => mb_substr($description, 0, 600),
      'image_url' => $image,
    ];
  }

  /**
   * Normalises metadata to plain, editable text.
   */
  private function plainText(string $value): string {
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $value));
  }

}
