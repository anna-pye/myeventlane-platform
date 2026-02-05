<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the Page Visual config entity.
 *
 * Assigns hero/illustration images to system routes. Images are Media entities;
 * resolution is route-based, not path-based. Uses media_uuid for config sync.
 *
 * @ConfigEntityType(
 *   id = "myeventlane_page_visual",
 *   label = @Translation("Page Visual"),
 *   label_collection = @Translation("Page Visuals"),
 *   label_singular = @Translation("page visual"),
 *   label_plural = @Translation("page visuals"),
 *   config_prefix = "page_visual",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "route_name",
 *     "media_uuid_desktop",
 *     "media_uuid_mobile",
 *     "hide_on_mobile",
 *     "alt_text",
 *     "enabled"
 *   },
 *   handlers = {
 *     "list_builder" = "Drupal\myeventlane_page_visuals\PageVisualListBuilder",
 *     "form" = {
 *       "add" = "Drupal\myeventlane_page_visuals\Form\PageVisualForm",
 *       "edit" = "Drupal\myeventlane_page_visuals\Form\PageVisualForm",
 *       "delete" = "Drupal\myeventlane_page_visuals\Form\PageVisualDeleteForm"
 *     }
 *   },
 *   links = {
 *     "collection" = "/admin/config/myeventlane/page-visuals",
 *     "add-form" = "/admin/config/myeventlane/page-visuals/add",
 *     "edit-form" = "/admin/config/myeventlane/page-visuals/manage/{myeventlane_page_visual}",
 *     "delete-form" = "/admin/config/myeventlane/page-visuals/manage/{myeventlane_page_visual}/delete"
 *   },
 *   admin_permission = "administer myeventlane page visuals"
 * )
 */
final class PageVisual extends ConfigEntityBase implements PageVisualInterface {

  /**
   * The machine name.
   *
   * @var string
   */
  protected string $id = '';

  /**
   * The human-readable label.
   *
   * @var string
   */
  protected string $label = '';

  /**
   * The route name (e.g. mel_search.view, view.upcoming_events.page_events).
   *
   * @var string
   */
  protected string $route_name = '';

  /**
   * The desktop Media entity UUID (portable across environments).
   *
   * @var string|null
   */
  protected ?string $media_uuid_desktop = NULL;

  /**
   * The mobile Media entity UUID (optional; portable across environments).
   *
   * @var string|null
   */
  protected ?string $media_uuid_mobile = NULL;

  /**
   * Whether to hide the visual on mobile viewports.
   *
   * @var bool
   */
  protected bool $hide_on_mobile = FALSE;

  /**
   * Alt text for the image (required for accessibility).
   *
   * @var string
   */
  protected string $alt_text = '';

  /**
   * Whether this visual is enabled.
   *
   * @var bool
   */
  protected bool $enabled = TRUE;

  /**
   * {@inheritdoc}
   */
  public function getRouteName(): string {
    return $this->route_name;
  }

  /**
   * {@inheritdoc}
   */
  public function getMediaUuidDesktop(): ?string {
    return $this->media_uuid_desktop;
  }

  /**
   * {@inheritdoc}
   */
  public function getMediaUuidMobile(): ?string {
    return $this->media_uuid_mobile;
  }

  /**
   * {@inheritdoc}
   */
  public function isHideOnMobile(): bool {
    return $this->hide_on_mobile;
  }

  /**
   * {@inheritdoc}
   */
  public function getAltText(): string {
    return $this->alt_text;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return $this->enabled;
  }

}
