<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProPaymentRecoveryService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms an asynchronous retry of the outstanding Pro renewal.
 */
final class ProPaymentRetryForm extends ConfirmFormBase {

  public function __construct(
    private readonly ProPaymentRecoveryService $recoveryService,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_pro.payment_recovery'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_payment_retry_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Retry your outstanding MEL Pro payment?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('We will securely retry the outstanding renewal using your saved MEL Pro payment method. Only one retry will be scheduled.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Retry payment');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('myeventlane_pro.manage');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->recoveryService->queueRetry($this->currentUser);
      if ($result === 'queued') {
        $this->messenger()->addStatus($this->t('Your payment retry has been scheduled. We will email you when it is processed.'));
      }
      elseif ($result === 'duplicate') {
        $this->messenger()->addStatus($this->t('A payment retry is already scheduled.'));
      }
      else {
        $this->messenger()->addWarning($this->t('There is no outstanding MEL Pro payment to retry.'));
      }
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('We could not schedule the payment retry. Update your payment method or contact support.'));
    }
    $form_state->setRedirect('myeventlane_pro.manage');
  }

}
