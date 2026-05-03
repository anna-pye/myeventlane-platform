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
 * Controller for vendor event creation: forwards to Event Studio create.
 *
 * Legacy route (/vendor/events/add) is retained; canonical entry matches marketing CTAs
 * (draft creation + redirect to edit is handled in Event Studio).
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
      $container->get('logger.channel.myeventlane_vendor'),
    );
  }

  /**
   * Redirects to Event Studio create (Stripe, terms, draft resume, new draft + edit).
   */
  public function buildForm(): RedirectResponse {
    $this->assertVendorAccess();

    $this->logger->notice('Vendor event create entry: from_route=myeventlane_vendor.console.events_add target_route=myeventlane_event_studio.create studio_selected=1 uid=@uid', [
      '@uid' => (string) $this->currentUser->id(),
    ]);

    $url = Url::fromRoute('myeventlane_event_studio.create');
    return new RedirectResponse($url->toString());
  }

}
