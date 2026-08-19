<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProBillingSchedule;
use Drupal\myeventlane_pro\Service\ProSubscriptionStatusService;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Schedules a Pro subscription cancellation at the paid period end.
 */
final class ProCancelRequestForm extends FormBase {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProSubscriptionStatusService $statusService,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_pro.subscription_status'),
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_cancel_request';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'myeventlane_pro/pro';
    $status = $this->statusService->getStatusForCurrentUser();

    if (!$status['is_pro'] || !$status['is_subscription_managed'] || !$status['has_active_subscription']) {
      $this->messenger()->addWarning($this->t('You do not have an active subscription to cancel.'));
      $form['redirect'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Redirecting...') . '</p>',
      ];
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_pro.overview'));
      return $form;
    }

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p class="mel-pro-cancel-request__intro">' . $this->t('Your subscription will stop at the end of the current billing period. You can reactivate it before that date.') . '</p>',
      '#weight' => -10,
    ];

    $form['support'] = [
      '#type' => 'markup',
      '#markup' => '<p class="mel-pro-cancel-request__support">' . $this->t("Need help before you cancel? We're here to help.") . '</p>',
      '#weight' => -5,
    ];

    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason for cancelling (optional)'),
      '#description' => $this->t('Let us know why you are cancelling. This helps us improve.'),
      '#rows' => 3,
      '#weight' => 0,
    ];

    $form['actions'] = ['#type' => 'actions', '#weight' => 10];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel at period end'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Keep my subscription'),
      '#url' => Url::fromRoute('myeventlane_pro.manage'),
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface) {
      $this->messenger()->addError($this->t('Unable to process your request.'));
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_pro.overview'));
      return;
    }

    $subscription = NULL;
    $ids = $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $user->id())
      ->condition('billing_schedule', ProBillingSchedule::ALL, 'IN')
      ->sort('subscription_id', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!empty($ids)) {
      $subscription = $this->entityTypeManager->getStorage('commerce_subscription')->load((int) reset($ids));
    }

    $notes = trim((string) $form_state->getValue('reason', ''));
    if ($notes !== '') {
      $notes = 'User reason: ' . $notes;
    }

    if (!$this->database->schema()->tableExists('myeventlane_pro_cancel_request')) {
      $this->messenger()->addError($this->t('Cancellation requests are not available at the moment. Please contact support.'));
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_pro.manage'));
      return;
    }

    if (!$subscription instanceof \Drupal\commerce_recurring\Entity\SubscriptionInterface) {
      $this->messenger()->addError($this->t('Your active subscription could not be loaded. Please contact support.'));
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_pro.manage'));
      return;
    }

    if ($subscription->hasScheduledChange('state', 'canceled')) {
      $this->messenger()->addStatus($this->t('Your subscription is already set to cancel at the end of the current billing period.'));
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_pro.manage'));
      return;
    }

    $subscription->cancel(TRUE);
    $subscription->save();

    $this->database->insert('myeventlane_pro_cancel_request')
      ->fields([
        'uid' => (int) $user->id(),
        'subscription_id' => (int) $subscription->id(),
        'status' => 'scheduled',
        'requested_at' => $this->time->getRequestTime(),
        'notes' => $notes !== '' ? $notes : NULL,
      ])
      ->execute();

    $this->messenger()->addStatus($this->t('Your Pro subscription will end at the close of the current billing period. You can reactivate it before then.'));
    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_pro.manage'));
  }

}
