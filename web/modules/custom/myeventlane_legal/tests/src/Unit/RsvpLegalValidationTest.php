<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_legal\Unit;

use Drupal\Core\Form\FormState;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Drupal\myeventlane_legal\Service\RsvpLegalAlter;
use Drupal\myeventlane_legal\Service\RsvpLegalConsentHelper;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit test: RSVP legal consent validation logic.
 *
 * @group myeventlane_legal
 */
final class RsvpLegalValidationTest extends UnitTestCase {

  /**
   * Tests validation passes when the combined terms checkbox is checked.
   */
  public function testValidationPassesWhenTermsChecked(): void {
    $alter = $this->createAlterService();

    $form = [
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset($this->createMockSettings()),
    ];

    $form_state = new FormState();
    $form_state->setValues([
      'legal_consent' => [
        'customer_terms_agreed' => 1,
        'marketing_opt_in' => 0,
      ],
    ]);

    $alter->validateRsvpLegal($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
  }

  /**
   * Tests validation passes when checkbox uses string "1" (form submission).
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
        'marketing_opt_in' => '',
      ],
    ]);

    $alter->validateRsvpLegal($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
  }

  /**
   * Tests validation passes when form_state has consent but element #value is stale.
   *
   * Regression: isTermsCheckboxAccepted must not trust render #value alone after
   * normalizeLegalConsentFromRequest() updates values from POST.
   */
  public function testValidationPassesWhenFormStateValueOverridesStaleElementValue(): void {
    $alter = $this->createAlterService();

    $form = [
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset($this->createMockSettings()),
    ];
    $form['legal_consent']['customer_terms_agreed']['#value'] = 0;

    $form_state = new FormState();
    $form_state->setValues([
      'legal_consent' => [
        'customer_terms_agreed' => 1,
        'marketing_opt_in' => 0,
      ],
    ]);

    $alter->validateRsvpLegal($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
  }

  /**
   * Tests validation when POST uses attendee_terms_agreed (alternate key).
   */
  public function testValidationPassesWhenPostUsesAttendeeTermsAgreedKey(): void {
    $request = Request::create('/event/1/book', 'POST', [
      'legal_consent' => [
        'attendee_terms_agreed' => 1,
      ],
    ]);
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $alter = new RsvpLegalAlter($this->createMockSettings(), $requestStack);

    $form = [
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset($this->createMockSettings()),
    ];

    $form_state = new FormState();
    $form_state->setValues([
      'legal_consent' => [
        'customer_terms_agreed' => 0,
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
    $alter = $this->createAlterService();

    $form = [
      'legal_consent' => RsvpLegalConsentHelper::buildFieldset($this->createMockSettings()),
    ];

    $form_state = new FormState();
    $form_state->setValues([
      'legal_consent' => [
        'customer_terms_agreed' => 0,
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
    $requestStack = $this->createMock(RequestStack::class);
    return new RsvpLegalAlter($this->createMockSettings(), $requestStack);
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

    $logger = $this->createMock(\Drupal\Core\Logger\LoggerChannelInterface::class);
    $logger->expects($this->any())->method('warning');
    $loggerFactory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new LegalSettingsService(
      $config,
      $loggerFactory,
      $this->createMock(\Drupal\Component\Datetime\TimeInterface::class)
    );
  }

}
