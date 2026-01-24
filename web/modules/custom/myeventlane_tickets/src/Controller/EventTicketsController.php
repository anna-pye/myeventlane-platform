<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_tickets\Service\EventAccess;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for Tickets workspace pages.
 *
 * All methods render inside the vendor console with tickets sub-navigation.
 */
final class EventTicketsController extends VendorEventTicketsBaseController implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The form builder.
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    private readonly EventAccess $eventAccess,
    EntityTypeManagerInterface $entityTypeManager,
    FormBuilderInterface $formBuilder,
  ) {
    parent::__construct($domainDetector, $currentUser);
    $this->entityTypeManager = $entityTypeManager;
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('myeventlane_tickets.event_access'),
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
    );
  }

  /**
   * Overview page for tickets workspace.
   */
  public function overview(NodeInterface $event): array {
    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-tickets-overview']],
    ];

    $content['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Manage ticket types, groups, access codes, settings, and embedded widgets for this event.') . '</p>',
    ];

    $content['links'] = [
      '#theme' => 'item_list',
      '#items' => [
        [
          '#type' => 'link',
          '#title' => $this->t('Ticket types'),
          '#url' => Url::fromRoute('myeventlane_tickets.event_tickets_types', ['event' => $event->id()]),
        ],
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
      ],
      '#attributes' => ['class' => ['mel-tickets-overview-links']],
    ];

    return $this->buildTicketsPage($event, $content, (string) $this->t('Tickets'), 'overview');
  }

  /**
   * Renders the add ticket modal form.
   */
  public function addTicketModal(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $form = $this->formBuilder->getForm('Drupal\myeventlane_tickets\Form\AddTicketModalForm', $event);

    // The modal title is set in the form's header markup.
    // Drupal's dialog will use the route title as fallback.
    return $form;
  }

  /**
   * Lists ticket types (Commerce products) for the event.
   */
  public function typesList(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('commerce_product');

    // Query ticket products filtered by event.
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'ticket')
      ->condition('field_event', $event->id())
      ->sort('created', 'DESC');

    $product_ids = $query->execute();
    $products = $product_ids ? $storage->loadMultiple($product_ids) : [];

    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-tickets-types-list']],
    ];

    // Add "Add" button with AJAX modal.
    $add_url = Url::fromRoute('myeventlane_tickets.add_ticket_modal', ['event' => $event->id()]);
    $content['add_button'] = [
      '#type' => 'link',
      '#title' => $this->t('+ Add ticket type'),
      '#url' => $add_url,
      '#attributes' => [
        'class' => ['use-ajax', 'mel-btn', 'mel-btn--primary'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => json_encode(['width' => 'medium', 'dialogClass' => 'mel-add-ticket-dialog']),
      ],
      '#weight' => -10,
    ];

    // Attach AJAX library.
    $content['#attached']['library'][] = 'core/drupal.dialog.ajax';

    if (empty($products)) {
      $content['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-empty-state']],
        'message' => [
          '#markup' => '<p>' . $this->t('No ticket types have been created for this event yet.') . '</p>',
        ],
      ];
    }
    else {
      // Build card grid.
      $content['grid'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-types-grid']],
      ];

      foreach ($products as $product) {
        /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
        $variations = $product->getVariations();
        $variation = !empty($variations) ? reset($variations) : NULL;

        $price = 'Free';
        $stock = 'Unlimited';

        if ($variation) {
          $price_obj = $variation->getPrice();
          if ($price_obj) {
            $price_number = (float) $price_obj->getNumber();
            if ($price_number > 0) {
              $price = '$' . number_format($price_number, 2);
            }
          }

          // Get stock if available.
          if ($variation->hasField('field_stock') && !$variation->get('field_stock')->isEmpty()) {
            $stock_value = (int) $variation->get('field_stock')->value;
            $stock = $this->t('@count available', ['@count' => $stock_value]);
          }
        }

        $card = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-type-card']],
        ];

        $card['name'] = [
          '#type' => 'markup',
          '#markup' => '<h3 class="mel-ticket-type-card__name">' . $this->t('@name', ['@name' => $product->label()]) . '</h3>',
        ];

        $card['price'] = [
          '#type' => 'markup',
          '#markup' => '<div class="mel-ticket-type-card__price">' . $price . '</div>',
        ];

        $card['stock'] = [
          '#type' => 'markup',
          '#markup' => '<div class="mel-ticket-type-card__stock">' . $stock . '</div>',
        ];

        $card['actions'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-type-card__actions']],
        ];

        $card['actions']['edit'] = [
          '#type' => 'link',
          '#title' => $this->t('Edit'),
          '#url' => $product->toUrl('edit-form'),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
        ];

        $card['actions']['duplicate'] = [
          '#type' => 'markup',
          '#markup' => '<span class="mel-btn mel-btn--secondary mel-btn--disabled">' . $this->t('Duplicate') . ' <small>(' . $this->t('Coming soon') . ')</small></span>',
        ];

        $content['grid'][] = $card;
      }
    }

    return $this->buildTicketsPage($event, $content, (string) $this->t('Ticket types'), 'types');
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

    $form = $this->formBuilder->getForm($settings, 'default');

    return $this->buildTicketsPage($event, $form, (string) $this->t('Ticket settings'), 'settings');
  }

  /**
   * Add form for ticket group.
   */
  public function groupsAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_ticket_group');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->formBuilder->getForm($entity, 'add');

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

    $form = $this->formBuilder->getForm($entity, 'default');

    return $this->buildTicketsPage($event, $form, (string) $this->t('Edit ticket group'), 'groups');
  }

  /**
   * Add form for access code.
   */
  public function accessCodesAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_access_code');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->formBuilder->getForm($entity, 'add');

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

    $form = $this->formBuilder->getForm($entity, 'default');

    return $this->buildTicketsPage($event, $form, (string) $this->t('Edit access code'), 'access_codes');
  }

  /**
   * Add form for purchase surface widget.
   */
  public function widgetsAdd(NodeInterface $event): array {
    $this->assertEventOwnership($event);

    $storage = $this->entityTypeManager->getStorage('mel_purchase_surface');
    $entity = $storage->create(['event' => $event->id()]);
    $form = $this->formBuilder->getForm($entity, 'add');

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

    $form = $this->formBuilder->getForm($entity, 'default');

    return $this->buildTicketsPage($event, $form, (string) $this->t('Edit widget'), 'widgets');
  }

}
