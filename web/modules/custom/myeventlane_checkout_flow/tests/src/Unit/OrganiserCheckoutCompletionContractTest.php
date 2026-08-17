<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects organiser checkout completion from attendee ticket language.
 *
 * @group myeventlane_checkout_flow
 */
final class OrganiserCheckoutCompletionContractTest extends TestCase {

  public function testCompletionUsesOrganiserBranch(): void {
    $root = dirname(__DIR__, 7);
    $template = file_get_contents($root . '/web/themes/custom/myeventlane_theme/templates/commerce/commerce-checkout-completion.html.twig');
    $preprocess = file_get_contents($root . '/web/themes/custom/myeventlane_theme/myeventlane_theme.theme');
    self::assertIsString($template);
    self::assertIsString($preprocess);

    self::assertStringContainsString('mel_organiser_completion', $template);
    self::assertStringContainsString('organiser.purchase_details.rows', $template);
    self::assertStringContainsString('organiser_complete.primary_url', $template);
    self::assertStringContainsString("['mel_confirmation_has_tickets'] = FALSE", $preprocess);
    self::assertStringContainsString("['mel_calendar_links'] = []", $preprocess);
    self::assertStringContainsString("['booking_summary_heading']", $preprocess);
  }

  public function testOrganiserCompletionCopyContainsNoAttendeeActions(): void {
    $service = file_get_contents(dirname(__DIR__, 3) . '/src/Service/OrganiserCheckoutContext.php');
    self::assertIsString($service);

    self::assertStringContainsString('Your Pro trial has started', $service);
    self::assertStringContainsString('Your event Boost is confirmed', $service);
    self::assertStringContainsString('Return to Organiser Studio', $service);
    self::assertStringNotContainsString('View Digital Pass', $service);
    self::assertStringNotContainsString('Browse more events', $service);
    self::assertStringNotContainsString("You're going", $service);
  }

}
