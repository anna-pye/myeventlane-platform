<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;

/**
 * Allows verified account email addresses to authenticate through Drupal core.
 */
final class EmailUserAuthentication implements UserAuthenticationInterface, UserAuthInterface {

  public function __construct(
    private readonly UserAuthenticationInterface $inner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function lookupAccount($identifier): UserInterface|false {
    $identifier = trim((string) $identifier);
    if ($identifier !== '' && filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
      $accounts = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['mail' => $identifier]);

      if (count($accounts) === 1) {
        $account = reset($accounts);
        return $account instanceof UserInterface ? $account : FALSE;
      }

      return FALSE;
    }

    return $this->inner->lookupAccount($identifier);
  }

  /**
   * {@inheritdoc}
   */
  public function authenticateAccount(UserInterface $account, #[\SensitiveParameter] string $password): bool {
    return $this->inner->authenticateAccount($account, $password);
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate($username, #[\SensitiveParameter] $password) {
    $account = $this->lookupAccount((string) $username);
    if (!$account || !$this->authenticateAccount($account, (string) $password)) {
      return FALSE;
    }

    return $account->id();
  }

}
