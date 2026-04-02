<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\myeventlane_location\Plugin\Field\FieldWidget\LocationAddressDefaultWidgetBase;

/**
 * Hook implementations for myeventlane_location.
 */
final class MyeventlaneLocationHooks {

  /**
   * Implements hook_field_widget_info_alter().
   *
   * Procedural hooks in .module files are not reliably discovered in Drupal 11;
   * this OOP hook ensures the address_default plugin class is always replaced.
   */
  #[Hook('field_widget_info_alter')]
  public function fieldWidgetInfoAlter(array &$info): void {
    if (isset($info['address_default']['class'])) {
      $info['address_default']['class'] = LocationAddressDefaultWidgetBase::class;
    }
  }

}
