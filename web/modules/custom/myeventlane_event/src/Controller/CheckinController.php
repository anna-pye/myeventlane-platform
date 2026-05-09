<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Door Mode check-in: QR validation and name search with JSON endpoints.
 *
 * JSON handling is delegated to {@see \Drupal\myeventlane_checkout_flow\Service\MelDoorCheckinValidateService}
 * when the checkout flow module is enabled, so vendor and public door routes
 * converge on {@see \Drupal\myeventlane_checkout_flow\Service\MelAttendeeCheckinManager}.
 */
final class CheckinController extends ControllerBase {

  private const CSRF_ID = 'mel_door_checkin';

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly EventVendorAccessCheckerInterface $eventVendorAccessChecker,
    private readonly mixed $doorCheckinValidate,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('logger.factory')->get('myeventlane_event'),
      $container->get('csrf_token'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->has('myeventlane_checkout_flow.door_checkin_validate')
        ? $container->get('myeventlane_checkout_flow.door_checkin_validate')
        : NULL,
    );
  }

  /**
   * Access callback for door check-in routes (workspace parity + staff).
   *
   * Aligned with {@see \Drupal\myeventlane_checkout_flow\Service\MelAttendeeOperationsAccess}
   * check-in gates via {@see EventVendorAccessCheckerInterface}.
   */
  public function checkAccess(?NodeInterface $event = NULL): AccessResult {
    $account = $this->currentUser();

    if ($account->hasPermission('administer event attendees')
      || $account->hasPermission('administer commerce_order')
      || $account->hasPermission('bypass node access')) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.permissions']);
    }

    if ($event instanceof NodeInterface) {
      if ($this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
        return AccessResult::allowed()
          ->cachePerUser()
          ->addCacheableDependency($event);
      }
      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheableDependency($event);
    }

    return AccessResult::forbidden()->addCacheContexts(['user']);
  }

  /**
   * Door Mode page (mobile-friendly, no full attendee list).
   */
  public function doorMode(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $validateUrl = $this->buildValidateUrl($eventId);
    $searchUrl = $this->buildSearchUrl($eventId);

    return [
      '#theme' => 'mel_checkin_page',
      '#event' => $event,
      '#event_id' => $eventId,
      '#validate_url' => $validateUrl,
      '#search_url' => $searchUrl,
      '#csrf_token' => $this->csrfToken->get(self::CSRF_ID),
      '#attached' => [
        'library' => ['myeventlane_event/door_checkin'],
        'drupalSettings' => [
          'melDoorCheckin' => [
            'validateUrl' => $validateUrl,
            'searchUrl' => $searchUrl,
            'csrfToken' => $this->csrfToken->get(self::CSRF_ID),
            'eventId' => $eventId,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $eventId],
      ],
    ];
  }

  /**
   * Validates QR token or performs name search / check-in (JSON).
   */
  public function validate(NodeInterface $event, Request $request): JsonResponse {
    if (!is_object($this->doorCheckinValidate) || !method_exists($this->doorCheckinValidate, 'handleRequest')) {
      $this->logger->error('Door check-in: MelDoorCheckinValidateService unavailable; refusing legacy duplicate mutation path.');
      return new JsonResponse(['status' => 'error', 'message' => 'Check-in service unavailable.'], 503);
    }

    return $this->doorCheckinValidate->handleRequest(
      $event,
      $request,
      $this->currentUser()->getAccount(),
      $this->csrfToken,
      self::CSRF_ID,
    );
  }

  private function buildValidateUrl(int $eventId): string {
    return Url::fromRoute(
      'myeventlane_event.checkin_validate',
      ['event' => $eventId],
      ['absolute' => TRUE],
    )->toString();
  }

  private function buildSearchUrl(int $eventId): string {
    return Url::fromRoute(
      'myeventlane_event.checkin_validate',
      ['event' => $eventId],
      ['absolute' => TRUE],
    )->toString();
  }

}
