<?php

declare(strict_types=1);

namespace Drupal\myeventlane_growth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Records a growth CTA click then redirects to an internal destination.
 */
final class GrowthCtaController extends ControllerBase {

  public function __construct(
    private readonly GrowthTrackingService $tracking,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_growth.tracking'),
    );
  }

  /**
   * Tracks click and redirects.
   */
  public function redirectCta(Request $request): TrustedRedirectResponse {
    $key = (string) $request->query->get('key', '');
    $surface = (string) $request->query->get('surface', 'dashboard');
    $destination = (string) $request->query->get('destination', '');
    $eidRaw = $request->query->get('eid', '');
    $eventId = ($eidRaw !== '' && is_numeric($eidRaw)) ? (int) $eidRaw : NULL;
    if ($eventId !== NULL && $eventId <= 0) {
      $eventId = NULL;
    }

    if ($key === '' || $destination === '') {
      throw new BadRequestHttpException('Missing growth tracking parameters.');
    }

    $uid = (int) $this->currentUser()->id();
    $safe = $this->filterInternalDestination($destination, $request);
    if ($safe === NULL) {
      throw new BadRequestHttpException('Invalid destination.');
    }

    $this->tracking->recordClick($key, $uid, $eventId, $surface);
    return new TrustedRedirectResponse($safe, 302);
  }

  /**
   * Allows only same-host relative URLs or absolute URLs on this site.
   */
  private function filterInternalDestination(string $destination, Request $request): ?string {
    $destination = trim($destination);
    if ($destination === '') {
      return NULL;
    }
    $parts = parse_url($destination);
    if ($parts === FALSE) {
      return NULL;
    }
    if (!empty($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], TRUE)) {
      return NULL;
    }
    if (!empty($parts['host'])) {
      $currentHost = $request->getHost();
      if (strcasecmp((string) $parts['host'], $currentHost) !== 0) {
        return NULL;
      }
    }
    $path = $parts['path'] ?? '';
    if ($path === '' || !str_starts_with($path, '/')) {
      return NULL;
    }
    if (str_starts_with($path, '//')) {
      return NULL;
    }
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    return $path . $query . $fragment;
  }

}
