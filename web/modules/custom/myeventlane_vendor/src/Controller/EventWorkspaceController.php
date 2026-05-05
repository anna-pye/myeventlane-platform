<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Service\VendorEventWorkspaceViewModelBuilder;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides shell-level event workspace entry routes.
 */
final class EventWorkspaceController extends VendorConsoleBaseController {

  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
    private readonly RequestStack $requestStack,
    private readonly VendorEventWorkspaceViewModelBuilder $eventWorkspaceViewModelBuilder,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * Event workspace root: overview shell + TASK 8 view model.
   */
  public function workspace(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $model = $this->eventWorkspaceViewModelBuilder->build($event, $this->currentUser);

    return $this->buildVendorPage('mel_event_workspace', [
      'event' => $event,
      'tabs' => [],
      'vendor_event_workspace_model' => $model,
      'insights' => [],
      'header_stats' => NULL,
      'meta' => NULL,
      'actions' => [],
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['mel-event-workspace-v2__content-anchor']],
        '#value' => '',
      ],
    ]);
  }

  /**
   * Publish workspace route: delegate to event publish workflow.
   */
  public function publish(NodeInterface $event): RedirectResponse {
    $this->assertEventOwnership($event);

    try {
      $target = Url::fromRoute('myeventlane_event_studio.edit', [
        'node' => (int) $event->id(),
      ])->toString();
    }
    catch (\Throwable $exception) {
      $request = $this->requestStack->getCurrentRequest();
      $this->logger->error('Publish route delegation failed for event {event}: {message}', [
        'event' => (int) $event->id(),
        'message' => $exception->getMessage(),
      ]);

      if ($request !== NULL) {
        throw new NotFoundHttpException('Publish workflow route is not available.', $exception);
      }

      $target = Url::fromRoute('myeventlane_vendor.console.event_workspace', [
        'event' => (int) $event->id(),
      ])->toString();
    }

    return new RedirectResponse($target, 302);
  }

}
