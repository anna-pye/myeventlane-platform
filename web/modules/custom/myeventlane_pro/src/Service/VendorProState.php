<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;

final class VendorProState {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProActiveResolver $resolver,
  ) {}

  public function isPro(): bool {
    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $this->currentUser->id());

    if (!$user instanceof UserInterface) {
      return FALSE;
    }

    return $this->resolver->isUserProActive($user);
  }

}
