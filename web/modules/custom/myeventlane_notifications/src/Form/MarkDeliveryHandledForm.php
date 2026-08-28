<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_notifications\Service\NotificationUserInboxService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Explicitly marks one organiser action as handled.
 */
final class MarkDeliveryHandledForm extends FormBase {

  public function __construct(
    private readonly NotificationUserInboxService $userInbox,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('myeventlane_notifications.user_inbox'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_notifications_mark_delivery_handled';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $delivery_id = 0, int $event_id = 0): array {
    $form['delivery_id'] = ['#type' => 'hidden', '#value' => $delivery_id];
    $form['event_id'] = ['#type' => 'hidden', '#value' => $event_id];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Mark handled'),
      '#attributes' => ['class' => ['mel-action-centre__handled-button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    $deliveryId = (int) $form_state->getValue('delivery_id');
    $eventId = (int) $form_state->getValue('event_id');
    if (!$this->userInbox->markHandledOne($uid, $deliveryId)) {
      $this->messenger()->addError($this->t('This update could not be marked as handled.'));
    }
    $route = $eventId > 0
      ? 'myeventlane_notifications.organiser_event_action_centre'
      : 'myeventlane_notifications.organiser_action_centre';
    $parameters = $eventId > 0 ? ['node' => $eventId] : [];
    $form_state->setRedirect($route, $parameters);
  }

}
