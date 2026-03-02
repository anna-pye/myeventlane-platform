<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for Boost entitlement entities.
 */
interface BoostEntitlementInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Active entitlement status.
   */
  public const STATUS_ACTIVE = 'active';

  /**
   * Expired entitlement status.
   */
  public const STATUS_EXPIRED = 'expired';

  /**
   * Revoked entitlement status.
   */
  public const STATUS_REVOKED = 'revoked';

}
