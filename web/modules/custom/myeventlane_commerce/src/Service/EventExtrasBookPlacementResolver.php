<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event\Utility\EventNodeRevisionSave;
use Drupal\node\NodeInterface;

/**
 * Resolves where operational extras appear on the paid event book page.
 */
final class EventExtrasBookPlacementResolver {

  use StringTranslationTrait;

  public const PLACEMENT_MAIN_BEFORE_ACCESS = 'main_before_access';

  public const PLACEMENT_SIDEBAR = 'sidebar';

  public const FIELD_NAME = 'field_mel_extras_book_placement';

  /**
   * Constructs the resolver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Options for vendor placement radios.
   *
   * @return array<string, string>
   *   Machine value => label for vendor forms.
   */
  public function getOptions(): array {
    return [
      self::PLACEMENT_MAIN_BEFORE_ACCESS => (string) t('Under ticket choices, before the access code'),
      self::PLACEMENT_SIDEBAR => (string) t('In the sidebar next to “Your selection”'),
    ];
  }

  /**
   * Returns the placement for an event (defaults to main column).
   */
  public function getPlacement(NodeInterface $event): string {
    if ($event->bundle() !== 'event' || !$event->hasField(self::FIELD_NAME)) {
      return self::PLACEMENT_MAIN_BEFORE_ACCESS;
    }
    if ($event->get(self::FIELD_NAME)->isEmpty()) {
      return self::PLACEMENT_MAIN_BEFORE_ACCESS;
    }
    $value = (string) $event->get(self::FIELD_NAME)->value;
    return $this->isValid($value) ? $value : self::PLACEMENT_MAIN_BEFORE_ACCESS;
  }

  /**
   * Whether extras render in the booking sidebar.
   */
  public function isSidebar(NodeInterface $event): bool {
    return $this->getPlacement($event) === self::PLACEMENT_SIDEBAR;
  }

  /**
   * Whether extras render in the main column before the access code.
   */
  public function isMainBeforeAccess(NodeInterface $event): bool {
    return $this->getPlacement($event) === self::PLACEMENT_MAIN_BEFORE_ACCESS;
  }

  /**
   * Persists vendor placement when the field exists.
   */
  public function savePlacement(NodeInterface $event, string $placement): void {
    if (!$event->hasField(self::FIELD_NAME)) {
      throw new \RuntimeException('Extras book placement field is not available on this event.');
    }
    if (!$this->isValid($placement)) {
      $placement = self::PLACEMENT_MAIN_BEFORE_ACCESS;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $fresh = $storage->load($event->id());
    if (!$fresh instanceof NodeInterface) {
      throw new \RuntimeException('Event could not be reloaded for placement save.');
    }
    if ($this->getPlacement($fresh) === $placement) {
      return;
    }
    $fresh->set(self::FIELD_NAME, $placement);
    EventNodeRevisionSave::prepare($fresh, 'Updated extras booking placement from Vendor Studio.');
    if ($fresh->getEntityType()->isRevisionable()) {
      $uid = (int) $this->currentUser->id();
      if ($uid > 0) {
        $fresh->setRevisionUserId($uid);
      }
    }
    try {
      $fresh->save();
    }
    catch (\Throwable $e) {
      throw new \RuntimeException($e->getMessage(), 0, $e);
    }
  }

  /**
   * Whether a placement machine name is allowed.
   */
  public function isValid(string $placement): bool {
    return in_array($placement, [
      self::PLACEMENT_MAIN_BEFORE_ACCESS,
      self::PLACEMENT_SIDEBAR,
    ], TRUE);
  }

}
