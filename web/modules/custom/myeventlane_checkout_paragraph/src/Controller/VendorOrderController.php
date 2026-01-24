<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_paragraph\Controller;

use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\TicketLabelResolver;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Vendor controller to show attendee details for an order.
 */
final class VendorOrderController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * VendorOrderController constructor.
   */
  public function __construct(
    private readonly MessengerInterface $messengerService,
    private readonly TicketLabelResolver $ticketLabelResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('messenger'),
      $container->get('myeventlane_core.ticket_label_resolver'),
    );
  }

  /**
   * Access check for attendee view.
   */
  public function access(Order $commerce_order, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    foreach ($commerce_order->getItems() as $item) {
      $variation = $item->getPurchasedEntity();
      if ($variation && $variation->hasField('field_event') && !$variation->get('field_event')->isEmpty()) {
        $event = $variation->get('field_event')->entity;
        if ($event && (int) $event->getOwnerId() === (int) $account->id()) {
          return AccessResult::allowed()->cachePerUser()->addCacheableDependency($event);
        }
      }
    }

    return AccessResult::forbidden('No access to attendee list.')->cachePerUser();
  }

  /**
   * Show attendee details for a single order.
   */
  public function attendees(Order $commerce_order): array|RedirectResponse {
    $access = $this->access($commerce_order, $this->currentUser());
    if ($access->isForbidden()) {
      $this->messengerService->addError($this->t('You do not have access to view these attendees.'));
      return $this->redirect('<front>');
    }

    $rows = $this->collectRows($commerce_order);
    $grouped = $this->groupRowsByEvent($rows);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vendor-attendees']],
      'title' => [
        '#type' => 'markup',
        '#markup' => '<h2>' . $this->t('Attendee Details for Order @order', ['@order' => $commerce_order->getOrderNumber()]) . '</h2>',
      ],
    ];

    foreach ($grouped as $key => $items) {
      [$event_title, $event_id] = explode('|', $key, 2);
      $build[] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $event_title,
        'export_link' => $event_id ? [
          '#type' => 'link',
          '#title' => $this->t('Export CSV for this event'),
          '#url' => Url::fromRoute('myeventlane_checkout_paragraph.export_csv', ['event' => $event_id]),
          '#attributes' => ['class' => ['button', 'button--small']],
        ] : [],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Ticket'),
            $this->t('First name'),
            $this->t('Last name'),
            $this->t('Email'),
            $this->t('Extras'),
          ],
          '#rows' => array_map(static function (array $row): array {
            return [$row['item_label'], $row['first_name'], $row['last_name'], $row['email'], $row['extras']];
          }, $items),
        ],
      ];
    }

    return $build;
  }

  /**
   * Collects row data per ticket holder.
   */
  private function collectRows(Order $commerce_order): array {
    $rows = [];
    foreach ($commerce_order->getItems() as $item) {
      $variation = $item->getPurchasedEntity();
      $event_title = $this->t('Unlinked Event');
      $event_id = NULL;
      if ($variation && $variation->hasField('field_event') && !$variation->get('field_event')->isEmpty()) {
        $event = $variation->get('field_event')->entity;
        if ($event) {
          $event_title = $event->label();
          $event_id = $event->id();
        }
      }

      if ($item->hasField('field_ticket_holder')) {
        foreach ($item->get('field_ticket_holder')->referencedEntities() as $holder) {
          if (!$holder instanceof ParagraphInterface) {
            continue;
          }
          $item_label = $this->ticketLabelResolver->getTicketLabel($item);
          $rows[] = [
            'event_title' => $event_title,
            'event_id' => $event_id,
            'item_label' => $item_label,
            'first_name' => $holder->hasField('field_first_name') ? $holder->get('field_first_name')->value : '',
            'last_name' => $holder->hasField('field_last_name') ? $holder->get('field_last_name')->value : '',
            'email' => $holder->hasField('field_email') ? $holder->get('field_email')->value : '',
            'extras' => $this->collectExtraLabels($holder),
          ];
        }
      }
    }
    return $rows;
  }

  /**
   * Groups rows by event.
   */
  private function groupRowsByEvent(array $rows): array {
    $grouped = [];
    foreach ($rows as $row) {
      $key = $row['event_title'] . '|' . ($row['event_id'] ?? '0');
      $grouped[$key][] = $row;
    }
    return $grouped;
  }

  /**
   * Collects extra question labels for display.
   */
  private function collectExtraLabels(ParagraphInterface $holder): string {
    $extras = [];
    if ($holder->hasField('field_attendee_questions') && !$holder->get('field_attendee_questions')->isEmpty()) {
      foreach ($holder->get('field_attendee_questions')->referencedEntities() as $question) {
        if ($question instanceof ParagraphInterface) {
          $extras[] = $question->get('field_question_label')->value ?? 'Question';
        }
      }
    }
    return implode(', ', $extras);
  }

}
