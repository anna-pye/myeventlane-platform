<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Interface for onboarding state entities.
 */
interface OnboardingStateInterface extends ContentEntityInterface {

  /**
   * Track: customer.
   */
  public const TRACK_CUSTOMER = 'customer';

  /**
   * Track: vendor.
   */
  public const TRACK_VENDOR = 'vendor';

  /**
   * Stage order (forward-only progression).
   *
   * @var string[]
   */
  public const STAGE_ORDER = [
    'probe',
    'present',
    'listen',
    'ask',
    'invite',
    'complete',
  ];

  /**
   * Gets the onboarding track.
   */
  public function getTrack(): string;

  /**
   * Sets the onboarding track.
   */
  public function setTrack(string $track): static;

  /**
   * Gets the current stage.
   */
  public function getStage(): string;

  /**
   * Sets the current stage.
   */
  public function setStage(string $stage): static;

  /**
   * Whether onboarding is completed.
   */
  public function isCompleted(): bool;

  /**
   * Sets the completed flag.
   */
  public function setCompleted(bool $completed): static;

  /**
   * Gets the vendor ID (vendor track only).
   */
  public function getVendorId(): ?int;

  /**
   * Sets the vendor ID.
   */
  public function setVendorId(?int $vendor_id): static;

  /**
   * Gets the store ID.
   */
  public function getStoreId(): ?int;

  /**
   * Sets the store ID.
   */
  public function setStoreId(?int $store_id): static;

  /**
   * Gets the flags map.
   *
   * @return array<string, mixed>
   */
  public function getFlags(): array;

  /**
   * Sets the flags map.
   *
   * @param array<string, mixed> $flags
   */
  public function setFlags(array $flags): static;

}
