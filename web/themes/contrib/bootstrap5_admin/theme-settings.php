<?php

/**
 * @file
 * theme-settings.php
 *
 * Provides theme settings for bootstrap 5 admin theme.
 */

use Drupal\bootstrap5_admin\SettingsManager;
use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_FORM_ID_alter().
 */
function bootstrap5_admin_form_system_theme_settings_alter(&$form, FormStateInterface $form_state, $form_id = NULL) {
  $settings_manager = new SettingsManager(\Drupal::service('theme.manager'), \Drupal::service('file_system'), \Drupal::service('messenger'), \Drupal::service('extension.list.theme'));
  return $settings_manager->themeSettingsAlter($form, $form_state, $form_id);
}
