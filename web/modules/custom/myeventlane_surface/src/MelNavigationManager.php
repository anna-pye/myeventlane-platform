<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Canonical shell navigation contracts — presentation metadata, not route access.
 *
 * Link targets and permissions remain owned by routing + existing link builders.
 */
final class MelNavigationManager {

  use StringTranslationTrait;

  public function __construct(
    private readonly SurfaceResolver $surfaceResolver,
  ) {}

  /**
   * Returns structural + labelling contract for the active surface.
   *
   * @return array<string, mixed>
   */
  public function getContract(): array {
    $surface = $this->surfaceResolver->resolve();
    return match ($surface) {
      MelSurfaceId::Customer => [
        'shell' => 'customer',
        'variant' => 'customer_shell',
        'nav_landmark_label' => $this->t('Account navigation'),
        'nav_root_classes' => [
          'mel-shell-nav',
          'mel-shell-nav--customer',
          'mel-my-account__nav',
        ],
        'nav_card_classes' => [
          'mel-shell-nav__card',
          'mel-my-account__nav-card',
        ],
        'list_classes' => [
          'mel-shell-nav__list',
          'mel-my-account__nav-list',
        ],
        'item_classes' => [
          'mel-shell-nav__item',
          'mel-my-account__nav-item',
        ],
        'link_classes' => [
          'mel-shell-nav__link',
          'mel-my-account__nav-link',
        ],
        'active_link_class' => 'mel-shell-nav__link--active mel-my-account__nav-link--active',
      ],
      MelSurfaceId::Vendor => [
        'shell' => 'vendor',
        'variant' => 'vendor_shell',
        'nav_landmark_label' => $this->t('Vendor console navigation'),
        'nav_root_classes' => [
          'mel-shell-nav',
          'mel-shell-nav--vendor',
        ],
        'nav_card_classes' => [
          'mel-shell-nav__card',
        ],
        'list_classes' => [
          'mel-shell-nav__list',
        ],
        'item_classes' => [
          'mel-shell-nav__item',
        ],
        'link_classes' => [
          'mel-shell-nav__link',
        ],
        'active_link_class' => 'mel-shell-nav__link--active',
      ],
      MelSurfaceId::Auth => [
        'shell' => 'auth',
        'variant' => 'auth_shell',
        'nav_landmark_label' => $this->t('Account'),
        'nav_root_classes' => [
          'mel-shell-nav',
          'mel-shell-nav--auth',
        ],
        'nav_card_classes' => [
          'mel-shell-nav__card',
          'mel-shell-nav__card--minimal',
        ],
        'list_classes' => [
          'mel-shell-nav__list',
        ],
        'item_classes' => [
          'mel-shell-nav__item',
        ],
        'link_classes' => [
          'mel-shell-nav__link',
        ],
        'active_link_class' => 'mel-shell-nav__link--active',
      ],
      default => [
        'shell' => 'public',
        'variant' => 'public_discovery',
        'nav_landmark_label' => '',
        'nav_root_classes' => ['mel-shell-nav', 'mel-shell-nav--public'],
        'nav_card_classes' => ['mel-shell-nav__card'],
        'list_classes' => ['mel-shell-nav__list'],
        'item_classes' => ['mel-shell-nav__item'],
        'link_classes' => ['mel-shell-nav__link'],
        'active_link_class' => 'mel-shell-nav__link--active',
      ],
    };
  }

}
