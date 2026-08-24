<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Protects the structure and actions in customer-facing refund emails.
 */
#[CoversNothing]
#[Group('myeventlane_refunds')]
final class RefundEmailExperienceContractTest extends TestCase {

  /**
   * The first refund email slice uses the shared mobile-safe content pattern.
   */
  public function testRefundTemplatesUseStructuredEmailContent(): void {
    foreach ([
      'refund_requested_buyer',
      'refund_requested_vendor',
      'refund_completed_buyer',
    ] as $id) {
      $template = $this->syncTemplate($id);
      $body = (string) $template['body_html'];

      self::assertNotEmpty($template['preheader'] ?? '', $id);
      self::assertStringContainsString('<h1', $body, $id);
      self::assertStringContainsString('role="presentation"', $body, $id);
      self::assertMatchesRegularExpression('/<a[^>]+style="[^"]+"/i', $body, $id);
      self::assertStringContainsString('background:#c24737', $body, $id);
    }
  }

  /**
   * Buyer and organiser messages lead to the correct next action.
   */
  public function testRefundRequestActionsUsePurposeSpecificRoutes(): void {
    $buyer = (string) $this->syncTemplate('refund_requested_buyer')['body_html'];
    $organiser = (string) $this->syncTemplate('refund_requested_vendor')['body_html'];

    self::assertStringContainsString('View booking details', $buyer);
    self::assertStringNotContainsString('View refund request', $buyer);
    self::assertStringContainsString('{{ my_tickets_url }}', $buyer);
    self::assertStringContainsString('Review refund request', $organiser);
    self::assertStringContainsString('{{ vendor_refund_requests_url }}', $organiser);
    self::assertStringContainsString('connected Stripe account', $organiser);
  }

  /**
   * Completed refunds explain revoked access without marketing content.
   */
  public function testCompletedRefundHasSafeTransactionalDetails(): void {
    $body = (string) $this->syncTemplate('refund_completed_buyer')['body_html'];

    self::assertStringContainsString('View refund details', $body);
    self::assertStringContainsString('Ticket access:', $body);
    self::assertStringContainsString('Adjustment note', $body);
    self::assertStringNotContainsString('Explore upcoming events', $body);
    self::assertStringNotContainsString('{{ browse_events_url }}', $body);
    self::assertStringNotContainsString('View Digital Pass', $body);
    self::assertStringNotContainsString('2–5 business days', $body);
    self::assertStringContainsString('financial institution controls when it appears', $body);
  }

  /**
   * Twig output resolves links and conditional financial details cleanly.
   */
  public function testCompletedRefundRendersWithoutTemplateTokens(): void {
    $template = $this->syncTemplate('refund_completed_buyer');
    $twig = new Environment(new ArrayLoader([]));
    $body = trim($twig->createTemplate((string) $template['body_html'])->render([
      'event_title' => 'Community Night',
      'order_number' => '2026-08-24-1',
      'refunded_amount' => 'AUD 30.00',
      'tickets_cancelled' => 1,
      'adjustment_note_number' => 'ADJ-2026-08-24-1',
      'adjustment_note_date' => '24 Aug 2026',
      'supplier_name' => 'Sample Organiser',
      'supplier_abn' => '11 111 111 111',
      'adjustment_reason' => 'Customer refund',
      'gst_adjustment' => 'AUD 2.73',
      'my_tickets_url' => 'https://example.test/my-tickets/order/1',
    ]));

    self::assertStringNotContainsString('{{', $body);
    self::assertStringNotContainsString('{%', $body);
    self::assertStringContainsString('href="https://example.test/my-tickets/order/1"', $body);
    self::assertStringContainsString('Cancelled for 1 attendee(s)', $body);
  }

  /**
   * Runtime context supplies public and organiser-domain URLs.
   */
  public function testRefundProcessorBuildsTheNewEmailLinks(): void {
    $processor = file_get_contents(dirname(__DIR__, 3) . '/src/Service/RefundProcessor.php');

    self::assertIsString($processor);
    self::assertStringContainsString("'vendor_refund_requests_url' => \$this->buildVendorRefundRequestsUrl", $processor);
    self::assertStringContainsString("buildDomainUrl(\$path, 'vendor')", $processor);
    self::assertStringContainsString("'myeventlane_refunds.vendor_refund_requests'", $processor);
  }

  /**
   * Fresh installs keep the same content contract as exported config.
   */
  public function testInstallDefaultsContainTheNewEmailContract(): void {
    $install = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_refunds.install');

    self::assertIsString($install);
    self::assertStringContainsString("'preheader' => 'We received your refund request", $install);
    self::assertStringContainsString("'preheader' => 'A refund request for {{ event_title }} needs your review.'", $install);
    self::assertStringContainsString("'preheader' => 'Your {{ refunded_amount }} refund", $install);
    self::assertStringContainsString('Review refund request', $install);
    self::assertStringNotContainsString('Explore upcoming events', $install);
    self::assertStringContainsString("\$config->set('preheader', \$template['preheader'])", $install);
  }

  /**
   * Loads an exported messaging template.
   *
   * @return array<string, mixed>
   *   Decoded template configuration.
   */
  private function syncTemplate(string $id): array {
    $path = dirname(__DIR__, 7) . "/config/sync/myeventlane_messaging.template.{$id}.yml";
    self::assertFileExists($path);
    $data = Yaml::decode((string) file_get_contents($path));
    self::assertIsArray($data);
    return $data;
  }

}
