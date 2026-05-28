<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_pro\Service\ProAccessService;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Capability gate for event page style and colour customisation.
 *
 * Non-Pro vendors always use Mockup 1 (classic + coral). Pro and admin may
 * choose curated MEL presets. Billing is enforced via ProAccessService only.
 */
final class EventStyleAccessManager {

  /**
   * Pro feature key for event page customisation (see ProAccessService::PRO_FEATURES).
   */
  public const FEATURE_IMMERSIVE_STYLE = 'event_immersive_style';

  public function __construct(
    private readonly ?ProAccessService $proAccess = NULL,
    private readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {}

  /**
   * Whether the account may customise event page style and colour presets.
   */
  public function canCustomizeEventPage(AccountInterface|int $account): bool {
    $account = $this->resolveAccount($account);
    if ($account === NULL) {
      return FALSE;
    }

    if ($account->hasPermission('administer nodes')) {
      return TRUE;
    }

    if ($this->proAccess instanceof ProAccessService) {
      return $this->proAccess->canAccessFeature($account, self::FEATURE_IMMERSIVE_STYLE);
    }

    return FALSE;
  }

  /**
   * Whether the account may use a given page style machine name.
   */
  public function canUseStyle(string $style, AccountInterface|int $account): bool {
    $style = strtolower(trim($style));
    if ($style === EventPageStyleResolver::STYLE_CLASSIC || $style === '') {
      return TRUE;
    }

    if ($style === EventPageStyleResolver::STYLE_IMMERSIVE) {
      return $this->canCustomizeEventPage($account);
    }

    return FALSE;
  }

  /**
   * Whether the account may use a given colour preset machine name.
   */
  public function canUseColour(string $colour, AccountInterface|int $account): bool {
    $colour = strtolower(trim($colour));
    if ($colour === EventPageStyleResolver::COLOUR_CORAL || $colour === '') {
      return TRUE;
    }

    return $this->canCustomizeEventPage($account);
  }

  /**
   * Whether the account may select or persist Immersive styling for an event.
   */
  public function canUseImmersiveStyle(NodeInterface $event, AccountInterface $account): bool {
    return $this->canUseStyle(EventPageStyleResolver::STYLE_IMMERSIVE, $account);
  }

  private function resolveAccount(AccountInterface|int $account): ?AccountInterface {
    if ($account instanceof AccountInterface) {
      return $account;
    }

    if ($account < 1 || !$this->entityTypeManager instanceof EntityTypeManagerInterface) {
      return NULL;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($account);
    return $user instanceof UserInterface ? $user : NULL;
  }

}
