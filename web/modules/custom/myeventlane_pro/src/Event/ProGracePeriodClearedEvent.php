<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Event;

use Drupal\user\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired when a Pro grace expiry field is cleared after a successful recovery path.
 */
final class ProGracePeriodClearedEvent extends Event {

  public function __construct(
    private readonly UserInterface $user,
  ) {}

  public function getUser(): UserInterface {
    return $this->user;
  }

}
