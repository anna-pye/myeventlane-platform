<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Functional;

use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;

/**
 * Functional test: Vendor A cannot view/export Vendor B event RSVPs.
 *
 * @group myeventlane_rsvp
 */
final class VendorReportingAccessTest extends BrowserTestBase {

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
    'system',
    'myeventlane_schema',
    'myeventlane_event',
    'myeventlane_vendor',
    'myeventlane_rsvp',
  ];

  /**
   * Vendor A (owns Event A).
   */
  private User $vendorA;

  /**
   * Vendor B (owns Event B).
   */
  private User $vendorB;

  /**
   * Event A.
   */
  private Node $eventA;

  /**
   * Event B.
   */
  private Node $eventB;

  /**
   * Admin user.
   */
  private User $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->vendorA = $this->drupalCreateUser([
      'manage own event rsvps',
      'access content',
    ]);
    $this->vendorB = $this->drupalCreateUser([
      'manage own event rsvps',
      'access content',
    ]);
    $this->admin = $this->drupalCreateUser([
      'administer rsvps',
      'access content',
    ]);

    $this->eventA = Node::create([
      'type' => 'event',
      'title' => 'Event A (Vendor A)',
      'uid' => $this->vendorA->id(),
      'status' => 1,
    ]);
    $this->eventA->save();

    $this->eventB = Node::create([
      'type' => 'event',
      'title' => 'Event B (Vendor B)',
      'uid' => $this->vendorB->id(),
      'status' => 1,
    ]);
    $this->eventB->save();
  }

  /**
   * Vendor A cannot view Vendor B event RSVP list.
   */
  public function testVendorACannotAccessVendorBEventRsvps(): void {
    $this->drupalLogin($this->vendorA);
    $url = '/vendor/event/' . $this->eventB->id() . '/rsvps';
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Vendor A can view own event RSVP list.
   */
  public function testVendorACanAccessOwnEventRsvps(): void {
    $this->drupalLogin($this->vendorA);
    $url = '/vendor/event/' . $this->eventA->id() . '/rsvps';
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Vendor A cannot export Vendor B event RSVPs.
   */
  public function testVendorACannotExportVendorBEventRsvps(): void {
    $this->drupalLogin($this->vendorA);
    $url = '/vendor/event/' . $this->eventB->id() . '/rsvps/export';
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Admin can access any event RSVP list.
   */
  public function testAdminCanAccessAnyEventRsvps(): void {
    $this->drupalLogin($this->admin);
    $this->drupalGet('/vendor/event/' . $this->eventA->id() . '/rsvps');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('/vendor/event/' . $this->eventB->id() . '/rsvps');
    $this->assertSession()->statusCodeEquals(200);
  }

}
