<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber to add security headers for staging environments.
 *
 * Adds X-Robots-Tag header to prevent search engine indexing and caching
 * when the site is detected as a staging environment.
 */
final class StagingSecuritySubscriber implements EventSubscriberInterface {

  /**
   * Constructs StagingSecuritySubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onResponse', -10],
    ];
  }

  /**
   * Adds X-Robots-Tag header to responses in staging environments.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    // Only process main requests, not sub-requests.
    if (!$event->isMainRequest()) {
      return;
    }

    // Check if staging environment is detected.
    $is_staging = $this->configFactory->get('system.site')->get('staging_environment') ?? FALSE;

    // Also check environment variable as fallback.
    if (!$is_staging) {
      $env_staging = getenv('STAGING_ENVIRONMENT');
      $is_staging = ($env_staging === '1' || $env_staging === 'true');
    }

    // Auto-detect by hostname if not explicitly set.
    if (!$is_staging && isset($_SERVER['HTTP_HOST'])) {
      $host = $_SERVER['HTTP_HOST'];
      $staging_patterns = [
        '/staging\./i',
        '/stage\./i',
        '/test\./i',
        '/dev\./i',
        '/\.staging\./i',
      ];
      foreach ($staging_patterns as $pattern) {
        if (preg_match($pattern, $host)) {
          $is_staging = TRUE;
          break;
        }
      }
    }

    if ($is_staging) {
      $response = $event->getResponse();

      // Add X-Robots-Tag header to prevent indexing and caching.
      // noindex: Don't index this page
      // nofollow: Don't follow links on this page
      // noarchive: Don't cache/archive this page
      // nosnippet: Don't show snippets in search results.
      $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

      // Also add Cache-Control to prevent caching by proxies/CDNs.
      $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');
    }
  }

}
