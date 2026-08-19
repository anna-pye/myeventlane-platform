<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProBillingSchedule;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Removes a scheduled end-of-period Pro cancellation.
 */
final class ProReactivateForm extends ConfirmFormBase {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('current_user'), $container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'myeventlane_pro_reactivate';
  }

  public function getQuestion() {
    return $this->t('Keep your MEL Pro subscription active?');
  }

  public function getDescription() {
    return $this->t('The scheduled cancellation will be removed and normal monthly renewals will continue.');
  }

  public function getConfirmText() {
    return $this->t('Reactivate MEL Pro');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('myeventlane_pro.manage');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ids = $this->entityTypeManager->getStorage('commerce_subscription')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $this->currentUser->id())
      ->condition('billing_schedule', ProBillingSchedule::ALL, 'IN')
      ->condition('state', ['trial', 'active'], 'IN')
      ->sort('subscription_id', 'DESC')
      ->range(0, 1)
      ->execute();
    $subscription = $ids === [] ? NULL : $this->entityTypeManager->getStorage('commerce_subscription')->load((int) reset($ids));

    if (!$subscription instanceof \Drupal\commerce_recurring\Entity\SubscriptionInterface || !$subscription->hasScheduledChange('state', 'canceled')) {
      $this->messenger()->addWarning($this->t('No scheduled cancellation was found.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $subscription->removeScheduledChanges('state');
    $subscription->setEndTime(NULL);
    $subscription->save();
    $this->messenger()->addStatus($this->t('Your MEL Pro subscription will continue.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
