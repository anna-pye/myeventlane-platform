<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

/**
 * Machine name for the organiser (vendor) Drupal user role.
 *
 * Single source of truth for checks that treat the organiser role like
 * "access vendor console" (gate, route access, controller asserts).
 *
 * @see user.role.vendor
 */
final class MelVendorOrganiserRole {

  /**
   * User role ID from config (config/sync/user.role.vendor.yml).
   */
  public const MACHINE_NAME = 'vendor';

  private function __construct() {}

}
