<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_refunds\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Guards the eligible customer refund action on booking details.
 */
final class CustomerRefundActionContractTest extends TestCase {

  /**
   * An eligible booking renders its protected refund action.
   */
  public function testBookingDetailRendersTheProtectedRefundAction(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_refunds.module');
    $services = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_refunds.services.yml');
    $template = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_checkout_flow/templates/myeventlane-order-detail.html.twig');

    self::assertIsString($module);
    self::assertIsString($services);
    self::assertIsString($template);
    self::assertStringContainsString("service('myeventlane_refunds.buyer_eligibility')", $module);
    self::assertStringContainsString("service('myeventlane_refunds.refund_request_storage')", $module);
    self::assertStringContainsString('loadLatestForBuyer', $module);
    self::assertStringContainsString("['@database', '@datetime.time', '@cache_tags.invalidator']", $services);
    self::assertStringContainsString("'myeventlane_refunds.buyer_refund'", $module);
    self::assertStringContainsString("['order']['refund_actions']", $module);
    self::assertStringContainsString('order.refund_actions|default([])', $template);
    self::assertStringContainsString('refund_action.can_refund and refund_action.refund_url', $template);
    self::assertStringContainsString("'Request refund'|t", $template);

    $html = $this->renderTemplate([
      'event_title' => 'Community Workshop',
      'can_refund' => TRUE,
      'refund_url' => '/my-tickets/order/623/refund?event=123',
      'ineligible_reason' => NULL,
    ]);
    self::assertStringContainsString('Request refund', $html);
    self::assertStringContainsString('href="/my-tickets/order/623/refund?event=123"', $html);
  }

  /**
   * An ineligible booking does not render a refund link.
   */
  public function testBookingDetailDoesNotRenderRefundLinkWhenIneligible(): void {
    $html = $this->renderTemplate([
      'event_title' => 'Community Workshop',
      'can_refund' => FALSE,
      'refund_url' => NULL,
      'ineligible_reason' => 'The refund window for this event has closed.',
    ]);
    self::assertStringNotContainsString('href="/my-tickets/order/623/refund', $html);
    self::assertStringContainsString('The refund window for this event has closed.', $html);
  }

  /**
   * A declined latest request remains distinct from an earlier payment refund.
   */
  public function testBookingDetailExplainsRejectedRequestAfterEarlierRefund(): void {
    $html = $this->renderTemplate([
      'event_title' => 'Community Workshop',
      'can_refund' => FALSE,
      'refund_url' => NULL,
      'ineligible_reason' => 'There are no active tickets left to refund for this event.',
      'latest_request' => [
        'status' => 'rejected',
        'decision_reason' => 'This ticket was already refunded.',
      ],
    ], [
      'status' => 'partial',
      'heading' => 'Partial refund processed',
      'refunded_amount' => 'A$22.73',
      'original_paid_amount' => 'A$23.11',
      'remaining_amount' => 'A$0.38',
      'message' => 'The refund was recorded.',
    ]);

    self::assertStringContainsString('Latest refund request declined.', $html);
    self::assertStringContainsString('This ticket was already refunded.', $html);
    self::assertStringContainsString('processed from an earlier approved request', $html);
    self::assertStringContainsString('There are no active tickets left to refund for this event.', $html);
  }

  /**
   * Renders the real booking-detail template with a controlled refund action.
   *
   * @param array<string, mixed> $refundAction
   *   Refund action data produced by the module preprocess hook.
   * @param array<string, mixed>|null $refundSummary
   *   Optional canonical payment refund summary.
   */
  private function renderTemplate(array $refundAction, ?array $refundSummary = NULL): string {
    $templateDirectory = dirname(__DIR__, 4) . '/myeventlane_checkout_flow/templates';
    $twig = new Environment(new FilesystemLoader($templateDirectory));
    $twig->addFilter(new TwigFilter('t', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('commerce_price_format', static fn (mixed $price): string => (string) $price));
    $twig->addFunction(new TwigFunction('url', static fn (string $route): string => '/generated/' . str_replace('.', '/', $route)));

    return $twig->render('myeventlane-order-detail.html.twig', [
      'title' => 'Booking 623',
      'operational_addon_guidance' => '',
      'order' => [
        'ticket_models' => [],
        'ticket_items' => [],
        'events' => [],
        'refund_actions' => [$refundAction],
        'refund_summary' => $refundSummary,
        'donation_total' => 0,
        'order_id' => 623,
        'order_number' => '623',
        'placed_date' => '22 August 2026',
        'state' => 'Completed',
        'total_price' => 0,
      ],
    ]);
  }

}
