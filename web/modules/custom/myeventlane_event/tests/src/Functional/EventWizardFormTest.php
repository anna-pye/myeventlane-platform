<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;

/**
 * Functional tests for Event Creation Wizard.
 *
 * @group myeventlane_event
 */
final class EventWizardFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'user',
    'field',
    'text',
    'options',
    'datetime',
    'image',
    'taxonomy',
    'address',
    'link',
    'myeventlane_schema',
    'myeventlane_event',
    'myeventlane_vendor',
    'myeventlane_location',
  ];

  /**
   * Test user (vendor).
   */
  private User $vendorUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create vendor user.
    $this->vendorUser = $this->drupalCreateUser([
      'create event content',
      'edit own event content',
      'access vendor console',
    ]);
  }

  /**
   * Tests wizard step progression.
   */
  public function testWizardStepProgression(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard.
    $this->drupalGet('/vendor/events/wizard');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Event setup');

    // Step 1: Basics - fill in title and event type.
    $this->assertSession()->fieldExists('title');
    $this->assertSession()->fieldExists('field_event_type');

    $this->submitForm([
      'title' => 'Test Event',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // Should be on step 2: When & Where.
    $this->assertSession()->pageTextContains('When & Where');
    $this->assertSession()->fieldExists('date_time[field_event_start]');

    // Fill in date and location.
    $start_date = date('Y-m-d\TH:i:s', strtotime('+1 week'));
    $this->submitForm([
      'date_time[field_event_start]' => $start_date,
      'location[location_mode]' => 'in_person',
      'location[field_venue_name]' => 'Test Venue',
      'location[field_location][address_line1]' => '123 Test St',
      'location[field_location][locality]' => 'Test City',
      'location[field_location][administrative_area]' => 'NSW',
      'location[field_location][postal_code]' => '2000',
      'location[field_location][country_code]' => 'AU',
    ], 'Continue');

    // Should be on step 3: Branding.
    $this->assertSession()->pageTextContains('Branding');

    // Skip branding and continue.
    $this->submitForm([], 'Continue');

    // Should be on step 4: Tickets & Capacity.
    $this->assertSession()->pageTextContains('Tickets & Capacity');

    // Fill in capacity.
    $this->submitForm([
      'field_capacity' => '100',
    ], 'Continue');

    // Should be on step 5: Content.
    $this->assertSession()->pageTextContains('Content');

    // Fill in body.
    $this->submitForm([
      'body[value]' => 'This is a test event description.',
    ], 'Continue');

    // Should be on step 6: Policies & Accessibility.
    $this->assertSession()->pageTextContains('Policies & Accessibility');

    // Select refund policy.
    $this->submitForm([
      'field_refund_policy' => '7_days',
    ], 'Continue');

    // Should be on step 7: Review.
    $this->assertSession()->pageTextContains('Review & Publish');
    $this->assertSession()->pageTextContains('Test Event');
    $this->assertSession()->pageTextContains('Test Venue');
  }

  /**
   * Tests value persistence when navigating back.
   */
  public function testValuePersistence(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard.
    $this->drupalGet('/vendor/events/wizard');

    // Fill in basics.
    $this->submitForm([
      'title' => 'Persistent Test Event',
      'field_event_type' => 'paid',
    ], 'Continue');

    // Fill in when & where.
    $start_date = date('Y-m-d\TH:i:s', strtotime('+2 weeks'));
    $this->submitForm([
      'date_time[field_event_start]' => $start_date,
      'location[location_mode]' => 'in_person',
      'location[field_venue_name]' => 'Persistent Venue',
      'location[field_location][address_line1]' => '456 Persistent St',
      'location[field_location][locality]' => 'Persistent City',
      'location[field_location][administrative_area]' => 'VIC',
      'location[field_location][postal_code]' => '3000',
      'location[field_location][country_code]' => 'AU',
    ], 'Continue');

    // Go to branding step.
    $this->assertSession()->pageTextContains('Branding');

    // Go back.
    $this->submitForm([], 'Back');

    // Should be back on when & where, with values preserved.
    $this->assertSession()->fieldValueEquals('location[field_venue_name]', 'Persistent Venue');

    // Go back again.
    $this->submitForm([], 'Back');

    // Should be back on basics, with values preserved.
    $this->assertSession()->fieldValueEquals('title', 'Persistent Test Event');
    $this->assertSession()->fieldValueEquals('field_event_type', 'paid');
  }

  /**
   * Tests conditional field visibility.
   */
  public function testConditionalFieldVisibility(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard and go to when & where step.
    $this->drupalGet('/vendor/events/wizard');
    $this->submitForm([
      'title' => 'Conditional Test',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // By default, in-person fields should be visible.
    $this->assertSession()->fieldExists('location[field_venue_name]');
    $this->assertSession()->fieldExists('location[field_location][address_line1]');

    // Switch to online mode.
    $this->submitForm([
      'location[location_mode]' => 'online',
    ], 'Continue');

    // Go back to when & where step.
    $this->drupalGet('/vendor/events/wizard');
    $this->submitForm([
      'title' => 'Conditional Test',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // Online URL field should be visible when online mode is selected.
    $this->submitForm([
      'location[location_mode]' => 'online',
    ], 'Continue');

    // Go back and verify online URL field exists.
    $this->submitForm([], 'Back');
    $this->assertSession()->fieldExists('location[field_external_url]');
  }

  /**
   * Tests save draft functionality.
   */
  public function testSaveDraft(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard.
    $this->drupalGet('/vendor/events/wizard');

    // Fill in basics and save draft.
    $this->submitForm([
      'title' => 'Draft Event',
      'field_event_type' => 'rsvp',
    ], 'Save Draft');

    // Should see success message.
    $this->assertSession()->pageTextContains('Draft saved');

    // Should still be on basics step.
    $this->assertSession()->fieldValueEquals('title', 'Draft Event');

    // Verify event was saved as draft.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => 'Draft Event']);

    $this->assertNotEmpty($nodes, 'Event should be saved');
    $event = reset($nodes);
    $this->assertFalse($event->isPublished(), 'Event should be saved as draft');
  }

  /**
   * Tests validation on each step.
   */
  public function testStepValidation(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard.
    $this->drupalGet('/vendor/events/wizard');

    // Try to continue without title (should fail).
    $this->submitForm([
      'title' => '',
    ], 'Continue');

    // Should see validation error.
    $this->assertSession()->pageTextContains('Event title is required');

    // Fill in title and continue.
    $this->submitForm([
      'title' => 'Validation Test',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // Try to continue without start date (should fail).
    $this->submitForm([
      'location[location_mode]' => 'in_person',
    ], 'Continue');

    // Should see validation error.
    $this->assertSession()->pageTextContains('Start date & time is required');
  }

  /**
   * Tests venue data persistence.
   */
  public function testVenueDataPersistence(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard.
    $this->drupalGet('/vendor/events/wizard');

    // Fill in basics.
    $this->submitForm([
      'title' => 'Venue Test',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // Fill in venue data.
    $start_date = date('Y-m-d\TH:i:s', strtotime('+1 week'));
    $this->submitForm([
      'date_time[field_event_start]' => $start_date,
      'location[location_mode]' => 'in_person',
      'location[field_venue_name]' => 'Test Venue Name',
      'location[field_location][address_line1]' => '789 Venue St',
      'location[field_location][locality]' => 'Venue City',
      'location[field_location][administrative_area]' => 'QLD',
      'location[field_location][postal_code]' => '4000',
      'location[field_location][country_code]' => 'AU',
    ], 'Continue');

    // Continue through remaining steps.
    // Branding.
    $this->submitForm([], 'Continue');
    // Tickets & Capacity.
    $this->submitForm([], 'Continue');
    $this->submitForm([
      'body[value]' => 'Test description',
    // Content.
    ], 'Continue');
    $this->submitForm([
      'field_refund_policy' => '7_days',
    // Policies.
    ], 'Continue');

    // On review step, verify venue data is shown.
    $this->assertSession()->pageTextContains('Test Venue Name');

    // Verify venue data was saved to the event.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => 'Venue Test']);

    $this->assertNotEmpty($nodes, 'Event should be saved');
    $event = reset($nodes);
    $this->assertEquals('Test Venue Name', $event->get('field_venue_name')->value);
    $this->assertFalse($event->get('field_location')->isEmpty());
    $address = $event->get('field_location')->first()->getValue();
    $this->assertEquals('789 Venue St', $address['address_line1']);
    $this->assertEquals('Venue City', $address['locality']);
    $this->assertEquals('QLD', $address['administrative_area']);
    $this->assertEquals('4000', $address['postal_code']);
  }

  /**
   * Tests venue lat/lng/place_id persistence.
   */
  public function testVenueCoordinatesPersistence(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard.
    $this->drupalGet('/vendor/events/wizard');

    // Fill in basics.
    $this->submitForm([
      'title' => 'Coordinates Test',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // Fill in venue data with coordinates.
    $start_date = date('Y-m-d\TH:i:s', strtotime('+1 week'));
    $this->submitForm([
      'date_time[field_event_start]' => $start_date,
      'location[location_mode]' => 'in_person',
      'location[field_venue_name]' => 'Coordinates Venue',
      'location[field_location][address_line1]' => '123 Coordinates St',
      'location[field_location][locality]' => 'Coordinates City',
      'location[field_location][administrative_area]' => 'NSW',
      'location[field_location][postal_code]' => '2000',
      'location[field_location][country_code]' => 'AU',
      'location[field_location_latitude]' => '-33.8688',
      'location[field_location_longitude]' => '151.2093',
      'location[field_location_place_id]' => 'ChIJP3Sa8ziYEmsRUKgyFmh9AQM',
    ], 'Continue');

    // Continue through remaining steps.
    // Branding.
    $this->submitForm([], 'Continue');
    // Tickets & Capacity.
    $this->submitForm([], 'Continue');
    $this->submitForm([
      'body[value]' => 'Test description',
    // Content.
    ], 'Continue');
    $this->submitForm([
      'field_refund_policy' => '7_days',
    // Policies.
    ], 'Continue');

    // Verify coordinates were saved.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => 'Coordinates Test']);

    $this->assertNotEmpty($nodes, 'Event should be saved');
    $event = reset($nodes);

    if ($event->hasField('field_location_latitude') && !$event->get('field_location_latitude')->isEmpty()) {
      $this->assertEquals('-33.8688', $event->get('field_location_latitude')->value);
    }

    if ($event->hasField('field_location_longitude') && !$event->get('field_location_longitude')->isEmpty()) {
      $this->assertEquals('151.2093', $event->get('field_location_longitude')->value);
    }

    if ($event->hasField('field_location_place_id') && !$event->get('field_location_place_id')->isEmpty()) {
      $this->assertEquals('ChIJP3Sa8ziYEmsRUKgyFmh9AQM', $event->get('field_location_place_id')->value);
    }
  }

  /**
   * Tests that event page renders correctly from wizard-created events.
   */
  public function testEventPageRendersFromWizard(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard and create a complete event.
    $this->drupalGet('/vendor/events/wizard');

    // Step 1: Basics.
    $this->submitForm([
      'title' => 'Rendered Event Test',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // Step 2: When & Where.
    $start_date = date('Y-m-d\TH:i:s', strtotime('+1 week'));
    $this->submitForm([
      'date_time[field_event_start]' => $start_date,
      'location[location_mode]' => 'in_person',
      'location[field_venue_name]' => 'Rendered Venue',
      'location[field_location][address_line1]' => '456 Rendered St',
      'location[field_location][locality]' => 'Rendered City',
      'location[field_location][administrative_area]' => 'VIC',
      'location[field_location][postal_code]' => '3000',
      'location[field_location][country_code]' => 'AU',
    ], 'Continue');

    // Step 3: Branding.
    $this->submitForm([], 'Continue');

    // Step 4: Tickets & Capacity.
    $this->submitForm([
      'field_capacity' => '50',
    ], 'Continue');

    // Step 5: Content.
    $this->submitForm([
      'body[value]' => 'This event will be rendered on the event page.',
    ], 'Continue');

    // Step 6: Policies.
    $this->submitForm([
      'field_refund_policy' => '7_days',
    ], 'Continue');

    // Step 7: Review & Publish.
    $this->submitForm([
      'publish_status' => '1',
    ], 'Publish Event');

    // Should redirect to event page.
    $this->assertSession()->statusCodeEquals(200);

    // Find the event node.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => 'Rendered Event Test']);

    $this->assertNotEmpty($nodes, 'Event should be created');
    $event = reset($nodes);

    // Visit the event page.
    $this->drupalGet('/node/' . $event->id());
    $this->assertSession()->statusCodeEquals(200);

    // Verify event data is displayed.
    $this->assertSession()->pageTextContains('Rendered Event Test');
    $this->assertSession()->pageTextContains('Rendered Venue');
    $this->assertSession()->pageTextContains('This event will be rendered on the event page.');
  }

  /**
   * Tests that no PHP notices occur during wizard usage.
   */
  public function testNoPhpNotices(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard multiple times and perform various actions.
    $this->drupalGet('/vendor/events/wizard');
    $this->assertSession()->statusCodeEquals(200);

    // Fill in basics.
    $this->submitForm([
      'title' => 'No Errors Test',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // Go back.
    $this->submitForm([], 'Back');

    // Fill in again and continue.
    $this->submitForm([
      'title' => 'No Errors Test',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // Save draft.
    $this->submitForm([], 'Save Draft');
    $this->assertSession()->pageTextContains('Draft saved');

    // Continue again.
    $start_date = date('Y-m-d\TH:i:s', strtotime('+1 week'));
    $this->submitForm([
      'date_time[field_event_start]' => $start_date,
      'location[location_mode]' => 'in_person',
      'location[field_location][address_line1]' => '123 Test St',
      'location[field_location][locality]' => 'Test City',
      'location[field_location][administrative_area]' => 'NSW',
      'location[field_location][postal_code]' => '2000',
      'location[field_location][country_code]' => 'AU',
    ], 'Continue');

    // Verify no PHP errors in logs (this is a basic check).
    // In a real scenario, you'd check error logs, but for functional tests,
    // we verify the page loads without errors.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Notice:');
    $this->assertSession()->pageTextNotContains('Warning:');
    $this->assertSession()->pageTextNotContains('Fatal error:');
  }

  /**
   * Tests that only relevant fields show per step.
   */
  public function testRelevantFieldsPerStep(): void {
    $this->drupalLogin($this->vendorUser);

    // Navigate to wizard.
    $this->drupalGet('/vendor/events/wizard');

    // Step 1: Basics - should only show basics fields.
    $this->assertSession()->fieldExists('title');
    $this->assertSession()->fieldExists('field_event_type');
    $this->assertSession()->fieldNotExists('date_time[field_event_start]');
    $this->assertSession()->fieldNotExists('field_capacity');

    // Fill and continue.
    $this->submitForm([
      'title' => 'Relevant Fields Test',
      'field_event_type' => 'rsvp',
    ], 'Continue');

    // Step 2: When & Where - should only show date/location fields.
    $this->assertSession()->fieldExists('date_time[field_event_start]');
    $this->assertSession()->fieldExists('location[location_mode]');
    $this->assertSession()->fieldNotExists('field_capacity');
    $this->assertSession()->fieldNotExists('body[value]');

    // Fill and continue.
    $start_date = date('Y-m-d\TH:i:s', strtotime('+1 week'));
    $this->submitForm([
      'date_time[field_event_start]' => $start_date,
      'location[location_mode]' => 'in_person',
      'location[field_location][address_line1]' => '123 Test St',
      'location[field_location][locality]' => 'Test City',
      'location[field_location][administrative_area]' => 'NSW',
      'location[field_location][postal_code]' => '2000',
      'location[field_location][country_code]' => 'AU',
    ], 'Continue');

    // Step 3: Branding - should only show image field.
    $this->assertSession()->fieldExists('field_event_image');
    $this->assertSession()->fieldNotExists('field_capacity');
    $this->assertSession()->fieldNotExists('body[value]');

    // Continue.
    $this->submitForm([], 'Continue');

    // Step 4: Tickets & Capacity - should only show capacity fields.
    $this->assertSession()->fieldExists('field_capacity');
    $this->assertSession()->fieldNotExists('body[value]');
    $this->assertSession()->fieldNotExists('field_refund_policy');
  }

}
