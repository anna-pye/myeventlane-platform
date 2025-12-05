<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Plugin\Field\FieldWidget;

use Drupal\address\Plugin\Field\FieldWidget\AddressDefaultWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Address autocomplete widget with Google Maps and Apple Maps support.
 *
 * @FieldWidget(
 *   id = "myeventlane_location_address_autocomplete",
 *   label = @Translation("Address with autocomplete"),
 *   field_types = {
 *     "address"
 *   }
 * )
 */
final class AddressAutocompleteWidget extends AddressDefaultWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Add a search input for address autocomplete.
    $element['address_search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search for address or venue'),
      '#description' => $this->t('Start typing to search for an address. Select from the suggestions to populate the address fields.'),
      '#attributes' => [
        'class' => ['myeventlane-location-address-search'],
        'autocomplete' => 'off',
        'placeholder' => $this->t('Type address or venue name...'),
      ],
      '#weight' => -10,
    ];

    // Add hidden fields for latitude and longitude.
    // These will be populated by JavaScript when an address is selected.
    $element['latitude'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['myeventlane-location-latitude'],
      ],
    ];

    $element['longitude'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['myeventlane-location-longitude'],
      ],
    ];

    // Add a container for map preview.
    $element['map_preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['myeventlane-location-map-preview'],
        'style' => 'width: 100%; height: 200px; margin-top: 1em; border: 1px solid #ccc; display: none;',
      ],
      '#weight' => 100,
    ];

    // Add wrapper attributes for JavaScript targeting.
    $element['#attributes']['class'][] = 'myeventlane-location-address-widget';
    $element['#attributes']['data-delta'] = $delta;

    // Attach library.
    $element['#attached']['library'][] = 'myeventlane_location/address_autocomplete';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $values = parent::massageFormValues($values, $form, $form_state);

    // Extract latitude and longitude from hidden fields and store them.
    // Note: We'll need to save these to dedicated fields (field_location_latitude/longitude
    // or field_event_lat/lng) via a form submit handler or entity presave hook.
    foreach ($values as $delta => &$value) {
      // The latitude/longitude are in the form values but not part of the address field.
      // We'll handle saving them separately in the module hooks.
    }

    return $values;
  }

}



