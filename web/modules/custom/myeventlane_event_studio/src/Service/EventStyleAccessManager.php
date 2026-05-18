<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_pro\Service\ProAccessService;
use Drupal\node\NodeInterface;

/**
 * Capability gate for Immersive event page styling.
 *
 * This service is the seam for future Pro subscription, boost purchase, or admin
 * override without hard-coding billing in Event Studio forms or theme render.
 */
final class EventStyleAccessManager {

  /**
   * Pro feature key for Immersive page style (see ProAccessService::PRO_FEATURES).
   */
  public const FEATURE_IMMERSIVE_STYLE = 'event_immersive_style';

  public function __construct(
    private readonly ?ProAccessService $proAccess = NULL,
  ) {}

  /**
   * Whether the account may select or persist Immersive styling for an event.
   */
  public function canUseImmersiveStyle(NodeInterface $event, AccountInterface $account): bool {
    if ($account->hasPermission('administer nodes')) {
      return TRUE;
    }

    if ($this->proAccess instanceof ProAccessService) {
      return $this->proAccess->canAccessFeature($account, self::FEATURE_IMMERSIVE_STYLE);
    }

    return FALSE;
  }

}
