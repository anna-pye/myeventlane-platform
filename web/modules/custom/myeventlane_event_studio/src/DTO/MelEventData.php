<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\DTO;

/**
 * Vendor-safe event read model (no node entity in Twig or controllers).
 */
final class MelEventData {

  public int $id = 0;

  public string $title = '';

  public string $summary = '';

  public ?string $start = NULL;

  public ?string $end = NULL;

  /** @var array<string, mixed> */
  public array $location = [];

  public string $venue_mode = 'one_off';

  public string $ticket_type = 'rsvp';

  public bool $published = FALSE;

  public string $moderation_state = '';

  /** @see \Drupal\myeventlane_event_state\Service\EventStateResolver */
  public string $lifecycle_state = '';

  public int $changed = 0;

  public int $created = 0;

  public string $venue_label = '';

  public ?string $image_url = NULL;

  public int $start_timestamp = 0;

  public int $end_timestamp = 0;

  public bool $is_series_template = FALSE;

  public ?int $capacity = NULL;

  public bool $has_cover_image = FALSE;

  /** When field_product_target exists and is empty. */
  public bool $needs_ticket_product = FALSE;

  public bool $is_sold_out = FALSE;

  public ?string $category_label = NULL;

}
