<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Functional;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;

/**
 * Functional test: RSVP submit → dashboard count increments.
 *
 * @group myeventlane_rsvp
 */
final class RsvpDashboardCountTest extends BrowserTestBase {

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
    'datetime',
    'myeventlane_schema',
    'myeventlane_event',
    'myeventlane_vendor',
    'myeventlane_rsvp',
  ];

  /**
   * Test vendor user.
   */
  private User $vendorUser;

  /**
   * Test event node.
   */
  private Node $event;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create vendor user.
    $this->vendorUser = $this->drupalCreateUser([
      'create event content',
      'edit own event content',
      'access vendor dashboard',
    ]);

    // Create an event.
    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Test Event for RSVP',
      'uid' => $this->vendorUser->id(),
      'status' => 1,
      'field_event_type' => 'rsvp',
    ]);
    $this->event->save();
  }

  /**
   * Tests that RSVP submission increments dashboard count.
   */
  public function testRsvpIncrementsDashboardCount(): void {
    // Login as vendor.
    $this->drupalLogin($this->vendorUser);

    // Get initial RSVP count from dashboard.
    $this->drupalGet('/vendor/dashboard');
    $this->assertSession()->statusCodeEquals(200);

    // Count RSVPs in database before submission.
    $database = \Drupal::database();
    $initialCount = (int) $database->select('myeventlane_rsvp', 'r')
      ->condition('event_nid', $this->event->id())
      ->countQuery()
      ->execute()
      ->fetchField();

    // Submit RSVP as anonymous user.
    $this->drupalLogout();
    $this->drupalGet('/node/' . $this->event->id());
    $this->assertSession()->statusCodeEquals(200);

    // Find and submit RSVP form.
    $this->submitForm([
      'name' => 'Test User',
      'email' => 'test@example.com',
      'guests' => 1,
    ], 'RSVP Now');

    // Verify RSVP was created.
    $newCount = (int) $database->select('myeventlane_rsvp', 'r')
      ->condition('event_nid', $this->event->id())
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals($initialCount + 1, $newCount, 'RSVP count should increment by 1');

    // Login as vendor and check dashboard.
    $this->drupalLogin($this->vendorUser);
    $this->drupalGet('/vendor/dashboard');
    $this->assertSession()->statusCodeEquals(200);

    // Verify dashboard shows updated count.
    // The dashboard should display the RSVP count in the events table.
    $this->assertSession()->pageTextContains((string) $newCount);
  }

}
