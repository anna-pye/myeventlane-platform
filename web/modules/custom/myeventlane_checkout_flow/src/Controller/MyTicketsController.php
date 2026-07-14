<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_checkout_flow\Service\MyTicketsOrderViewModelBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for "My Tickets" self-service experience.
 */
final class MyTicketsController extends ControllerBase {

  /**
   * Constructs MyTicketsController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly MyTicketsOrderViewModelBuilder $orderViewModelBuilder,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_checkout_flow.my_tickets_order_view_model_builder')
    );
  }

  /**
   * Access callback for My Tickets page.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function checkAccess() {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return AccessResult::forbidden()->addCacheContexts(['user']);
    }
    return AccessResult::allowed()->addCacheContexts(['user']);
  }

  /**
   * Title callback for order detail page.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Page title (translatable).
   */
  public function orderDetailTitle(OrderInterface $commerce_order) {
    return $this->t('Booking @order_number', ['@order_number' => $commerce_order->getOrderNumber()]);
  }

  /**
   * Renders the "My Tickets" overview page.
   *
   * @return array
   *   A render array for the My Tickets page.
   */
  public function overview(): array {
    $currentUser = $this->currentUser();

    // Anonymous users are redirected to login.
    if ($currentUser->isAnonymous()) {
      return [
        '#markup' => $this->t('Please <a href="@login">log in</a> to view your tickets.', [
          '@login' => Url::fromRoute('user.login')->toString(),
        ]),
        '#cache' => [
          'contexts' => ['user'],
        ],
      ];
    }

    // Load orders: owned by uid, or guest checkout (uid 0) with matching email.
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $orderStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('state', MyTicketsOrderViewModelBuilder::COMPLETED_ORDER_STATES, 'IN');

    $or = $query->orConditionGroup();
    $or->condition('uid', $currentUser->id());
    $userEmail = trim((string) $currentUser->getEmail());
    if ($userEmail !== '') {
      $guestGroup = $query->andConditionGroup();
      $guestGroup->condition('uid', 0);
      $guestGroup->condition('mail', $userEmail);
      $or->condition($guestGroup);
    }
    $query->condition($or);

    $orderIds = $query
      ->sort('placed', 'DESC')
      ->sort('order_id', 'DESC')
      ->execute();

    $orders = !empty($orderIds) ? $orderStorage->loadMultiple($orderIds) : [];

    // Group orders by upcoming vs past events.
    $upcomingOrders = [];
    $pastOrders = [];

    foreach ($this->orderViewModelBuilder->buildMultiple($orders) as $orderData) {
      if ($orderData['has_upcoming_events']) {
        $upcomingOrders[] = $orderData;
      }
      else {
        $pastOrders[] = $orderData;
      }
    }

    return [
      '#theme' => 'myeventlane_my_tickets',
      '#title' => $this->t('My Bookings'),
      '#upcoming_orders' => $upcomingOrders,
      '#past_orders' => $pastOrders,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['commerce_order_list'],
      ],
    ];
  }

  /**
   * Renders a customer-facing order detail page.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order entity.
   *
   * @return array
   *   A render array for the order detail page.
   */
  public function orderDetail(OrderInterface $commerce_order): array {
    $orderData = $this->orderViewModelBuilder->build($commerce_order, TRUE);

    $cacheTags = ['commerce_order:' . $commerce_order->id()];
    foreach ($orderData['events'] ?? [] as $eventData) {
      $nid = (int) ($eventData['id'] ?? 0);
      if ($nid) {
        $cacheTags[] = 'node:' . $nid;
      }
    }

    return [
      '#theme' => 'myeventlane_order_detail',
      '#title' => $this->t('Booking @order_number', ['@order_number' => $commerce_order->getOrderNumber()]),
      '#order' => $orderData,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => $cacheTags,
      ],
    ];
  }

}
