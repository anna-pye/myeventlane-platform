<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Service\ManageEventNavigation;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the legacy "Event information" entrypoint.
 *
 * Redirects vendors to Event Studio edit.
 */
final class ManageEventEditController extends ManageEventControllerBase {

  /**
   * Constructs ManageEventEditController.
   */
  public function __construct(ManageEventNavigation $navigation) {
    parent::__construct($navigation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_vendor.manage_event_navigation'),
    );
  }

  /**
   * Redirects to Event Studio for the event node.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response.
   */
  public function edit(NodeInterface $event): RedirectResponse {
    $url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => (int) $event->id()]);
    return new RedirectResponse($url->toString());
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageTitle(NodeInterface $event): string {
    return (string) $this->t('Event information');
  }

}
