<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the mandatory organiser journey and paid-only Stripe boundary.
 */
final class OrganiserOnboardingContractTest extends TestCase {

  private string $root;

  protected function setUp(): void {
    parent::setUp();
    $this->root = dirname(__DIR__, 6);
  }

  public function testCompletedOrganisersLeaveOnboarding(): void {
    $routing = $this->read('myeventlane_vendor/myeventlane_vendor.routing.yml');
    $controller = $this->read('myeventlane_vendor/src/Controller/VendorOnboardProfileController.php');

    self::assertStringContainsString('VendorOnboardProfileController::profile', $routing);
    self::assertStringNotContainsString("_form: '\\Drupal\\myeventlane_vendor\\Form\\VendorOnboardProfileForm'", $routing);
    self::assertStringContainsString('isCompleted($state)', $controller);
    self::assertStringContainsString("myeventlane_vendor.create_event_gateway", $controller);
  }

  public function testVendorEntityIsTermsSourceOfTruth(): void {
    $form = $this->read('myeventlane_vendor/src/Form/VendorOnboardProfileForm.php');
    $gatekeeper = $this->read('myeventlane_legal/src/Service/LegalGatekeeper.php');

    self::assertStringContainsString('getVendorTermsAcceptedAt()', $gatekeeper);
    self::assertStringContainsString('$accepted_at = $this->legalGatekeeper->getVendorTermsAcceptedAt();', $form);
    self::assertStringContainsString("'#total_steps'] = 3", $form);
    self::assertStringContainsString('getVendorTermsUrl()', $form);
    self::assertStringContainsString('?? (int) $this->time->getRequestTime()', $form);
  }

  public function testTaxDeclarationIsMandatoryDuringOnboarding(): void {
    $form = $this->read('myeventlane_vendor/src/Form/VendorOnboardProfileForm.php');

    self::assertStringContainsString("['step_content']['tax']['entity_type']", $form);
    self::assertStringContainsString("['step_content']['tax']['gst_status']", $form);
    self::assertStringContainsString("['step_content']['tax']['declaration']", $form);
    self::assertStringContainsString("'#required' => TRUE", $form);
    self::assertStringContainsString("'field_tax_declaration_at'", $form);
    self::assertStringContainsString('isValidAbn', $form);
    self::assertStringContainsString('currently registered for GST with the Australian Taxation Office (ATO)', $form);
    self::assertStringContainsString('“Registered from” date shown for Goods & Services Tax on ABN Lookup', $form);
    self::assertStringContainsString('match the Australian Business Register', $form);
  }

  public function testStripeIsRequiredOnlyByPaidEventChecks(): void {
    $vendorGate = $this->read('myeventlane_vendor/src/Service/VendorPublishRequirementsGate.php');
    $eligibility = $this->read('myeventlane_event_studio/src/Service/PublishEligibilityEvaluator.php');
    $readiness = $this->read('myeventlane_event_studio/src/Service/EventReadinessService.php');

    self::assertStringNotContainsString('Connect Stripe before publishing your event.', $vendorGate);
    self::assertStringContainsString("in_array(\$eventType, ['paid', 'both'], TRUE)", $eligibility);
    self::assertStringContainsString("in_array(\$event_type, ['paid', 'both'], TRUE)", $readiness);
  }

  public function testNavigationShowsThreeMandatorySteps(): void {
    $theme = file_get_contents($this->root . '/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme');
    self::assertIsString($theme);

    self::assertStringContainsString("\$display_order = ['present', 'ask', 'complete'];", $theme);
    self::assertStringContainsString("'present' => t('Organiser details')", $theme);
    self::assertStringNotContainsString("\$display_order = ['present', 'ask', 'listen', 'complete'];", $theme);

    $template = file_get_contents($this->root . '/themes/custom/myeventlane_vendor_theme/templates/form/form--organiser-onboard-profile-form.html.twig');
    self::assertIsString($template);
    self::assertStringContainsString('<h1>{{ step_title }}</h1>', $template);
  }

  private function read(string $relativePath): string {
    $contents = file_get_contents($this->root . '/modules/custom/' . $relativePath);
    self::assertIsString($contents);
    return $contents;
  }

}
