<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines Pro vendor communication overrides.
 *
 * @ConfigEntityType(
 *   id = "myeventlane_pro_vendor_comms",
 *   label = @Translation("Pro vendor comms"),
 *   label_collection = @Translation("Pro vendor comms"),
 *   config_prefix = "vendor_comms",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "store_id",
 *     "enabled",
 *     "rsvp_body",
 *     "ticket_body",
 *     "reminder_body",
 *     "abandoned_cart_body",
 *     "brand_signature",
 *     "updated"
 *   },
 *   admin_permission = "administer own pro communications",
 * )
 */
final class ProVendorComms extends ConfigEntityBase {

  /**
   * Entity ID.
   *
   * @var string
   */
  protected string $id = '';

  /**
   * Label.
   *
   * @var string
   */
  protected string $label = '';

  /**
   * Store ID.
   *
   * @var int
   */
  protected int $store_id = 0;

  /**
   * Override enabled.
   *
   * @var bool
   */
  protected bool $enabled = FALSE;

  /**
   * RSVP template body.
   *
   * @var string
   */
  protected string $rsvp_body = '';

  /**
   * Ticket template body.
   *
   * @var string
   */
  protected string $ticket_body = '';

  /**
   * Reminder template body.
   *
   * @var string
   */
  protected string $reminder_body = '';

  /**
   * Abandoned cart template body.
   *
   * @var string
   */
  protected string $abandoned_cart_body = '';

  /**
   * Optional vendor signature.
   *
   * @var string
   */
  protected string $brand_signature = '';

  /**
   * Last updated timestamp.
   *
   * @var int
   */
  protected int $updated = 0;

}
