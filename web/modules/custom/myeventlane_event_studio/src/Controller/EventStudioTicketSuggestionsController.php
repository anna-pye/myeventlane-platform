<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioTicketLinkSuggestionService;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * POST JSON: suggested entity_autocomplete strings for ticket product + types.
 */
final class EventStudioTicketSuggestionsController implements ContainerInjectionInterface {

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event_studio.ticket_link_suggestion'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.event_access_checker'),
    );
  }

  public function __construct(
    private readonly EventStudioTicketLinkSuggestionService $suggestionService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
  ) {}

  public function suggest(Request $request): JsonResponse {
    if (!$request->isMethod('POST')) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Method not allowed'], 405);
    }

    $raw = $request->getContent();
    $data = [];
    if ($raw !== '') {
      try {
        $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
        $data = is_array($decoded) ? $decoded : [];
      }
      catch (\JsonException) {
        return new JsonResponse(['ok' => FALSE, 'error' => 'Invalid JSON'], 400);
      }
    }

    $eventTitle = trim((string) ($data['event_title'] ?? ''));
    $tierTitles = $data['tier_titles'] ?? [];
    if (!is_array($tierTitles)) {
      $tierTitles = [];
    }

    $nid = isset($data['nid']) ? (int) $data['nid'] : 0;
    if ($nid < 1) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Event context is required.'], 400);
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->load($nid);
    if (!$loaded instanceof NodeInterface || $loaded->bundle() !== 'event') {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Not found'], 404);
    }
    if (!$this->currentUser->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($loaded, $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }

    $strings = $this->suggestionService->suggest(
      $this->currentUser,
      $eventTitle,
      $tierTitles,
      $nid,
    );

    return new JsonResponse([
      'ok' => TRUE,
      'product' => $strings['product'],
      'ticket_types' => $strings['ticket_types'],
    ]);
  }

}
