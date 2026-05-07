<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Presentation helpers for governed tables (wrappers around Views/table markup).
 */
final class MelDataTableHelper {

  /**
   * Layout modifiers for responsive / density presentation.
   *
   * @return list<string>
   */
  public function getTableModifierClasses(MelSurfaceId $surface, bool $responsiveStack = FALSE): array {
    $classes = ['mel-data-table__frame'];
    $classes[] = match ($surface) {
      MelSurfaceId::Vendor => 'mel-data-table__frame--dense',
      MelSurfaceId::Staff => 'mel-data-table__frame--staff',
      MelSurfaceId::Customer => 'mel-data-table__frame--readable',
      MelSurfaceId::Public => 'mel-data-table__frame--minimal',
      MelSurfaceId::Auth => 'mel-data-table__frame--readable',
    };
    if ($responsiveStack) {
      $classes[] = 'mel-data-table__frame--stack-sm';
    }
    return $classes;
  }

  /**
   * Toolbar cluster classes (filters, density — presentation only).
   *
   * @return list<string>
   */
  public function getToolbarClasses(MelSurfaceId $surface): array {
    $classes = ['mel-table-toolbar'];
    if ($surface === MelSurfaceId::Vendor || $surface === MelSurfaceId::Staff) {
      $classes[] = 'mel-table-toolbar--dense';
    }
    return $classes;
  }

}
