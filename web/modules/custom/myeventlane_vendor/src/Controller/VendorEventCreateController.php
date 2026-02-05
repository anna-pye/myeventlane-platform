<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor event creation: redirects to the step wizard.
 *
 * Canonical event creation is the phased wizard at /vendor/events/create
 * (Basics → When & Where → Tickets → Details → Review → Publish). This route
 * (/vendor/events/add) exists for backwards compatibility and "Create Event"
 * links; redirecting here ensures the correct wizard always renders.
 */
final class VendorEventCreateController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
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
   * Redirects to the event creation step wizard.
   */
  public function buildForm(): RedirectResponse {
    $this->assertVendorAccess();
    $this->assertStripeConnected();

    $url = Url::fromRoute('myeventlane_event.wizard.create');
    return new RedirectResponse($url->toString());
  }

}
