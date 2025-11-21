<?php

namespace Drupal\bootstrap5_admin;

use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Implements trusted prerender callbacks for the Claro theme.
 *
 * @internal
 */
class Bootstrap5AdminPreRender implements TrustedCallbackInterface {

  /**
   * Prerender callback for Vertical Tabs element.
   */
  public static function verticalTabs($element) {
    $group_type_is_details = isset($element['group']['#type']) && $element['group']['#type'] === 'details';
    $groups_are_present = isset($element['group']['#groups']) && is_array($element['group']['#groups']);

    // If the vertical tabs have a details group, add attributes to those
    // details elements, so they are styled as accordion items and have BEM
    // classes.
    if ($group_type_is_details && $groups_are_present) {
      $group_keys = Element::children($element['group']['#groups'], TRUE);
      $first_key = TRUE;
      $last_group_with_child_key = NULL;
      $last_group_with_child_key_last_child_key = NULL;

      $group_key = implode('][', $element['#parents']);
      // Only check siblings against groups because we are only looking for
      // group elements.
      if (in_array($group_key, $group_keys)) {
        $children_keys = Element::children($element['group']['#groups'][$group_key], TRUE);

        foreach ($children_keys as $child_key) {
          $last_group_with_child_key = $group_key;
          $type = $element['group']['#groups'][$group_key][$child_key]['#type'] ?? NULL;
          if ($type === 'details') {
            // Add BEM class to specify the details element is in a vertical
            // tabs group.
            $element['group']['#groups'][$group_key][$child_key]['#attributes']['class'][] = 'vertical-tabs__item';
            $element['group']['#groups'][$group_key][$child_key]['#vertical_tab_item'] = TRUE;

            if ($first_key) {
              $element['group']['#groups'][$group_key][$child_key]['#attributes']['class'][] = 'vertical-tabs__item--first';
              $first_key = FALSE;
            }

            $last_group_with_child_key_last_child_key = $child_key;
          }
        }
      }

      if ($last_group_with_child_key && $last_group_with_child_key_last_child_key) {
        $element['group']['#groups'][$last_group_with_child_key][$last_group_with_child_key_last_child_key]['#attributes']['class'][] = 'vertical-tabs__item--last';
      }

      $element['#attributes']['class'][] = 'vertical-tabs__items';
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return [
      'verticalTabs',
    ];
  }

}
