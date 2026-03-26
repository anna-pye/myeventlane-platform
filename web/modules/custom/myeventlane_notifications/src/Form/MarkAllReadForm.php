<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\myeventlane_notifications\NotificationFilter;
use Drupal\myeventlane_notifications\Service\NotificationUserInboxService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Marks all unread deliveries read for the current user.
 */
final class MarkAllReadForm extends FormBase {

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
    return 'myeventlane_notifications_mark_all_read';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $filter = NotificationFilter::ALL): array {
    if (!in_array($filter, NotificationFilter::allowed(), TRUE)) {
      $filter = NotificationFilter::ALL;
    }
    $form['filter'] = [
      '#type' => 'hidden',
      '#value' => $filter,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Mark all as read'),
      '#attributes' => ['class' => ['mel-notif-inbox__mark-all-btn']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    $filter = (string) $form_state->getValue('filter');
    if (!in_array($filter, NotificationFilter::allowed(), TRUE)) {
      $filter = NotificationFilter::ALL;
    }
    if ($uid > 0) {
      $this->userInbox->markAllRead($uid);
    }
    $form_state->setRedirect('myeventlane_notifications.inbox', [], ['query' => ['filter' => $filter]]);
  }

}
