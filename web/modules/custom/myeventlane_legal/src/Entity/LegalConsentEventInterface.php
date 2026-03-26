<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Interface for Legal Consent Event entities.
 *
 * Immutable audit records for legal consent actions.
 */
interface LegalConsentEventInterface extends ContentEntityInterface {

  /**
   * Valid source values for consent events.
   */
  public const SOURCE_RSVP = 'rsvp';

  public const SOURCE_CHECKOUT = 'checkout';

  public const SOURCE_REGISTRATION = 'registration';

}
