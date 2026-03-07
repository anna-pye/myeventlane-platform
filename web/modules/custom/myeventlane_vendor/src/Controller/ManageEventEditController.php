<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Service\ManageEventNavigation;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the legacy "Event information" entrypoint.
 *
 * This now redirects vendors to the canonical wizard edit route.
 */
final class ManageEventEditController extends ManageEventControllerBase {

  /**
   * Constructs ManageEventEditController.
   */
  public function __construct(
    ManageEventNavigation $navigation,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($navigation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_vendor.manage_event_navigation'),
      $container->get('logger.factory')->get('myeventlane_vendor'),
    );
  }

  /**
   * Renders the event information edit form.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Render array.
   */
  public function edit(NodeInterface $event): RedirectResponse {
    // TEMP DIAGNOSTIC: remove after vendor workflow consolidation validation.
    $this->logger->notice('TEMP diagnostics: vendor entrypoint route={route} event_id={event_id} form_id={form_id} canonical_wizard={canonical}', [
      'route' => 'myeventlane_vendor.manage_event.edit',
      'event_id' => (int) $event->id(),
      'form_id' => 'n/a',
      'canonical' => 1,
    ]);
    $url = Url::fromRoute('myeventlane_event.wizard.edit', ['node' => (int) $event->id()]);
    return new RedirectResponse($url->toString());
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageTitle(NodeInterface $event): string {
    return (string) $this->t('Event information');
  }

}
