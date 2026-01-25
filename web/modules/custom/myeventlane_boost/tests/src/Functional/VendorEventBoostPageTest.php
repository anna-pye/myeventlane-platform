<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_boost\Functional;

use Drupal\commerce_store\Entity\Store;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;

/**
 * Functional test: vendor can load the event Boost page.
 *
 * @group myeventlane_boost
 */
#[\Drupal\Tests\RunTestsInSeparateProcesses]
final class VendorEventBoostPageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'field',
    'node',
    'options',
    'text',
    'datetime',
    'user',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_product',
    'commerce_order',
    'commerce_cart',
    'myeventlane_core',
    'myeventlane_schema',
    'myeventlane_event',
    'myeventlane_vendor',
    'myeventlane_boost',
  ];

  /**
   * Tests that the event Boost page loads for the event owner.
   */
  public function testVendorEventBoostTabLoads(): void {
    $vendor_user = $this->drupalCreateUser([
      'access content',
      'administer nodes',
      'access vendor console',
      'purchase boost for events',
    ]);

    $store = Store::create([
      'type' => 'online',
      'uid' => $vendor_user->id(),
      'name' => 'Vendor Test Store',
      'mail' => 'vendor-store@example.com',
      'default_currency' => 'AUD',
      'timezone' => 'Australia/Sydney',
      'address' => [
        'country_code' => 'AU',
        'administrative_area' => '',
        'locality' => '',
        'postal_code' => '',
        'address_line1' => '',
        'organization' => 'Vendor Test Store',
      ],
      'billing_countries' => ['AU'],
      'is_default' => FALSE,
      'status' => TRUE,
    ]);
    $store->save();

    // Satisfy boost route access requirement: Stripe must be connected.
    if ($store->hasField('field_stripe_account_id')) {
      $store->set('field_stripe_account_id', 'acct_test_123');
    }
    if ($store->hasField('field_stripe_connected')) {
      $store->set('field_stripe_connected', 1);
    }
    $store->save();

    $event = Node::create([
      'type' => 'event',
      'title' => 'Boost Page Test Event',
      'status' => 1,
      'uid' => $vendor_user->id(),
      // Ticketed event so the vendor console would show a Boost tab.
      'field_event_type' => 'paid',
      'field_event_start' => gmdate('Y-m-d\TH:i:s', time() + 86400),
    ]);
    if ($event->hasField('field_event_store')) {
      $event->set('field_event_store', ['target_id' => $store->id()]);
    }
    $event->save();

    $this->drupalLogin($vendor_user);

    $this->drupalGet('/event/' . $event->id() . '/boost');
    $this->assertSession()->statusCodeEquals(200);

    // Confirm we're on the Boost purchase page (heading + lead copy).
    $this->assertSession()->elementTextContains('css', 'h1.boost-title', 'Boost');
    $this->assertSession()->pageTextContains('Featured placement + badge. Choose a boost duration below.');

    // If no boost products are configured, the form shows an empty state.
    $this->assertSession()->pageTextContains('No boost options are available right now.');
  }

}

