<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * Provides an unsubscribe endpoint for messaging preferences.
 */
final class UnsubscribeController extends ControllerBase {

  /**
   * Unsubscribes a user from marketing emails.
   *
   * @param mixed $uid
   *   The user ID.
   * @param mixed $ts
   *   The timestamp.
   * @param mixed $h
   *   The signature hash.
   *
   * @return array
   *   A render array.
   */
  public function unsubscribe($uid, $ts, $h): array {
    $uid = (int) $uid;
    $ts = (int) $ts;
    $h = (string) $h;

    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return ['#markup' => $this->t('Invalid link.')];
    }

    $secret = \Drupal::config('system.site')->get('hash_salt');
    $calc = hash('sha256', $uid . $ts . $secret);
    if (!hash_equals($calc, $h)) {
      return ['#markup' => $this->t('Invalid signature.')];
    }

    // Store pref in user_data to avoid schema work here.
    \Drupal::service('user.data')->set('myeventlane_messaging', $uid, 'marketing_opt_out', TRUE);

    return [
      '#markup' => $this->t('You have been unsubscribed from marketing emails. You may still receive transactional emails about your orders and RSVPs.'),
    ];
  }

  /**
   * Builds an unsubscribe URL for a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return string
   *   The absolute unsubscribe URL.
   */
  public static function buildUnsubUrl(UserInterface $user): string {
    $uid = (int) $user->id();
    $ts = \Drupal::time()->getCurrentTime();
    $secret = \Drupal::config('system.site')->get('hash_salt');
    $h = hash('sha256', $uid . $ts . $secret);

    return Url::fromRoute(
      'myeventlane_messaging.unsubscribe',
      ['uid' => $uid, 'ts' => $ts, 'h' => $h],
      ['absolute' => TRUE],
    )->toString(TRUE)->getGeneratedUrl();
  }

}
