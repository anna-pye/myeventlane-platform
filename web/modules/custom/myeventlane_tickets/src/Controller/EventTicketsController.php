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
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\myeventlane_vendor\Form\EventTicketsWorkspaceForm;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for Tickets workspace pages.
 *
 * All methods render inside the vendor console with tickets sub-navigation.
 */
final class EventTicketsController extends VendorEventTicketsBaseController implements ContainerInjectionInterface {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected FormBuilderInterface $formBuilder;

  protected EntityFormBuilderInterface $entityFormBuilder;

  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly EventAccess $eventAccess,
    EntityTypeManagerInterface $entityTypeManager,
    FormBuilderInterface $formBuilder,
    EntityFormBuilderInterface $entityFormBuilder,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
    $this->entityTypeManager = $entityTypeManager;
    $this->formBuilder = $formBuilder;
    $this->entityFormBuilder = $entityFormBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('myeventlane_tickets.event_access'),
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('entity.form_builder'),
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
   * Overview: canonical ticket tier workspace (mel_ticket_type, no variations).
   */
  public function overview(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $tickets_form = $this->formBuilder->getForm(EventTicketsWorkspaceForm::class, $event);

    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-tickets-overview']],
    ];

    $content['tickets_workspace'] = $tickets_form;

    $content['more_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('More ticket tools'),
      '#attributes' => ['class' => ['mel-tickets-overview__more-title']],
    ];

    $content['links'] = [
      '#theme' => 'item_list',
      '#items' => [
        [
          '#type' => 'link',
          '#title' => $this->t('Ticket groups'),
          '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_groups', ['event' => $event->id()]),
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Access codes'),
          '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_access_codes', ['event' => $event->id()]),
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Ticket settings'),
          '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_settings', ['event' => $event->id()]),
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Embedded widgets'),
          '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_widgets', ['event' => $event->id()]),
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Door scanner'),
          '#url' => Url::fromRoute('myeventlane_tickets.ticket_scan', ['event' => $event->id()]),
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Check-in analytics'),
          '#url' => Url::fromRoute('myeventlane_tickets.ticket_checkin_analytics', ['event' => $event->id()]),
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Manual check-in'),
          '#url' => Url::fromRoute('myeventlane_tickets.ticket_checkin', ['event' => $event->id()]),
        ],
      ],
      '#attributes' => ['class' => ['mel-tickets-overview-links']],
    ];

    return $this->buildTicketsPage($event, $content, (string) $this->t('Tickets'), 'overview');
  }

  /**
   * Ticket types tab: same mel_ticket_type workspace as overview.
   */
  public function typesList(NodeInterface $event): array {
    $this->assertEventOwnership($event);
    $form = $this->formBuilder->getForm(EventTicketsWorkspaceForm::class, $event);
    return $this->buildTicketsPage($event, $form, (string) $this->t('Ticket types'), 'types');
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
    return $this->buildTicketsPage($event, $form, (string) $this->t('Edit ticket type'), 'types');
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

    return $this->buildTicketsPage($event, $form, (string) $this->t('Ticket settings'), 'settings');
  }

  /**
   * Add form for ticket group.
   */
  public function groupsAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_ticket_group');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->entityFormBuilder->getForm($entity, 'add');

    return $this->buildTicketsPage($event, $form, (string) $this->t('Add ticket group'), 'groups');
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

    return $this->buildTicketsPage($event, $form, (string) $this->t('Edit ticket group'), 'groups');
  }

  /**
   * Add form for access code.
   */
  public function accessCodesAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_access_code');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->entityFormBuilder->getForm($entity, 'add');

    return $this->buildTicketsPage($event, $form, (string) $this->t('Add access code'), 'access_codes');
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

    return $this->buildTicketsPage($event, $form, (string) $this->t('Edit access code'), 'access_codes');
  }

  /**
   * Add form for purchase surface widget.
   */
  public function widgetsAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_purchase_surface');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->entityFormBuilder->getForm($entity, 'add');

    return $this->buildTicketsPage($event, $form, (string) $this->t('Add widget'), 'widgets');
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

    return $this->buildTicketsPage($event, $form, (string) $this->t('Edit widget'), 'widgets');
  }

}
