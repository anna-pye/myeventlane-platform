<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProBillingSchedule;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for cancelling a Pro subscription.
 *
 * Uses Commerce Recurring's native scheduled cancellation.
 */
final class ProCancelConfirmForm extends ConfirmFormBase {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.myeventlane_pro'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_cancel_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Cancel your Pro subscription at the end of this billing period?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Your Pro features remain active until the period ends. You can reactivate before then.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('myeventlane_pro.manage');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Cancel at period end');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ids = $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $this->currentUser->id())
      ->condition('billing_schedule', ProBillingSchedule::ALL, 'IN')
      ->condition('state', ['trial', 'active'], 'IN')
      ->execute();

    if (empty($ids)) {
      $this->messenger()->addWarning($this->t('No active subscription found to cancel.'));
      $form_state->setRedirectUrl(new Url('myeventlane_pro.overview'));
      return;
    }

    $subscriptions = $this->entityTypeManager
      ->getStorage('commerce_subscription')
      ->loadMultiple($ids);

    foreach ($subscriptions as $subscription) {
      try {
        $subscription->cancel(TRUE);
        $subscription->save();

        $this->logger->notice('Pro subscription @id scheduled for period-end cancellation by user @uid.', [
          '@id' => $subscription->id(),
          '@uid' => $this->currentUser->id(),
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to cancel subscription @id: @msg', [
          '@id' => $subscription->id(),
          '@msg' => $e->getMessage(),
        ]);
        $this->messenger()->addError($this->t('There was an error cancelling your subscription. Please contact support.'));
        $form_state->setRedirectUrl(new Url('myeventlane_pro.manage'));
        return;
      }
    }

    $this->messenger()->addStatus($this->t('Your Pro subscription will end at the close of the current billing period.'));
    $form_state->setRedirectUrl(new Url('myeventlane_pro.manage'));
  }

}
