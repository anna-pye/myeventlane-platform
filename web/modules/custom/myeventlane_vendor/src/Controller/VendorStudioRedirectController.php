<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Redirect-only aliases for legacy Vendor Studio URLs.
 *
 * All traffic terminates in Event Studio create/edit routes.
 */
final class VendorStudioRedirectController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * Constructs the redirect controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Legacy /vendor/studio entry — redirects to Event Studio create or edit.
   */
  public function studio(Request $request): RedirectResponse {
    $query_event_id = (int) $request->query->get('event', 0);
    if ($query_event_id > 0) {
      $event = $this->entityTypeManager->getStorage('node')->load($query_event_id);
      if ($event instanceof NodeInterface && $event->bundle() === 'event') {
        try {
          $this->assertEventOwnership($event);
          $url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()])->toString();
          return new RedirectResponse($url, 302);
        }
        catch (AccessDeniedHttpException) {
          // Fall through to create when the event is not accessible.
        }
      }
    }

    $url = Url::fromRoute('myeventlane_event_studio.create')->toString();
    return new RedirectResponse($url, 302);
  }

  /**
   * Legacy /vendor/events/{event}/editor — redirects to Event Studio edit.
   */
  public function eventEditor(NodeInterface $event): RedirectResponse {
    $this->assertEventOwnership($event);

    if ($event->bundle() !== 'event') {
      throw new AccessDeniedHttpException('Only event nodes can be edited in Event Studio.');
    }

    $url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => $event->id()])->toString();
    return new RedirectResponse($url, 302);
  }

}
