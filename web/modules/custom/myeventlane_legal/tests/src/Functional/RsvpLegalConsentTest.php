<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_legal\Functional;

use Drupal\node\Entity\Node;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Functional test: RSVP form with legal consent checkbox.
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

  private User $vendorUser;

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
   * Tests RSVP form submits successfully with legal checkbox checked.
   */
  public function testRsvpWithLegalConsentSucceeds(): void {
    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->fieldExists('legal_consent[customer_terms_agreed]');

    $edit = [
      'name' => 'Test User',
      'email' => 'test-legal@example.com',
      'guests' => 1,
      'legal_consent[customer_terms_agreed]' => 1,
    ];

    $this->submitForm($edit, 'Reserve');

    $this->assertSession()->pageTextNotContains('You must agree to the Terms of Service and Privacy Policy');

    $this->assertSession()->pageTextContains('Reserved');
  }

  /**
   * Tests policy version and acceptance timestamps update when config versions change.
   */
  public function testRsvpConsentVersionUpdatesOnResubmit(): void {
    $settings = \Drupal::configFactory()->getEditable('myeventlane_legal.settings');
    $settings->set('customer_terms_version', 'v1-functional')->set('privacy_version', 'v1-functional')->save();

    $email = 'version-resubmit@example.com';
    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->submitForm([
      'name' => 'Version User',
      'email' => $email,
      'guests' => 1,
      'legal_consent[customer_terms_agreed]' => 1,
    ], 'Reserve');

    $submission = $this->loadRsvpByEmail($email);
    $this->assertInstanceOf(RsvpSubmissionInterface::class, $submission);
    $this->assertSame('v1-functional', $submission->get('field_customer_terms_version')->value);
    $this->assertSame('v1-functional', $submission->get('field_privacy_version')->value);
    $termsTsFirst = (int) $submission->get('field_customer_terms_accepted_at')->value;

    $settings->set('customer_terms_version', 'v2-functional')->set('privacy_version', 'v2-functional')->save();

    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->submitForm([
      'name' => 'Version User',
      'email' => $email,
      'guests' => 1,
      'legal_consent[customer_terms_agreed]' => 1,
    ], 'Reserve');

    $submission = $this->loadRsvpByEmail($email);
    $this->assertInstanceOf(RsvpSubmissionInterface::class, $submission);
    $this->assertSame('v2-functional', $submission->get('field_customer_terms_version')->value);
    $this->assertSame('v2-functional', $submission->get('field_privacy_version')->value);
    $termsTsSecond = (int) $submission->get('field_customer_terms_accepted_at')->value;
    $this->assertGreaterThan($termsTsFirst, $termsTsSecond);
  }

  /**
   * Loads the RSVP submission for this event and email.
   */
  private function loadRsvpByEmail(string $email): ?RsvpSubmissionInterface {
    $ids = \Drupal::entityQuery('rsvp_submission')
      ->accessCheck(FALSE)
      ->condition('event_id', $this->event->id())
      ->condition('email', $email)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $entity = \Drupal::entityTypeManager()->getStorage('rsvp_submission')->load(reset($ids));
    return $entity instanceof RsvpSubmissionInterface ? $entity : NULL;
  }

}
