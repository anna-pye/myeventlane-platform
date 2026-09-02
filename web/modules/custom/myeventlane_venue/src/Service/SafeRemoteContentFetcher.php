<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\myeventlane_venue\Exception\UnsafeRemoteUrlException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;

/**
 * Downloads size-limited public content without following unchecked redirects.
 */
final class SafeRemoteContentFetcher {

  private const MAX_REDIRECTS = 3;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly PublicRemoteUrlGuard $urlGuard,
  ) {}

  /**
   * Fetches an HTML document.
   *
   * @return array{body: string, content_type: string, url: string}
   *   The bounded response body, content type and final URL.
   */
  public function fetchHtml(string $url): array {
    return $this->fetch($url, 524288, ['text/html', 'application/xhtml+xml']);
  }

  /**
   * Fetches an image body.
   *
   * @return array{body: string, content_type: string, url: string}
   *   The bounded response body, content type and final URL.
   */
  public function fetchImage(string $url): array {
    return $this->fetch($url, 5242880, [
      'image/jpeg',
      'image/png',
      'image/webp',
    ]);
  }

  /**
   * Fetches a remote resource after validating every redirect target.
   *
   * @param string $url
   *   The remote public HTTPS URL.
   * @param int $max_bytes
   *   The maximum response body size.
   * @param string[] $allowed_content_types
   *   Allow-listed response content types.
   *
   * @return array{body: string, content_type: string, url: string}
   *   The bounded response body, content type and final URL.
   */
  private function fetch(string $url, int $max_bytes, array $allowed_content_types): array {
    $current = $url;
    for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
      $safe = $this->urlGuard->validate($current);
      $response = $this->request($safe);
      $status = $response->getStatusCode();

      if ($status >= 300 && $status < 400) {
        if ($redirects === self::MAX_REDIRECTS) {
          throw new UnsafeRemoteUrlException('The website redirected too many times.');
        }
        $location = trim($response->getHeaderLine('Location'));
        if ($location === '') {
          throw new UnsafeRemoteUrlException('The website returned an invalid redirect.');
        }
        $current = (string) UriResolver::resolve(new Uri($safe['url']), new Uri($location));
        continue;
      }

      if ($status < 200 || $status >= 300) {
        throw new \RuntimeException(sprintf('The website returned HTTP %d.', $status));
      }

      $content_type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
      if (!in_array($content_type, $allowed_content_types, TRUE)) {
        throw new \RuntimeException('The website returned an unsupported content type.');
      }

      $length = (int) $response->getHeaderLine('Content-Length');
      if ($length > $max_bytes) {
        throw new \RuntimeException('The website content is too large to review safely.');
      }

      $body = '';
      $stream = $response->getBody();
      while (!$stream->eof()) {
        $body .= $stream->read(8192);
        if (strlen($body) > $max_bytes) {
          throw new \RuntimeException('The website content is too large to review safely.');
        }
      }

      return [
        'body' => $body,
        'content_type' => $content_type,
        'url' => $safe['url'],
      ];
    }

    throw new \RuntimeException('The website could not be fetched.');
  }

  /**
   * Makes one request pinned to the addresses that were just validated.
   *
   * @param array{url: string, host: string, ips: string[]} $safe
   *   Validated URL information.
   */
  private function request(array $safe): ResponseInterface {
    $resolve = array_map(
      static fn(string $ip): string => sprintf('%s:443:%s', $safe['host'], $ip),
      $safe['ips'],
    );

    return $this->httpClient->request('GET', $safe['url'], [
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
      'connect_timeout' => 3.0,
      'timeout' => 8.0,
      'stream' => TRUE,
      'headers' => [
        'Accept' => 'text/html,application/xhtml+xml,image/jpeg,image/png,image/webp;q=0.8',
        'User-Agent' => 'MyEventLane venue metadata preview/1.0',
      ],
      'curl' => [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_RESOLVE => $resolve,
      ],
    ]);
  }

}
