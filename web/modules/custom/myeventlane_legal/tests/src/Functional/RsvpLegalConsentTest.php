<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_legal\Functional;

use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Functional test: RSVP form with legal consent checkboxes.
 *
 * Verifies that submitting the RSVP form with both legal checkboxes checked
 * passes validation and succeeds (no spurious validation errors).
 *
 * @group myeventlane_legal
 */
#[RunTestsInSeparateProcesses]
#[Group('myeventlane_legal')]
final class RsvpLegalConsentTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * Minimal modules; myeventlane_legal depends on myeventlane_core.
   * Using Standard profile modules to satisfy config dependencies.
   */
  protected static $modules = [
    'node',
    'user',
    'field',
    'text',
    'datetime',
    'system',
    'myeventlane_schema',
    'myeventlane_event',
    'myeventlane_core',
    'myeventlane_vendor',
    'myeventlane_rsvp',
    'myeventlane_legal',
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

    $this->vendorUser = $this->drupalCreateUser([
      'create event content',
      'edit own event content',
    ]);

    $this->event = Node::create([
      'type' => 'event',
      'title' => 'Test Event for RSVP Legal',
      'uid' => $this->vendorUser->id(),
      'status' => 1,
      'field_event_type' => 'rsvp',
    ]);
    $this->event->save();
  }

  /**
   * Tests RSVP form submits successfully with legal checkboxes checked.
   */
  public function testRsvpWithLegalConsentSucceeds(): void {
    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->assertSession()->statusCodeEquals(200);

    // Assert legal consent fields are present.
    $this->assertSession()->fieldExists('legal_consent[customer_terms_agreed]');
    $this->assertSession()->fieldExists('legal_consent[privacy_agreed]');

    // Submit with both legal checkboxes checked.
    $edit = [
      'name' => 'Test User',
      'email' => 'test-legal@example.com',
      'guests' => 1,
      'legal_consent[customer_terms_agreed]' => 1,
      'legal_consent[privacy_agreed]' => 1,
    ];

    $this->submitForm($edit, 'Reserve');

    // No validation errors.
    $this->assertSession()->pageTextNotContains('You must agree to the Terms of Service');
    $this->assertSession()->pageTextNotContains('You must confirm you have read the Privacy Policy');

    // RSVP succeeds: thank-you page or success message.
    $this->assertSession()->pageTextContains('Reserved');
  }

}
