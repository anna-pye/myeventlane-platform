<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProPaymentRecoveryService;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets authorised staff queue a normal Commerce Pro renewal retry.
 */
final class ProAdminPaymentRetryForm extends ConfirmFormBase {

  /**
   * The organiser whose payment is being retried.
   */
  private ?UserInterface $organiser = NULL;

  public function __construct(
    private readonly ProPaymentRecoveryService $recoveryService,
    private readonly LoggerInterface $logger,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_pro.payment_recovery'),
      $container->get('logger.channel.myeventlane_pro'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_admin_payment_retry_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    $this->organiser = $user;
    if (!$user instanceof UserInterface || !$this->recoveryService->isInActiveGracePeriod($user)) {
      throw new \InvalidArgumentException('Payment retry is only available during an active Pro grace period.');
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Retry the outstanding Pro payment for %name?', [
      '%name' => $this->organiser?->getDisplayName() ?? $this->t('this organiser'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This schedules one Commerce renewal attempt using the organiser’s current saved MEL Pro payment method. It does not expose card details.');
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
    return Url::fromRoute('myeventlane_pro.admin_report');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->organiser instanceof UserInterface) {
      throw new \LogicException('The Pro organiser is unavailable.');
    }
    try {
      $result = $this->recoveryService->queueRetry($this->organiser);
      if ($result === 'queued') {
        $this->messenger()->addStatus($this->t('The Pro payment retry has been scheduled.'));
        $this->logger->notice('Staff user @actor queued a MEL Pro payment retry for organiser @uid.', [
          '@actor' => (string) $this->currentUser->id(),
          '@uid' => (string) $this->organiser->id(),
        ]);
      }
      elseif ($result === 'duplicate') {
        $this->messenger()->addStatus($this->t('A payment retry is already scheduled.'));
      }
      else {
        $this->messenger()->addWarning($this->t('There is no outstanding Pro renewal to retry.'));
      }
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('The payment retry could not be scheduled. Send the organiser a secure payment update link first.'));
    }
    $form_state->setRedirect('myeventlane_pro.admin_report');
  }

}
