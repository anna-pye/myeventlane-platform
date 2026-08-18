<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Contract tests for paid publish Stripe gate alignment in Event Studio.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioStripePublishGateAlignmentTest extends UnitTestCase {

  public function testPreprocessUsesPaidPublishStripeGateForPaidEvents(): void {
    $preprocess = file_get_contents(dirname(__DIR__, 3) . '/src/EventStudioPreprocess.php');
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_event_studio.services.yml');

    $this->assertIsString($preprocess);
    $this->assertIsString($services);
    $this->assertStringContainsString('PaidPublishStripeGate', $preprocess);
    $this->assertStringContainsString('validatePaidPublishAllowed', $preprocess);
    $this->assertStringContainsString('validateTaxDeclarationAllowed', $preprocess);
    $this->assertStringContainsString('mel_publish_tax_gate', $preprocess);
    $this->assertStringNotContainsString("empty(\$flags['stripe_connected'])", $preprocess);
    $this->assertStringContainsString("'@myeventlane_vendor.paid_publish_stripe_gate'", $services);
  }

  public function testReadinessAndEnforcementPathsUsePaidPublishStripeGate(): void {
    $readiness = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventReadinessService.php');
    $evaluator = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PublishEligibilityEvaluator.php');
    $save = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSaveService.php');
    $publish = file_get_contents(dirname(__DIR__, 3) . '/src/Controller/EventStudioPublishController.php');

    $this->assertIsString($readiness);
    $this->assertIsString($evaluator);
    $this->assertIsString($save);
    $this->assertIsString($publish);
    $this->assertStringContainsString('validatePaidPublishAllowed', $readiness);
    $this->assertStringContainsString("\$accepts_payments = in_array(\$event_type, ['paid', 'both'], TRUE)", $readiness);
    $this->assertStringContainsString("hasField('field_enable_donations')", $readiness);
    $this->assertStringContainsString('validatePaidPublishAllowed', $evaluator);
    $this->assertStringContainsString('publishEligibilityEvaluator', $save);
    $this->assertStringNotContainsString('validatePaidPublishAllowed', $save);
    $this->assertStringNotContainsString('if (!$readiness->ready)', $publish);
  }

  public function testPublishCardUsesStripeGateCopyAndConnectRoute(): void {
    $twig = file_get_contents(dirname(__DIR__, 3) . '/templates/mel-event-studio.html.twig');
    $preprocess = file_get_contents(dirname(__DIR__, 3) . '/src/EventStudioPreprocess.php');
    $gate = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_vendor/src/Service/PaidPublishStripeGate.php');

    $this->assertIsString($twig);
    $this->assertIsString($preprocess);
    $this->assertIsString($gate);
    $this->assertStringContainsString('Finish payment setup before publishing', $twig);
    $this->assertStringContainsString('Confirm your legal and tax details', $twig);
    $this->assertStringContainsString("myeventlane_vendor.console.settings_profile", $twig);
    $this->assertStringContainsString('To accept event payments, finish your Stripe setup so payouts can reach your bank.', $twig);
    $this->assertStringContainsString('Resume Stripe setup', $twig);
    $this->assertStringContainsString('mel_stripe_connect_url', $twig);
    $this->assertStringContainsString('myeventlane_vendor.stripe_connect', $preprocess);
    $this->assertStringContainsString('To accept event payments, finish your Stripe setup so payouts can reach your bank.', $gate);
    $this->assertStringContainsString("in_array(\$event_type, ['paid', 'both'], TRUE)", $preprocess);
    $this->assertStringContainsString("\$accepts_donations", $preprocess);
  }

  public function testDraftSaveSkipsPublishEligibilityOnDraftPath(): void {
    $save = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventStudioSaveService.php');

    $this->assertIsString($save);
    $this->assertStringContainsString('if (!$draft && $willPublish)', $save);
    $this->assertStringContainsString('publishEligibilityEvaluator->evaluate', $save);
  }

}
