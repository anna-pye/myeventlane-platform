<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard success screen controller.
 *
 * Displays the post-publish success page.
 */
final class VendorEventWizardController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
    );
  }

  /**
   * Post-publish success screen.
   */
  public function success(NodeInterface $event): array|RedirectResponse {
    $this->assertEventOwnership($event);

    if (!$event->isPublished()) {
      $url = Url::fromRoute('myeventlane_event.wizard.review', ['event' => $event->id()]);
      return new RedirectResponse($url->toString());
    }

    $event_url = Url::fromRoute('entity.node.canonical', ['node' => $event->id()]);
    $tickets_url = Url::fromRoute('myeventlane_vendor.console.event_tickets', ['event' => $event->id()]);
    $create_url = Url::fromRoute('myeventlane_event.wizard.create');

    return $this->buildVendorPage('myeventlane_event_wizard_success', [
      'title' => $this->t('Event published successfully'),
      'event' => $event,
      'event_url' => $event_url->toString(),
      'tickets_url' => $tickets_url->toString(),
      'create_url' => $create_url->toString(),
    ]);
  }

}
