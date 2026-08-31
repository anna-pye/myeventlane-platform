<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_tickets\Entity\AccessCode;
use Drupal\myeventlane_tickets\Entity\PurchaseSurface;
use Drupal\myeventlane_tickets\Entity\TicketGroup;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\myeventlane_tickets\Service\PurchaseSurfaceEmbedCodeBuilder;
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
 * All methods render inside the vendor event workspace with ticket navigation.
 */
final class EventTicketsController extends VendorEventTicketsBaseController implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity form builder.
   */
  protected EntityFormBuilderInterface $entityFormBuilder;

  /**
   * The form builder.
   */
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
    private readonly PurchaseSurfaceEmbedCodeBuilder $embedCodeBuilder,
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
      $container->get('myeventlane_tickets.purchase_surface_embed_code_builder'),
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
   * Some environments still register this as the route controller. The
   * canonical route uses EventTicketManagerForm directly.
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
   * Entity edit form for a tier attached to this event.
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
    $page = $this->buildToolFormPage($event, $form, [
      'eyebrow' => $this->t('Ticket types'),
      'title' => $this->t('Edit @ticket', ['@ticket' => $mel_ticket_type->label()]),
      'description' => $this->t('Update this ticket without leaving the selected event workspace.'),
      'help_title' => $this->t('Event-specific ticket'),
      'help_text' => $this->t('Changes apply only to this event. Existing orders and attendee records remain attached to the saved ticket.'),
      'back_route' => 'myeventlane_vendor.console.event_tickets',
      'back_label' => $this->t('Back to tickets'),
    ]);
    return $this->buildTicketsPage($event, $page, 'types');
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

    $page = $this->buildToolFormPage($event, $form, [
      'eyebrow' => $this->t('Ticket settings'),
      'title' => $this->t('Customer and order settings'),
      'description' => $this->t('Choose what customers see and set optional ticket limits for this event.'),
      'help_title' => $this->t('Use only what you need'),
      'help_text' => $this->t('Leave minimum and maximum order limits blank when this event does not need them. You can also turn each display option on or off.'),
      'back_route' => 'myeventlane_vendor.console.event_tickets',
      'back_label' => $this->t('Back to Ticketing'),
    ]);

    return $this->buildTicketsPage($event, $page, 'settings');
  }

  /**
   * Add form for ticket group.
   */
  public function groupsAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_ticket_group');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->entityFormBuilder->getForm($entity, 'add');

    $page = $this->buildToolFormPage($event, $form, [
      'eyebrow' => $this->t('Ticket groups'),
      'title' => $this->t('Add a section or bundle'),
      'description' => $this->t('Organise ticket choices under a heading or sell a fixed combination for one total price.'),
      'help_title' => $this->t('Section or bundle?'),
      'help_text' => $this->t('A section groups existing choices. A bundle sells set quantities together. Ticket capacity and checkout remain event-specific.'),
      'back_route' => 'myeventlane_tickets.event_tickets_groups',
      'back_label' => $this->t('Back to groups'),
    ]);

    return $this->buildTicketsPage($event, $page, 'groups');
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

    $page = $this->buildToolFormPage($event, $form, [
      'eyebrow' => $this->t('Ticket groups'),
      'title' => $this->t('Edit @group', ['@group' => $entity->label()]),
      'description' => $this->t('Update how these event tickets are grouped or sold together.'),
      'help_title' => $this->t('Existing sales stay with the event'),
      'help_text' => $this->t('This changes the offer shown to future customers. It does not move existing orders, tickets or attendee records.'),
      'back_route' => 'myeventlane_tickets.event_tickets_groups',
      'back_label' => $this->t('Back to groups'),
    ]);

    return $this->buildTicketsPage($event, $page, 'groups');
  }

  /**
   * Delete confirmation for a ticket group in this event.
   */
  public function groupsDelete(NodeInterface $event, TicketGroup $mel_ticket_group): array {
    $this->assertEventOwnership($event);
    if ($mel_ticket_group->getEventId() !== (int) $event->id()) {
      throw new NotFoundHttpException();
    }

    $form = $this->entityFormBuilder->getForm($mel_ticket_group, 'delete');
    $page = $this->buildToolFormPage($event, $form, [
      'eyebrow' => $this->t('Ticket groups'),
      'title' => $this->t('Delete @group?', ['@group' => $mel_ticket_group->label()]),
      'description' => $this->t('Review the confirmation carefully before removing this group from the event.'),
      'help_title' => $this->t('This cannot be undone'),
      'help_text' => $this->t('Deleting a group removes its organiser setup. Existing orders and issued tickets remain part of the event.'),
      'back_route' => 'myeventlane_tickets.event_tickets_groups',
      'back_label' => $this->t('Cancel and return to groups'),
    ]);

    return $this->buildTicketsPage($event, $page, 'groups');
  }

  /**
   * Add form for access code.
   */
  public function accessCodesAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_access_code');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->entityFormBuilder->getForm($entity, 'add');

    $page = $this->buildToolFormPage($event, $form, [
      'eyebrow' => $this->t('Access codes'),
      'title' => $this->t('Create an access code'),
      'description' => $this->t('Give selected guests a private code for ticket choices attached to this event.'),
      'help_title' => $this->t('Share it privately'),
      'help_text' => $this->t('The full code is shown once after creation. Copy it then and send it only to the guests who should use it.'),
      'back_route' => 'myeventlane_tickets.event_tickets_access_codes',
      'back_label' => $this->t('Back to access codes'),
    ]);

    return $this->buildTicketsPage($event, $page, 'access_codes');
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

    $page = $this->buildToolFormPage($event, $form, [
      'eyebrow' => $this->t('Access codes'),
      'title' => $this->t('Edit access code'),
      'description' => $this->t('Change its ticket access, usage limit, expiry or status for this event.'),
      'help_title' => $this->t('The saved code stays private'),
      'help_text' => $this->t('Leave the code field blank to keep the current code. Enter a new one only when you intend to replace it.'),
      'back_route' => 'myeventlane_tickets.event_tickets_access_codes',
      'back_label' => $this->t('Back to access codes'),
    ]);

    return $this->buildTicketsPage($event, $page, 'access_codes');
  }

  /**
   * Delete confirmation for an access code in this event.
   */
  public function accessCodesDelete(NodeInterface $event, AccessCode $mel_access_code): array {
    $this->assertEventOwnership($event);
    if ($mel_access_code->getEventId() !== (int) $event->id()) {
      throw new NotFoundHttpException();
    }

    $form = $this->entityFormBuilder->getForm($mel_access_code, 'delete');
    $page = $this->buildToolFormPage($event, $form, [
      'eyebrow' => $this->t('Access codes'),
      'title' => $this->t('Delete this access code?'),
      'description' => $this->t('Guests will no longer be able to use this code for the event.'),
      'help_title' => $this->t('Check before deleting'),
      'help_text' => $this->t('If the code may be needed again, set it to inactive instead. Deletion cannot be undone.'),
      'back_route' => 'myeventlane_tickets.event_tickets_access_codes',
      'back_label' => $this->t('Cancel and return to access codes'),
    ]);

    return $this->buildTicketsPage($event, $page, 'access_codes');
  }

  /**
   * Add form for purchase surface widget.
   */
  public function widgetsAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_purchase_surface');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->entityFormBuilder->getForm($entity, 'add');

    return $this->buildTicketsPage($event, $this->buildWidgetFormPage($event, $form), 'widgets');
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

    return $this->buildTicketsPage($event, $this->buildWidgetFormPage($event, $form, $entity), 'widgets');
  }

  /**
   * Delete confirmation for a ticket widget in this event.
   */
  public function widgetsDelete(NodeInterface $event, PurchaseSurface $mel_purchase_surface): array {
    $this->assertEventOwnership($event);
    if ($mel_purchase_surface->getEventId() !== (int) $event->id()) {
      throw new NotFoundHttpException();
    }

    $form = $this->entityFormBuilder->getForm($mel_purchase_surface, 'delete');
    $page = $this->buildToolFormPage($event, $form, [
      'eyebrow' => $this->t('Ticket widgets'),
      'title' => $this->t('Delete @widget?', ['@widget' => $mel_purchase_surface->label()]),
      'description' => $this->t('This widget will stop loading wherever its embed code is used.'),
      'help_title' => $this->t('Your tickets are not deleted'),
      'help_text' => $this->t('Only the promotional widget is removed. Event tickets, orders, capacity and checkout remain unchanged.'),
      'back_route' => 'myeventlane_tickets.event_tickets_widgets',
      'back_label' => $this->t('Cancel and return to widgets'),
    ]);

    return $this->buildTicketsPage($event, $page, 'widgets');
  }

  /**
   * Wraps the entity form in the selected-event widget experience.
   */
  private function buildWidgetFormPage(NodeInterface $event, array $form, ?PurchaseSurface $widget = NULL): array {
    return [
      '#theme' => 'mel_event_ticket_widget_form',
      '#event' => $event,
      '#form' => $form,
      '#is_edit' => $widget instanceof PurchaseSurface,
      '#widget_label' => $widget instanceof PurchaseSurface ? $widget->getLabel() : '',
      '#embed_code' => $widget instanceof PurchaseSurface ? $this->embedCodeBuilder->build($widget) : '',
      '#list_url' => Url::fromRoute('myeventlane_tickets.event_tickets_widgets', ['event' => $event->id()])->toString(),
      '#ticketing_url' => Url::fromRoute('myeventlane_event_studio.workspace_tickets', ['node' => $event->id()])->toString(),
      '#attached' => [
        'library' => ['myeventlane_tickets/purchase_surface_admin'],
      ],
    ];
  }

  /**
   * Wraps an event-scoped entity form in the shared ticket-tools layout.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The selected workspace event.
   * @param array<string, mixed> $form
   *   The entity form render array.
   * @param array<string, mixed> $copy
   *   Page copy and back-route definition.
   *
   * @return array<string, mixed>
   *   The shared Ticketing tool form render array.
   */
  private function buildToolFormPage(NodeInterface $event, array $form, array $copy): array {
    return [
      '#theme' => 'mel_event_ticket_tool_form',
      '#eyebrow' => $copy['eyebrow'],
      '#title' => $copy['title'],
      '#description' => $copy['description'],
      '#form' => $form,
      '#help_title' => $copy['help_title'],
      '#help_text' => $copy['help_text'],
      '#back_url' => Url::fromRoute($copy['back_route'], ['event' => $event->id()])->toString(),
      '#back_label' => $copy['back_label'],
      '#attached' => [
        'library' => ['myeventlane_tickets/ticket_tools_admin'],
      ],
    ];
  }

}
