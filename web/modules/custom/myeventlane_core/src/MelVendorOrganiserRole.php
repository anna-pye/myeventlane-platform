<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

/**
 * Machine name for the organiser (vendor) Drupal user role.
 *
 * Single source of truth for the role ID used with vendor-console trust checks.
 *
 * @see user.role.vendor
 * @see \Drupal\myeventlane_core\VendorConsoleTrust
 */
final class MelVendorOrganiserRole {

  /**
   * User role ID from config (config/sync/user.role.vendor.yml).
   */
  public const MACHINE_NAME = 'vendor';

  private function __construct() {}

}
