<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\MessagePreferenceStorage;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an unsubscribe endpoint for messaging preferences.
 *
 * Updates myeventlane_message_preference (not deletes). Supports
 * marketing opt-out and operational reminder opt-out via type parameter.
 */
final class UnsubscribeController extends ControllerBase {

  /**
   * Constructs UnsubscribeController.
   *
   * @param \Drupal\myeventlane_messaging\Service\MessagePreferenceStorage $preferenceStorage
   *   The preference storage.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $siteConfigFactory
   *   The config factory (for hash_salt); named to avoid collision with ControllerBase::$configFactory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly MessagePreferenceStorage $preferenceStorage,
    private readonly ConfigFactoryInterface $siteConfigFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_messaging.message_preference_storage'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Unsubscribes a user from marketing or operational emails.
   *
   * @param mixed $uid
   *   The user ID.
   * @param mixed $ts
   *   The timestamp.
   * @param mixed $h
   *   The signature hash.
   * @return array
   *   A render array.
   */
  public function unsubscribe($uid, $ts, $h): array {
    $uid = (int) $uid;
    $ts = (int) $ts;
    $h = (string) $h;
    $type = (string) ($this->getRequest()->query->get('type', 'marketing'));

    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return ['#markup' => $this->t('Invalid link.')];
    }

    $secret = $this->siteConfigFactory->get('system.site')->get('hash_salt');
    $calc = hash('sha256', (string) $uid . $ts . $secret);
    if (!hash_equals($calc, $h)) {
      return ['#markup' => $this->t('Invalid signature.')];
    }

    $email = $user->getEmail();
    $recipient = $email ?: (string) $uid;
    $recipientType = $email ? 'email' : 'uid';

    if ($type === 'operational') {
      $this->preferenceStorage->setOperationalReminderOptOut($recipient, $recipientType, TRUE);
      return [
        '#markup' => $this->t('You have been unsubscribed from event and reminder emails. You may still receive order receipts and other transactional emails.'),
      ];
    }

    $this->preferenceStorage->setMarketingOptOut($recipient, $recipientType, TRUE);
    return [
      '#markup' => $this->t('You have been unsubscribed from marketing emails. You may still receive transactional emails about your orders and RSVPs.'),
    ];
  }

  /**
   * Builds an unsubscribe URL for a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param string $type
   *   'marketing' or 'operational'.
   *
   * @return string
   *   The absolute unsubscribe URL.
   */
  public function buildUnsubUrl(UserInterface $user, string $type = 'marketing'): string {
    $uid = (int) $user->id();
    $ts = $this->time->getRequestTime();
    $secret = $this->siteConfigFactory->get('system.site')->get('hash_salt');
    $h = hash('sha256', (string) $uid . $ts . $secret);

    $params = ['uid' => $uid, 'ts' => $ts, 'h' => $h];
    if ($type !== 'marketing') {
      $params['type'] = $type;
    }

    return Url::fromRoute(
      'myeventlane_messaging.unsubscribe',
      $params,
      ['absolute' => TRUE],
    )->toString(TRUE)->getGeneratedUrl();
  }

}
