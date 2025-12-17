<?php

/**
 * @file
 * Debug script to inspect form structure.
 * 
 * Usage: ddev drush php:script debug-form-structure.php
 * Then visit /vendor/events/add in browser
 * Check watchdog logs for "FORM_STRUCTURE_DEBUG" messages
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

// Only run if accessed via CLI
if (PHP_SAPI !== 'cli') {
  return;
}

// This would need to be called from a form alter hook, but provides the structure
// to add to EventFormAlter for debugging

$debug_code = <<<'PHP'

// Add this to EventFormAlter::alterForm() at the END, before return:
$route_name = \Drupal::routeMatch()->getRouteName();
if ($route_name === 'myeventlane_vendor.console.events_add') {
  $logger = \Drupal::logger('myeventlane_event');
  
  // Check booking_config structure
  if (isset($form['booking_config'])) {
    $logger->debug('FORM_STRUCTURE_DEBUG: booking_config exists');
    if (isset($form['booking_config']['content'])) {
      $logger->debug('FORM_STRUCTURE_DEBUG: booking_config.content exists');
      if (isset($form['booking_config']['content']['paid_fields'])) {
        $logger->debug('FORM_STRUCTURE_DEBUG: booking_config.content.paid_fields exists');
        $paid_keys = array_filter(array_keys($form['booking_config']['content']['paid_fields']), function($k) {
          return !str_starts_with($k, '#');
        });
        $logger->debug('FORM_STRUCTURE_DEBUG: booking_config.content.paid_fields keys: @keys', ['@keys' => implode(', ', $paid_keys)]);
        if (isset($form['booking_config']['content']['paid_fields']['field_ticket_types'])) {
          $logger->debug('FORM_STRUCTURE_DEBUG: field_ticket_types IS in booking_config.content.paid_fields');
          $access = $form['booking_config']['content']['paid_fields']['field_ticket_types']['#access'] ?? 'NOT SET';
          $logger->debug('FORM_STRUCTURE_DEBUG: field_ticket_types #access: @access', ['@access' => var_export($access, TRUE)]);
        } else {
          $logger->error('FORM_STRUCTURE_DEBUG: field_ticket_types NOT in booking_config.content.paid_fields');
        }
      } else {
        $logger->error('FORM_STRUCTURE_DEBUG: booking_config.content.paid_fields does NOT exist');
      }
    } else {
      $logger->error('FORM_STRUCTURE_DEBUG: booking_config.content does NOT exist');
    }
  } else {
    $logger->error('FORM_STRUCTURE_DEBUG: booking_config does NOT exist');
  }
  
  // Check visibility structure
  if (isset($form['visibility'])) {
    $logger->debug('FORM_STRUCTURE_DEBUG: visibility exists');
    if (isset($form['visibility']['content'])) {
      $logger->debug('FORM_STRUCTURE_DEBUG: visibility.content exists');
      $vis_keys = array_filter(array_keys($form['visibility']['content']), function($k) {
        return !str_starts_with($k, '#');
      });
      $logger->debug('FORM_STRUCTURE_DEBUG: visibility.content keys: @keys', ['@keys' => implode(', ', $vis_keys)]);
      foreach (['field_category', 'field_accessibility'] as $field) {
        if (isset($form['visibility']['content'][$field])) {
          $logger->debug('FORM_STRUCTURE_DEBUG: @field IS in visibility.content', ['@field' => $field]);
          $access = $form['visibility']['content'][$field]['#access'] ?? 'NOT SET';
          $logger->debug('FORM_STRUCTURE_DEBUG: @field #access: @access', ['@field' => $field, '@access' => var_export($access, TRUE)]);
        } else {
          $logger->error('FORM_STRUCTURE_DEBUG: @field NOT in visibility.content', ['@field' => $field]);
        }
      }
    } else {
      $logger->error('FORM_STRUCTURE_DEBUG: visibility.content does NOT exist');
    }
  } else {
    $logger->error('FORM_STRUCTURE_DEBUG: visibility does NOT exist');
  }
}

PHP;

echo "Copy the code above and add it to EventFormAlter::alterForm() at the end.\n";
echo "Or check DEBUG_INSTRUCTIONS.md for logging that's already been added.\n";










