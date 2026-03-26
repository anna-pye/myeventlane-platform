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

    $this->submitForm($edit, 'RSVP');

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
    ], 'RSVP');

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
    ], 'RSVP');

    $submission = $this->loadRsvpByEmail($email);
    $this->assertInstanceOf(RsvpSubmissionInterface::class, $submission);
    $this->assertSame('v2-functional', $submission->get('field_customer_terms_version')->value);
    $this->assertSame('v2-functional', $submission->get('field_privacy_version')->value);
    $termsTsSecond = (int) $submission->get('field_customer_terms_accepted_at')->value;
    $this->assertGreaterThan($termsTsFirst, $termsTsSecond);
  }

  /**
   * Tests first-time RSVP: no prior consent, no forced re-consent message.
   */
  public function testFirstTimeRsvpNoReconsentMessage(): void {
    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('updated since your last RSVP');
  }

  /**
   * Tests prior consent with same versions: no forced re-consent, submit succeeds.
   */
  public function testPriorConsentSameVersionNoReconsent(): void {
    $settings = \Drupal::configFactory()->getEditable('myeventlane_legal.settings');
    $settings->set('customer_terms_version', 'v1')->set('privacy_version', 'v1')->save();

    $email = 'same-version@example.com';
    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->submitForm([
      'name' => 'Same Version User',
      'email' => $email,
      'guests' => 1,
      'legal_consent[customer_terms_agreed]' => 1,
    ], 'RSVP');

    $this->assertSession()->pageTextContains('Reserved');

    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->assertSession()->pageTextNotContains('updated since your last RSVP');

    $this->submitForm([
      'name' => 'Same Version User',
      'email' => $email,
      'guests' => 2,
      'legal_consent[customer_terms_agreed]' => 1,
    ], 'RSVP');

    $this->assertSession()->pageTextContains('Reserved');
  }

  /**
   * Tests prior consent with updated terms: forced re-consent, blocked if unchecked.
   */
  public function testReconsentRequiredWhenTermsUpdated(): void {
    $settings = \Drupal::configFactory()->getEditable('myeventlane_legal.settings');
    $settings->set('customer_terms_version', 'v1-reconsent')->set('privacy_version', 'v1-reconsent')->save();

    $email = 'reconsent-terms@example.com';
    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->submitForm([
      'name' => 'Reconsent User',
      'email' => $email,
      'guests' => 1,
      'legal_consent[customer_terms_agreed]' => 1,
    ], 'RSVP');

    $this->assertSession()->pageTextContains('Reserved');

    $settings->set('customer_terms_version', 'v2-reconsent')->save();

    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->assertSession()->pageTextContains('updated since your last RSVP');

    $this->submitForm([
      'name' => 'Reconsent User',
      'email' => $email,
      'guests' => 1,
      'legal_consent[customer_terms_agreed]' => 0,
    ], 'RSVP');

    $this->assertSession()->pageTextContains('You must agree to the updated Terms of Service to continue');
    $this->assertSession()->pageTextNotContains('Reserved');
  }

  /**
   * Tests prior consent with updated terms: submit succeeds when checkbox checked.
   */
  public function testReconsentSucceedsWhenChecked(): void {
    $settings = \Drupal::configFactory()->getEditable('myeventlane_legal.settings');
    $settings->set('customer_terms_version', 'v1-succeed')->set('privacy_version', 'v1-succeed')->save();

    $email = 'reconsent-succeed@example.com';
    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->submitForm([
      'name' => 'Reconsent Succeed User',
      'email' => $email,
      'guests' => 1,
      'legal_consent[customer_terms_agreed]' => 1,
    ], 'RSVP');

    $settings->set('customer_terms_version', 'v2-succeed')->save();

    $this->drupalGet('/event/' . $this->event->id() . '/rsvp/form');
    $this->submitForm([
      'name' => 'Reconsent Succeed User',
      'email' => $email,
      'guests' => 1,
      'legal_consent[customer_terms_agreed]' => 1,
    ], 'RSVP');

    $this->assertSession()->pageTextContains('Reserved');
    $submission = $this->loadRsvpByEmail($email);
    $this->assertSame('v2-succeed', $submission->get('field_customer_terms_version')->value);
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
