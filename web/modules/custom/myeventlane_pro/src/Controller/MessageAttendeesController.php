<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccessInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Redirects legacy Pro Message attendees into the Messages compose path.
 *
 * VX2-06: one Messages product. The Pro stub form is retired as a product.
 */
final class MessageAttendeesController extends VendorConsoleBaseController {

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly MelAttendeeOperationsAccessInterface $attendeeOperationsAccess,
    ?EventVendorAccessCheckerInterface $eventVendorAccessChecker = NULL,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger, $eventVendorAccessChecker);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_checkout_flow.attendee_operations_access'),
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  /**
   * Redirects /vendor/events/{node}/message to Messages compose.
   */
  public function message(NodeInterface $node): RedirectResponse {
    $this->assertVendorAccess();
    $this->assertAccess($node);

    try {
      $url = Url::fromRoute('myeventlane_vendor.console.event_promotion', [
        'event' => $node->id(),
      ])->toString();
    }
    catch (\Throwable) {
      $url = Url::fromRoute('myeventlane_event_studio.workspace_messaging', [
        'node' => $node->id(),
      ])->toString();
    }

    return new RedirectResponse($url, 302);
  }

  /**
   * Asserts the current user can message attendees of this event.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  private function assertAccess(NodeInterface $node): void {
    if ($node->bundle() !== 'event') {
      throw new AccessDeniedHttpException();
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      throw new AccessDeniedHttpException();
    }

    if (!$this->attendeeOperationsAccess->accountHasOrganiserOwnership($node, $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }
  }

}
