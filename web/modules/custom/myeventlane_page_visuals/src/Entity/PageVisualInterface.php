<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for Page Visual config entities.
 */
interface PageVisualInterface extends ConfigEntityInterface {

  /**
   * Gets the route name this visual applies to.
   */
  public function getRouteName(): string;

  /**
   * Gets the desktop Media entity UUID (portable across environments).
   */
  public function getMediaUuidDesktop(): ?string;

  /**
   * Gets the mobile Media entity UUID (optional; portable across environments).
   */
  public function getMediaUuidMobile(): ?string;

  /**
   * Whether to hide the visual on mobile viewports.
   */
  public function isHideOnMobile(): bool;

  /**
   * Gets the alt text for the image.
   */
  public function getAltText(): string;

  /**
   * Whether this visual is enabled.
   */
  public function isEnabled(): bool;

}
