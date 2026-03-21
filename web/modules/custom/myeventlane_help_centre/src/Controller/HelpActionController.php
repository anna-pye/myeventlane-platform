<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mel_ticket\Entity\TicketType;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Whitelisted POST actions for guided help (CSRF + event ownership enforced).
 */
final class HelpActionController extends ControllerBase {

  private const CSRF_ID = 'mel_help_action';

  /**
   * @var list<string>
   */
  private const ALLOWED_ACTIONS = [
    'enable_confirmations',
    'add_default_ticket',
  ];

  public function __construct(
    private readonly CsrfTokenGenerator $csrfToken,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    private readonly CurrentVendorResolverInterface $currentVendorResolver,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
    private readonly LoggerChannelInterface $logger,
  ) {
    // ControllerBase already declares $entityTypeManager, $configFactory, $currentUser;
    // do not re-promote them as readonly (PHP fatal: incompatible redeclaration).
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('csrf_token'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_event.ticket_tier_lifecycle'),
      $container->get('logger.channel.myeventlane_help_centre'),
    );
  }

  /**
   * Runs a single whitelisted action for the current vendor context.
   */
  public function run(string $action, Request $request): JsonResponse {
    $token = (string) ($request->headers->get('X-CSRF-Token') ?? '');
    if ($token === '' || !$this->csrfToken->validate($token, self::CSRF_ID)) {
      $this->logger->warning('Help action blocked: invalid CSRF token (uid=@uid).', [
        '@uid' => (string) $this->currentUser->id(),
      ]);
      throw new AccessDeniedHttpException();
    }

    if (!in_array($action, self::ALLOWED_ACTIONS, TRUE)) {
      $this->logger->notice('Help action unknown: @action', ['@action' => $action]);
      return new JsonResponse(['success' => FALSE], 404);
    }

    $decoded = [];
    $raw = $request->getContent();
    if ($raw !== '') {
      $decoded = Json::decode($raw);
      if (!is_array($decoded)) {
        $this->logger->warning('Help action: invalid JSON body (uid=@uid).', [
          '@uid' => (string) $this->currentUser->id(),
        ]);
        return new JsonResponse(['success' => FALSE], 400);
      }
    }

    $eventId = (int) ($decoded['event'] ?? 0);
    if ($eventId < 1) {
      $this->logger->notice('Help action @action missing event id (uid=@uid).', [
        '@action' => $action,
        '@uid' => (string) $this->currentUser->id(),
      ]);
      return new JsonResponse(['success' => FALSE], 400);
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $this->logger->warning('Help action @action: event @nid not found.', [
        '@action' => $action,
        '@nid' => (string) $eventId,
      ]);
      return new JsonResponse(['success' => FALSE], 404);
    }

    $this->assertUserOwnsEvent($event, $action);

    return match ($action) {
      'enable_confirmations' => $this->doEnableConfirmations(),
      'add_default_ticket' => $this->doAddDefaultTicket($event),
      default => new JsonResponse(['success' => FALSE], 404),
    };
  }

  private function doEnableConfirmations(): JsonResponse {
    if (!$this->currentUser->hasPermission('administer myeventlane rsvp')) {
      $this->logger->notice('enable_confirmations denied: uid=@uid lacks administer myeventlane rsvp.', [
        '@uid' => (string) $this->currentUser->id(),
      ]);
      return new JsonResponse(['success' => FALSE]);
    }

    if (!$this->moduleHandler()->moduleExists('myeventlane_rsvp')) {
      $this->logger->error('enable_confirmations failed: myeventlane_rsvp is not enabled.');
      return new JsonResponse(['success' => FALSE]);
    }

    $editable = $this->configFactory->getEditable('myeventlane_rsvp.settings');
    $editable->set('send_confirmation', TRUE);
    $editable->save();

    $this->logger->info('RSVP send_confirmation enabled via help action by uid=@uid.', [
      '@uid' => (string) $this->currentUser->id(),
    ]);

    return new JsonResponse(['success' => TRUE]);
  }

  private function doAddDefaultTicket(NodeInterface $event): JsonResponse {
    if (!$this->entityTypeManager->hasDefinition('mel_ticket_type')) {
      $this->logger->error('add_default_ticket: mel_ticket_type entity type missing.');
      return new JsonResponse(['success' => FALSE]);
    }

    if (!$this->currentUser->hasPermission('create mel_ticket_type entities')) {
      $this->logger->notice('add_default_ticket denied: uid=@uid cannot create ticket types.', [
        '@uid' => (string) $this->currentUser->id(),
      ]);
      return new JsonResponse(['success' => FALSE]);
    }
    if (!$this->currentUser->hasPermission('assign mel_ticket_type entities')) {
      $this->logger->notice('add_default_ticket denied: uid=@uid cannot assign ticket types.', [
        '@uid' => (string) $this->currentUser->id(),
      ]);
      return new JsonResponse(['success' => FALSE]);
    }

    $eventType = $this->eventTypeValue($event);
    if (!in_array($eventType, ['rsvp', 'both'], TRUE)) {
      $this->logger->notice('add_default_ticket skipped for event @nid: type=@type.', [
        '@nid' => (string) $event->id(),
        '@type' => $eventType !== '' ? $eventType : 'empty',
      ]);
      return new JsonResponse(['success' => FALSE]);
    }

    $title = (string) $this->t('General admission');
    if ($event->hasField('field_ticket_types') && !$event->get('field_ticket_types')->isEmpty()) {
      foreach ($event->get('field_ticket_types')->referencedEntities() as $existing) {
        if ($existing instanceof TicketType && $existing->getTitle() === $title) {
          $this->logger->info('add_default_ticket: event @nid already has default ticket.', [
            '@nid' => (string) $event->id(),
          ]);
          return new JsonResponse(['success' => TRUE]);
        }
      }
    }

    try {
      $ticket = $this->ticketTierLifecycle->createAttachAndSync($event, [
        'title' => $title,
        'ticket_kind' => 'rsvp',
        'capacity' => 100,
        'vendor_id' => ['target_id' => $this->currentUser->id()],
        'event' => ['target_id' => $event->id()],
        'status' => 1,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('add_default_ticket failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(['success' => FALSE]);
    }

    $this->logger->info('Default RSVP ticket @tid added to event @nid via help action.', [
      '@tid' => (string) $ticket->id(),
      '@nid' => (string) $event->id(),
    ]);

    return new JsonResponse(['success' => TRUE]);
  }

  private function eventTypeValue(NodeInterface $event): string {
    if (!$event->hasField('field_event_type') || $event->get('field_event_type')->isEmpty()) {
      return '';
    }
    return mb_strtolower(trim((string) $event->get('field_event_type')->value));
  }

  /**
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  private function assertUserOwnsEvent(NodeInterface $event, string $action): void {
    $account = $this->currentUser;
    if ($account->hasPermission('administer nodes') || $account->hasPermission('bypass node access')) {
      return;
    }
    if ((int) $event->getOwnerId() === (int) $account->id()) {
      return;
    }
    $vendor = $this->currentVendorResolver->resolveFromCurrentUser();
    if ($vendor instanceof Vendor
      && $event->hasField('field_event_vendor')
      && !$event->get('field_event_vendor')->isEmpty()
      && (int) $event->get('field_event_vendor')->target_id === (int) $vendor->id()) {
      return;
    }
    $this->logger->warning('Help action @action denied: uid=@uid cannot modify event @nid.', [
      '@action' => $action,
      '@uid' => (string) $account->id(),
      '@nid' => (string) $event->id(),
    ]);
    throw new AccessDeniedHttpException();
  }

}
