<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Plugin\EventStudioSection;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Defines the contract for Event Studio operational sections.
 */
interface EventStudioSectionInterface extends PluginInspectionInterface {

  public const STATE_ACTIVE = 'active';

  public const STATE_READONLY = 'readonly';

  public const STATE_DEFERRED = 'deferred';

  public const STATE_COMING_SOON = 'coming_soon';

  public const STATES = [
    self::STATE_ACTIVE,
    self::STATE_READONLY,
    self::STATE_DEFERRED,
    self::STATE_COMING_SOON,
  ];

  /**
   * Returns the human-readable section title.
   */
  public function title(): string;

  /**
   * Returns the navigation group label.
   */
  public function group(): string;

  /**
   * Returns the explicit route name owned by this section.
   */
  public function routeName(): string;

  /**
   * Returns the stable route fragment used in paths and autosave keys.
   */
  public function routeFragment(): string;

  /**
   * Returns the navigation weight.
   */
  public function weight(): int;

  /**
   * Returns the group ordering weight.
   */
  public function groupWeight(): int;

  /**
   * Returns the optional icon identifier.
   */
  public function icon(): string;

  /**
   * Returns the section access policy identifier.
   */
  public function accessPolicy(): string;

  /**
   * Returns the rendering target contract identifier.
   */
  public function renderTarget(): string;

  /**
   * Returns the governed operational state.
   */
  public function sectionState(): string;

  /**
   * Returns TRUE when this section can mutate operational state.
   */
  public function isWritable(): bool;

  /**
   * Returns TRUE when this section participates in readiness metadata.
   */
  public function participatesInReadiness(): bool;

  /**
   * Returns the operational domain area for governance reporting.
   */
  public function operationalArea(): string;

  /**
   * Returns the empty-state behavior identifier.
   */
  public function emptyStateType(): string;

  /**
   * Returns the mobile priority, lower values load earlier.
   */
  public function mobilePriority(): int;

  /**
   * Returns TRUE when the section should be shown in navigation.
   */
  public function isVisibleInNavigation(): bool;

  /**
   * Returns TRUE when the section is intentionally placeholder-only.
   */
  public function isDeferred(): bool;

  /**
   * Builds the governed section content.
   *
   * @return array<string, mixed>
   */
  public function build(NodeInterface $event): array;

  /**
   * Evaluates section operational availability.
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResultInterface;

}
