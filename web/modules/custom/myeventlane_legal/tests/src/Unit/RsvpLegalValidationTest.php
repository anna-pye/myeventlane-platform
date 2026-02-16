<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_legal\Unit;

use Drupal\Core\Form\FormState;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Drupal\myeventlane_legal\Service\RsvpLegalAlter;
use Drupal\myeventlane_legal\Service\RsvpLegalConsentHelper;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit test: RSVP legal consent validation logic.
 *
 * Verifies that validation accepts checkbox value 1 (not strict TRUE) and
 * uses the correct nested value path (getValue(['legal_consent', 'key'])).
 *
 * @group myeventlane_legal
 */
final class RsvpLegalValidationTest extends UnitTestCase {

  /**
   * Tests validation passes when both checkboxes are checked (value 1).
   */
  public function testValidationPassesWhenBothCheckboxesChecked(): void {
    $alter = $this->createAlterService();

    $form = [
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset($this->createMockSettings()),
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
   * Tests validation passes when checkboxes use string "1" (form submission).
   */
  public function testValidationPassesWithStringOne(): void {
    $alter = $this->createAlterService();

    $form = [
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset($this->createMockSettings()),
    ];

    $form_state = new FormState();
    $form_state->setValues([
      'legal_consent' => [
        'customer_terms_agreed' => '1',
        'privacy_agreed' => '1',
        'marketing_opt_in' => '',
      ],
    ]);

    $alter->validateRsvpLegal($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
  }

  /**
   * Tests validation fails when terms checkbox is unchecked.
   */
  public function testValidationFailsWhenTermsUnchecked(): void {
    $alter = $this->createAlterService();

    $form = [
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset($this->createMockSettings()),
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
    $alter = $this->createAlterService();

    $form = [
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset($this->createMockSettings()),
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

  /**
   * Creates RsvpLegalAlter with mocked dependencies.
   */
  private function createAlterService(): RsvpLegalAlter {
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    return new RsvpLegalAlter($this->createMockSettings(), $time);
  }

  /**
   * Creates a minimal LegalSettingsService mock.
   */
  private function createMockSettings(): LegalSettingsService {
    $config = $this->getConfigFactoryStub([
      'myeventlane_legal.settings' => [
        'customer_terms_url' => '',
        'privacy_url' => '',
        'collection_notice_rsvp' => '',
        'customer_terms_version' => '1',
        'privacy_version' => '1',
      ],
    ]);

    return new LegalSettingsService(
      $config,
      $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class),
      $this->createMock(\Drupal\Component\Datetime\TimeInterface::class)
    );
  }

}
