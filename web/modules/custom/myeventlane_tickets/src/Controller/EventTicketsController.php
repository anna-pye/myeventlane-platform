<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\myeventlane_vendor\Form\EventTicketManagerForm;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for Tickets workspace pages.
 *
 * All methods render inside the vendor event workspace with tickets sub-navigation.
 */
final class EventTicketsController extends VendorEventTicketsBaseController implements ContainerInjectionInterface {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected EntityFormBuilderInterface $entityFormBuilder;

  protected FormBuilderInterface $formBuilder;

  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    VendorEventTabsService $eventTabsService,
    private readonly EventAccess $eventAccess,
    EntityTypeManagerInterface $entityTypeManager,
    EntityFormBuilderInterface $entityFormBuilder,
    FormBuilderInterface $formBuilder,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger, $eventTabsService);
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFormBuilder = $entityFormBuilder;
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_vendor.service.event_tabs'),
      $container->get('myeventlane_tickets.event_access'),
      $container->get('entity_type.manager'),
      $container->get('entity.form_builder'),
      $container->get('form_builder'),
      $container->get('myeventlane_event.ticket_tier_lifecycle'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function assertEventOwnership(NodeInterface $event): void {
    if ($this->eventAccess->canManageEventTickets($event)) {
      return;
    }
    parent::assertEventOwnership($event);
  }

  /**
   * Tickets overview: Commerce-backed manager inside the workspace shell.
   *
   * Some environments still register this as the route controller; the canonical
   * route uses \Drupal\myeventlane_vendor\Form\EventTicketManagerForm directly.
   */
  public function overview(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    $form = $this->formBuilder->getForm(EventTicketManagerForm::class, $event);
    return $this->buildTicketsPage($event, $form, 'overview');
  }

  /**
   * Redirects legacy ticket type list URL to the canonical ticket manager.
   */
  public function typesRedirect(NodeInterface $event): RedirectResponse {
    $this->assertEventOwnership($event);
    return new RedirectResponse('/vendor/events/' . $event->id() . '/tickets');
  }

  /**
   * Entity edit form for a tier attached to this event (no Commerce variation UI).
   */
  public function editTicketType(NodeInterface $event, TicketTypeInterface $mel_ticket_type): array {
    $this->assertEventOwnership($event);
    if (!$this->ticketTierLifecycle->ticketBelongsToEvent($event, (int) $mel_ticket_type->id())) {
      throw new NotFoundHttpException();
    }
    if ((int) $mel_ticket_type->get('vendor_id')->target_id !== (int) $this->currentUser->id()
      && !$this->currentUser->hasPermission('administer mel_ticket_type entities')) {
      throw new AccessDeniedHttpException();
    }
    $form = $this->entityFormBuilder->getForm($mel_ticket_type, 'edit');
    return $this->buildTicketsPage($event, $form, 'types');
  }

  /**
   * Settings page for ticket configuration.
   */
  public function settings(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_event_ticket_settings');

    $existing = $storage->loadByProperties(['event' => $event->id()]);
    $settings = $existing ? reset($existing) : $storage->create([
      'event' => $event->id(),
      'status' => 1,
    ]);

    $form = $this->entityFormBuilder->getForm($settings, 'default');

    return $this->buildTicketsPage($event, $form, 'settings');
  }

  /**
   * Add form for ticket group.
   */
  public function groupsAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_ticket_group');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->entityFormBuilder->getForm($entity, 'add');

    return $this->buildTicketsPage($event, $form, 'groups');
  }

  /**
   * Edit form for ticket group.
   */
  public function groupsEdit(NodeInterface $event, $mel_ticket_group): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_ticket_group');
    $entity = $storage->load($mel_ticket_group);

    if (!$entity || (int) $entity->get('event')->target_id !== (int) $event->id()) {
      throw new NotFoundHttpException();
    }

    $form = $this->entityFormBuilder->getForm($entity, 'default');

    return $this->buildTicketsPage($event, $form, 'groups');
  }

  /**
   * Add form for access code.
   */
  public function accessCodesAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_access_code');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->entityFormBuilder->getForm($entity, 'add');

    return $this->buildTicketsPage($event, $form, 'access_codes');
  }

  /**
   * Edit form for access code.
   */
  public function accessCodesEdit(NodeInterface $event, $mel_access_code): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_access_code');
    $entity = $storage->load($mel_access_code);

    if (!$entity || (int) $entity->get('event')->target_id !== (int) $event->id()) {
      throw new NotFoundHttpException();
    }

    $form = $this->entityFormBuilder->getForm($entity, 'default');

    return $this->buildTicketsPage($event, $form, 'access_codes');
  }

  /**
   * Add form for purchase surface widget.
   */
  public function widgetsAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_purchase_surface');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->entityFormBuilder->getForm($entity, 'add');

    return $this->buildTicketsPage($event, $form, 'widgets');
  }

  /**
   * Edit form for purchase surface widget.
   */
  public function widgetsEdit(NodeInterface $event, $mel_purchase_surface): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_purchase_surface');
    $entity = $storage->load($mel_purchase_surface);

    if (!$entity || (int) $entity->get('event')->target_id !== (int) $event->id()) {
      throw new NotFoundHttpException();
    }

    $form = $this->entityFormBuilder->getForm($entity, 'default');

    return $this->buildTicketsPage($event, $form, 'widgets');
  }

}
