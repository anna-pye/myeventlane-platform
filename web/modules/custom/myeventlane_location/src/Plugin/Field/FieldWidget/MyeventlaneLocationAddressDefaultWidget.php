<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Plugin\Field\FieldWidget;

/**
 * Config-exportable address widget with the same UI as the Address module default.
 *
 * Prefer using core widget id "address_default" when possible; it is swapped
 * to LocationAddressDefaultWidgetBase site-wide via hook_field_widget_info_alter().
 *
 * @FieldWidget(
 *   id = "myeventlane_location_address_default",
 *   label = @Translation("Address"),
 *   field_types = {
 *     "address"
 *   }
 * )
 */
final class MyeventlaneLocationAddressDefaultWidget extends LocationAddressDefaultWidgetBase {

}
