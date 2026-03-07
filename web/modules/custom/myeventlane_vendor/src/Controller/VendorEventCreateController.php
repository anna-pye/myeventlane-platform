<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for vendor event creation: redirects to canonical wizard.
 *
 * This legacy route (/vendor/events/add) is retained for backwards
 * compatibility and redirects to the canonical wizard create route.
 */
final class VendorEventCreateController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
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
      $container->get('logger.factory')->get('myeventlane_vendor'),
    );
  }

  /**
   * Redirects to the canonical wizard create route.
   */
  public function buildForm(): RedirectResponse {
    $this->assertVendorAccess();
    $this->assertStripeConnected();

    // TEMP DIAGNOSTIC: remove after vendor workflow consolidation validation.
    $this->logger->notice('TEMP diagnostics: vendor entrypoint route={route} event_id={event_id} form_id={form_id} canonical_wizard={canonical}', [
      'route' => 'myeventlane_vendor.console.events_add',
      'event_id' => 0,
      'form_id' => 'n/a',
      'canonical' => 1,
    ]);

    $url = Url::fromRoute('myeventlane_event.wizard.create');
    return new RedirectResponse($url->toString());
  }

}
