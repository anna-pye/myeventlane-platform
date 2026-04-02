<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Plugin\Field\FieldWidget;

use Drupal\address\Plugin\Field\FieldWidget\AddressDefaultWidget;

/**
 * AddressDefaultWidget with safe handling of incomplete POST data.
 *
 * Used as the implementation class for the core "address_default" widget via
 * hook_field_widget_info_alter(), and as the parent for MyEventLane widgets.
 *
 * @internal
 */
class LocationAddressDefaultWidgetBase extends AddressDefaultWidget {

  use AddressWidgetFormValuePatchTrait;

}
