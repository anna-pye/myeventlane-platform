<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the canonical Event Studio orders presentation contract.
 */
final class EventStudioOrdersPresentationContractTest extends TestCase {

  public function testStudioOrdersReuseCanonicalVendorProjection(): void {
    $root = dirname(__DIR__, 7);
    $renderer = file_get_contents($root . '/web/modules/custom/myeventlane_event_studio/src/Service/EventStudioSectionRenderer.php');
    $controller = file_get_contents($root . '/web/modules/custom/myeventlane_vendor/src/Controller/VendorEventOrdersController.php');

    self::assertIsString($renderer);
    self::assertIsString($controller);
    self::assertStringContainsString('buildStudioContent($event)', $renderer);
    self::assertStringContainsString('public function buildStudioContent(NodeInterface $event): array', $controller);
    self::assertStringContainsString("\$data = \$this->getOrdersForEvent(\$event);", $controller);
    self::assertStringContainsString("'#studio' => TRUE", $controller);
  }

  public function testStudioOrdersTemplateKeepsSummaryAndActions(): void {
    $root = dirname(__DIR__, 7);
    $template = file_get_contents($root . '/web/themes/custom/myeventlane_vendor_theme/templates/event/orders.html.twig');

    self::assertIsString($template);
    self::assertStringContainsString('mel-studio-orders__summary', $template);
    self::assertStringContainsString('{{ row.view_link }}', $template);
    self::assertStringContainsString('{{ row.resend_link }}', $template);
    self::assertStringContainsString('{{ totals.ticket_count }}', $template);
    self::assertStringContainsString('{{ totals.total }}', $template);
    self::assertStringContainsString("{{ 'Fees'|t }} {{ row.fees }}", $template);
  }

  public function testStudioOrdersDescribeAllEventLinkedItemsAccurately(): void {
    $root = dirname(__DIR__, 7);
    $template = file_get_contents($root . '/web/themes/custom/myeventlane_vendor_theme/templates/event/orders.html.twig');

    self::assertIsString($template);
    self::assertStringContainsString("{{ 'Items sold'|t }}", $template);
    self::assertStringContainsString("{{ 'Gross sales'|t }}", $template);
    self::assertStringContainsString("{{ 'Items'|t }}", $template);
    self::assertStringNotContainsString("{{ 'Tickets sold'|t }}", $template);
    self::assertStringNotContainsString("{{ 'Gross ticket sales'|t }}", $template);
    self::assertStringNotContainsString("{{ 'Tickets'|t }}", $template);
  }

}
