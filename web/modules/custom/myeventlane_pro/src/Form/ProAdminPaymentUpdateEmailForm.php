<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends an organiser a secure, self-service Pro payment update link.
 */
final class ProAdminPaymentUpdateEmailForm extends ConfirmFormBase {

  /**
   * The organiser receiving the secure payment update link.
   */
  private ?UserInterface $organiser = NULL;

  public function __construct(
    private readonly MessagingManager $messagingManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_messaging.manager'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_pro'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_admin_payment_update_email_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    $this->organiser = $user;
    if (!$user instanceof UserInterface || trim((string) $user->getEmail()) === '') {
      throw new \InvalidArgumentException('A Pro organiser with an email address is required.');
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Send a secure payment update link to %name?', [
      '%name' => $this->organiser?->getDisplayName() ?? $this->t('this organiser'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The organiser will sign in and enter their own card details in Stripe. MyEventLane staff will not receive or handle the card details.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Send secure link');
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
    $uid = (int) $this->organiser->id();
    $messageId = $this->messagingManager->queue('pro_subscription_payment_update_link', (string) $this->organiser->getEmail(), [
      'first_name' => $this->organiser->getDisplayName(),
      'organiser_uid' => $uid,
      'payment_update_url' => Url::fromRoute('myeventlane_pro.payment_method_update', [], ['absolute' => TRUE])->toString(),
      'manage_url' => Url::fromRoute('myeventlane_pro.manage', [], ['absolute' => TRUE])->toString(),
    ], [
      // Prevent repeated staff clicks from sending multiple messages while
      // still allowing a deliberate resend after 15 minutes.
      'idempotency_key' => sprintf('pro-payment-update-link:%d:%d', $uid, intdiv($this->time->getRequestTime(), 900)),
    ]);

    if ($messageId === NULL) {
      $this->messenger()->addWarning($this->t('That secure link was already queued recently, or the message could not be queued.'));
    }
    else {
      $this->messenger()->addStatus($this->t('The secure payment update link has been queued for the organiser.'));
      $this->logger->notice('Staff user @actor queued a secure MEL Pro payment update link for organiser @uid.', [
        '@actor' => (string) $this->currentUser->id(),
        '@uid' => (string) $uid,
      ]);
    }
    $form_state->setRedirect('myeventlane_pro.admin_report');
  }

}
