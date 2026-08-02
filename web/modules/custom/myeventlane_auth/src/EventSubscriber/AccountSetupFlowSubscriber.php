<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Carries account-setup presentation through Drupal's secure reset redirect.
 */
final class AccountSetupFlowSubscriber implements EventSubscriberInterface {

  public const SESSION_KEY = 'mel_account_setup_uid';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 28]];
  }

  /**
   * Stores first-use intent without changing Drupal's token validation.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    $uid = (string) $request->attributes->get('uid', '');

    if ($route === 'user.reset' && $uid !== '') {
      $session = $request->getSession();
      if ($request->query->get('mel_flow') === 'account_setup') {
        $session->set(self::SESSION_KEY, $uid);
      }
      elseif ($request->isMethod('GET')) {
        $session->remove(self::SESSION_KEY);
      }
      return;
    }

    if ($route === 'user.reset.form' && $request->getSession()->get(self::SESSION_KEY) === $uid) {
      $request->attributes->get('_route_object')?->setDefault('_title', 'Set up your account');
    }
  }

}
