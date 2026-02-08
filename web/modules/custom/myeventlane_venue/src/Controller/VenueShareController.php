<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_venue\Service\VenueManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for share-by-link flow.
 */
class VenueShareController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected VenueManager $venueManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_venue.manager'),
    );
  }

  /**
   * Accepts a share link and grants access.
   *
   * @param string $token
   *   The share token.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to venues list.
   */
  public function accept(string $token): RedirectResponse {
    $uid = (int) $this->currentUser()->id();

    $venue = $this->venueManager->acceptShareLink($token, $uid);

    if ($venue) {
      $this->messenger()->addStatus($this->t('You now have access to the venue "@name".', [
        '@name' => $venue->getName(),
      ]));
    }
    else {
      $this->messenger()->addError($this->t('This share link is invalid or has expired.'));
    }

    return new RedirectResponse($this->getRedirectUrl());
  }

  /**
   * Gets the redirect URL with fallback.
   *
   * @return string
   *   The redirect URL.
   */
  protected function getRedirectUrl(): string {
    try {
      return Url::fromRoute('myeventlane_venue.vendor_venues')->toString();
    }
    catch (\Exception $e) {
      return Url::fromRoute('entity.myeventlane_venue.collection')->toString();
    }
  }

}
