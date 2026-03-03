<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for cancelling a Pro subscription.
 *
 * Uses Commerce Recurring's state machine transition — no custom cancel logic.
 */
final class ProCancelConfirmForm extends ConfirmFormBase {

  private const BILLING_SCHEDULE = 'mel_pro_monthly';

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
    return $this->t('Are you sure you want to cancel your Pro subscription?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Your Pro features will remain active until the end of the current billing period. This action cannot be undone from this page.');
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
    return $this->t('Cancel Subscription');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ids = $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $this->currentUser->id())
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->condition('state', 'active')
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
        $subscription->getState()->applyTransitionById('cancel');
        $subscription->save();

        $this->logger->notice('Pro subscription @id cancelled by user @uid.', [
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

    $this->messenger()->addStatus($this->t('Your Pro subscription has been cancelled.'));
    $form_state->setRedirectUrl(new Url('myeventlane_pro.overview'));
  }

}
