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

  public function testBookingDetailRendersTheProtectedRefundAction(): void {
    $module = file_get_contents(dirname(__DIR__, 3) . '/myeventlane_refunds.module');
    $template = file_get_contents(dirname(__DIR__, 4) . '/myeventlane_checkout_flow/templates/myeventlane-order-detail.html.twig');

    self::assertIsString($module);
    self::assertIsString($template);
    self::assertStringContainsString("service('myeventlane_refunds.buyer_eligibility')", $module);
    self::assertStringContainsString("'myeventlane_refunds.buyer_refund'", $module);
    self::assertStringContainsString("['order']['refund_actions']", $module);
    self::assertStringContainsString('order.refund_actions|default([])', $template);
    self::assertStringContainsString('refund_action.can_refund and refund_action.refund_url', $template);
    self::assertStringContainsString("{{ 'Request refund'|t }}", $template);

    $html = $this->renderTemplate([
      'event_title' => 'Community Workshop',
      'can_refund' => TRUE,
      'refund_url' => '/my-tickets/order/623/refund?event=123',
      'ineligible_reason' => NULL,
    ]);
    self::assertStringContainsString('Request refund', $html);
    self::assertStringContainsString('href="/my-tickets/order/623/refund?event=123"', $html);
  }

  public function testBookingDetailDoesNotRenderARefundLinkWhenIneligible(): void {
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
   * Renders the real booking-detail template with a controlled refund action.
   *
   * @param array<string, mixed> $refundAction
   *   Refund action data produced by the module preprocess hook.
   */
  private function renderTemplate(array $refundAction): string {
    $templateDirectory = dirname(__DIR__, 4) . '/myeventlane_checkout_flow/templates';
    $twig = new Environment(new FilesystemLoader($templateDirectory));
    $twig->addFilter(new TwigFilter('t', static fn (string $text): string => $text));
    $twig->addFunction(new TwigFunction('url', static fn (string $route): string => '/generated/' . str_replace('.', '/', $route)));

    return $twig->render('myeventlane-order-detail.html.twig', [
      'title' => 'Booking 623',
      'operational_addon_guidance' => '',
      'order' => [
        'ticket_models' => [],
        'ticket_items' => [],
        'events' => [],
        'refund_actions' => [$refundAction],
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
