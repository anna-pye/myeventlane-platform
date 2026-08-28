<?php

declare(strict_types=1);

namespace Drupal\myeventlane_notifications\Service;

use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_notifications\NotificationContext;

/**
 * Builds the header notification bell render array without the Block plugin API.
 *
 * Block plugin discovery can fail on some environments (stale caches, deploy
 * timing). The bell is theme shell only; this service is the supported path.
 */
final class NotificationBellPresenter {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * @param string $bellContext
   *   Primary bell context: personal (public site) or business (vendor dashboard).
   *
   * @return array<string, mixed>
   *   Empty when the user must not see the bell.
   */
  public function build(string $bellContext = NotificationContext::PERSONAL): array {
    $account = $this->currentUser->getAccount();
    if (!$account->isAuthenticated() || !$account->hasPermission('access myeventlane notifications')) {
      return [];
    }

    if (!in_array($bellContext, [NotificationContext::PERSONAL, NotificationContext::BUSINESS], TRUE)) {
      $bellContext = NotificationContext::PERSONAL;
    }

    return [
      '#theme' => 'mel_notification_bell',
      '#bell_context' => $bellContext,
      '#inbox_url' => $this->urlGenerator->generateFromRoute(
        $bellContext === NotificationContext::BUSINESS
          ? 'myeventlane_notifications.organiser_action_centre'
          : 'myeventlane_notifications.inbox'
      ),
      '#settings_url' => $this->urlGenerator->generateFromRoute(
        $bellContext === NotificationContext::BUSINESS
          ? 'myeventlane_notifications.organiser_preferences'
          : 'myeventlane_notifications.preferences'
      ),
      '#attached' => [
        'library' => ['myeventlane_notifications/user_experience'],
      ],
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'max-age' => 0,
      ],
    ];
  }

}
