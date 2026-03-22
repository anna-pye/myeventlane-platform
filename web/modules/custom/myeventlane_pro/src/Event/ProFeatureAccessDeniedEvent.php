<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Event;

use Drupal\user\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired when an organiser is denied access to a Pro-gated route.
 */
final class ProFeatureAccessDeniedEvent extends Event {

  public function __construct(
    private readonly UserInterface $user,
    private readonly string $routeName,
  ) {}

  public function getUser(): UserInterface {
    return $this->user;
  }

  public function getRouteName(): string {
    return $this->routeName;
  }

}
