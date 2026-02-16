<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_legal\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_legal\Service\RsvpLegalAlter;
use Drupal\myeventlane_legal\Service\RsvpLegalConsentHelper;

/**
 * Kernel test: RSVP legal consent validation.
 *
 * Verifies that validation accepts checkbox value 1 (not strict TRUE) and
 * uses the correct nested value path.
 *
 * @group myeventlane_legal
 */
final class RsvpLegalConsentValidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'myeventlane_core',
    'myeventlane_legal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['myeventlane_legal']);
  }

  /**
   * Tests validation passes when both checkboxes are checked (value 1).
   */
  public function testValidationPassesWhenBothCheckboxesChecked(): void {
    $alter = $this->container->get('myeventlane_legal.rsvp_alter');

    $form = [
      '#tree' => FALSE,
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset(
        $this->container->get('myeventlane_legal.settings')
      ),
    ];

    $form_state = new FormState();
    $form_state->setValues([
      'legal_consent' => [
        'customer_terms_agreed' => 1,
        'privacy_agreed' => 1,
        'marketing_opt_in' => 0,
      ],
    ]);

    $alter->validateRsvpLegal($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
  }

  /**
   * Tests validation fails when terms checkbox is unchecked.
   */
  public function testValidationFailsWhenTermsUnchecked(): void {
    $alter = $this->container->get('myeventlane_legal.rsvp_alter');

    $form = [
      '#tree' => FALSE,
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset(
        $this->container->get('myeventlane_legal.settings')
      ),
    ];

    $form_state = new FormState();
    $form_state->setValues([
      'legal_consent' => [
        'customer_terms_agreed' => 0,
        'privacy_agreed' => 1,
        'marketing_opt_in' => 0,
      ],
    ]);

    $alter->validateRsvpLegal($form, $form_state);

    $this->assertTrue($form_state->hasAnyErrors());
  }

  /**
   * Tests validation fails when privacy checkbox is unchecked.
   */
  public function testValidationFailsWhenPrivacyUnchecked(): void {
    $alter = $this->container->get('myeventlane_legal.rsvp_alter');

    $form = [
      '#tree' => FALSE,
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset(
        $this->container->get('myeventlane_legal.settings')
      ),
    ];

    $form_state = new FormState();
    $form_state->setValues([
      'legal_consent' => [
        'customer_terms_agreed' => 1,
        'privacy_agreed' => 0,
        'marketing_opt_in' => 0,
      ],
    ]);

    $alter->validateRsvpLegal($form, $form_state);

    $this->assertTrue($form_state->hasAnyErrors());
  }

}
