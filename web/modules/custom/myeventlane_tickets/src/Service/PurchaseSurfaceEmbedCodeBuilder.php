<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\myeventlane_tickets\Entity\PurchaseSurface;

/**
 * Builds the copy-and-paste script tag for an event ticket widget.
 */
final class PurchaseSurfaceEmbedCodeBuilder {

  public function __construct(
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * Returns the external-site embed code for a saved widget.
   */
  public function build(PurchaseSurface $surface): string {
    $token = $surface->getEmbedToken();
    if ($token === '') {
      return '';
    }

    $url = $this->urlGenerator->generateFromRoute(
      'myeventlane_tickets.purchase_surface_script',
      ['token' => $token],
      ['absolute' => TRUE],
    );

    return sprintf('<script async src="%s"></script>', htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
  }

}
