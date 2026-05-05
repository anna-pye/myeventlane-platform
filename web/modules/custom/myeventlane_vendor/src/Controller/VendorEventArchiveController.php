<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorEventRemovalService;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * POST handler for safe archive/delete from vendor console event cards.
 */
final class VendorEventArchiveController extends VendorConsoleBaseController {

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly VendorEventRemovalService $vendorEventRemovalService,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  public function handle(NodeInterface $event, Request $request): RedirectResponse {
    $this->assertEventOwnership($event);

    if (!$request->isMethod(Request::METHOD_POST)) {
      throw new MethodNotAllowedHttpException(['POST']);
    }

    $tokenId = 'vendor_event_remove_' . (int) $event->id();
    $submitted = (string) $request->request->get('token', '');
    if (!$this->csrfToken->validate($submitted, $tokenId)) {
      $this->messenger->addError($this->t('Security validation failed. Please reload the page and try again.'));
      return $this->redirectToEvents();
    }

    $result = $this->vendorEventRemovalService->executeRemovalFromRequest($event, $this->currentUser);
    $action = (string) ($result['action'] ?? 'error');
    $message = (string) ($result['message'] ?? '');

    if ($action === 'error' || $message === '') {
      $this->messenger->addError($message !== '' ? $message : $this->t('The event could not be updated.'));
    }
    elseif ($action === 'noop') {
      $this->messenger->addWarning($message);
    }
    else {
      $this->messenger->addStatus($message);
    }

    return $this->redirectToEvents();
  }

  private function redirectToEvents(): RedirectResponse {
    try {
      $url = Url::fromRoute('myeventlane_vendor.console.events')->toString();
    }
    catch (\Throwable) {
      $url = '/vendor/events';
    }
    return new RedirectResponse($url);
  }

}
