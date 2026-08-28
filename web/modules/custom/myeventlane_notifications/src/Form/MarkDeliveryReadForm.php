<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_notifications\NotificationFilter;
use Drupal\myeventlane_notifications\Service\NotificationUserInboxService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Marks a single inbox row as read (POST + redirect).
 */
final class MarkDeliveryReadForm extends FormBase {

  public function __construct(
    private readonly NotificationUserInboxService $userInbox,
  ) {
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_notifications.user_inbox'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_notifications_mark_delivery_read';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $delivery_id = 0, string $tab = NotificationFilter::TAB_ALL, string $filter = NotificationFilter::FILTER_ALL, string $return_route = '', int $event_id = 0): array {
    if (!in_array($tab, NotificationFilter::allowedTabs(), TRUE)) {
      $tab = NotificationFilter::TAB_ALL;
    }
    if (!in_array($filter, NotificationFilter::allowedFilters(), TRUE)) {
      $filter = NotificationFilter::FILTER_ALL;
    }
    $form['delivery_id'] = [
      '#type' => 'hidden',
      '#value' => $delivery_id,
    ];
    $form['tab'] = [
      '#type' => 'hidden',
      '#value' => $tab,
    ];
    $form['filter'] = [
      '#type' => 'hidden',
      '#value' => $filter,
    ];
    $form['return_route'] = ['#type' => 'hidden', '#value' => $return_route];
    $form['event_id'] = ['#type' => 'hidden', '#value' => $event_id];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Mark as read'),
      '#attributes' => ['class' => ['mel-notif-inbox__mark-read-btn']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    $deliveryId = (int) $form_state->getValue('delivery_id');
    $tab = (string) $form_state->getValue('tab');
    $filter = (string) $form_state->getValue('filter');
    $returnRoute = (string) $form_state->getValue('return_route');
    $eventId = (int) $form_state->getValue('event_id');
    if (!in_array($tab, NotificationFilter::allowedTabs(), TRUE)) {
      $tab = NotificationFilter::TAB_ALL;
    }
    if (!in_array($filter, NotificationFilter::allowedFilters(), TRUE)) {
      $filter = NotificationFilter::FILTER_ALL;
    }
    $isOrganiserActionCentre = in_array($returnRoute, [
      'myeventlane_notifications.organiser_action_centre',
      'myeventlane_notifications.organiser_event_action_centre',
    ], TRUE);
    if ($uid > 0 && $deliveryId > 0) {
      if ($isOrganiserActionCentre) {
        $this->userInbox->markReadGroup($uid, $deliveryId);
      }
      else {
        $this->userInbox->markReadOne($uid, $deliveryId);
      }
    }
    if ($returnRoute === 'myeventlane_notifications.organiser_event_action_centre' && $eventId > 0) {
      $form_state->setRedirect($returnRoute, ['node' => $eventId]);
      return;
    }
    if ($returnRoute === 'myeventlane_notifications.organiser_action_centre') {
      $form_state->setRedirect($returnRoute);
      return;
    }
    $form_state->setRedirect('myeventlane_notifications.inbox', [], ['query' => ['tab' => $tab, 'filter' => $filter]]);
  }

}
