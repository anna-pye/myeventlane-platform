<?php

/**
 * @file
 * Creates default main menu links for MyEventLane.
 *
 * This file is included by hook_install() to create default menu structure.
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;

/**
 * Creates default main menu links.
 */
function _myeventlane_core_create_default_menu_links(): void {
  // Check if menu links already exist.
  $existing = \Drupal::entityTypeManager()
    ->getStorage('menu_link_content')
    ->loadByProperties(['menu_name' => 'main']);

  if (!empty($existing)) {
    return;
  }

  // Create Events link.
  $events_link = MenuLinkContent::create([
    'title' => 'Events',
    'link' => ['uri' => 'internal:/events'],
    'menu_name' => 'main',
    'weight' => 0,
  ]);
  $events_link->save();

  // Create Create Event link.
  $create_link = MenuLinkContent::create([
    'title' => 'Create Event',
    'link' => ['uri' => 'internal:/event/add'],
    'menu_name' => 'main',
    'weight' => 1,
  ]);
  $create_link->save();

  // Create Vendors link.
  $vendors_link = MenuLinkContent::create([
    'title' => 'Vendors',
    'link' => ['uri' => 'internal:/vendors'],
    'menu_name' => 'main',
    'weight' => 2,
  ]);
  $vendors_link->save();

  // Create Support link.
  $support_link = MenuLinkContent::create([
    'title' => 'Support',
    'link' => ['uri' => 'internal:/support'],
    'menu_name' => 'main',
    'weight' => 3,
  ]);
  $support_link->save();
}
