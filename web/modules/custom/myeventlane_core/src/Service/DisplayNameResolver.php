<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Resolves display name for users.
 *
 * Uses field_display_name when available and non-empty, otherwise falls back
 * to the account name (username). Never exposes email as display name.
 */
final class DisplayNameResolver {

  /**
   * Constructs DisplayNameResolver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets the display name for an account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account (user session or loaded user).
   *
   * @return string
   *   Display name for greetings and UI. Never empty for authenticated users.
   */
  public function getDisplayName(AccountInterface $account): string {
    if ($account->isAnonymous()) {
      return '';
    }

    $user = $this->loadUser((int) $account->id());
    if (!$user instanceof UserInterface) {
      return $account->getDisplayName();
    }

    if ($user->hasField('field_display_name') && !$user->get('field_display_name')->isEmpty()) {
      $name = trim((string) $user->get('field_display_name')->value);
      if ($name !== '') {
        return $name;
      }
    }

    return $account->getDisplayName();
  }

  /**
   * Loads a user entity.
   */
  private function loadUser(int $uid): ?UserInterface {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    return $user instanceof UserInterface ? $user : NULL;
  }

}
