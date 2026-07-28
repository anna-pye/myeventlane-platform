<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the portfolio Messages hub reliability and presentation contract.
 *
 * @group myeventlane_vendor
 */
final class VendorMessagesHubContractTest extends TestCase {

  private function repositoryRoot(): string {
    return dirname(__DIR__, 7);
  }

  public function testRecipientResolverLoadsOrdersSafely(): void {
    $resolver = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_messaging/src/Service/AttendeeRecipientResolver.php');
    $this->assertIsString($resolver);
    $this->assertStringNotContainsString('$orderItem->getOrder()', $resolver);
    $this->assertStringContainsString("getStorage('commerce_order')", $resolver);
    $this->assertStringContainsString("get('order_id')->target_id", $resolver);
  }

  public function testHubKeepsCompactSearchableHierarchy(): void {
    $template = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/templates/messages-hub.html.twig');
    $this->assertIsString($template);
    $this->assertStringContainsString('data-mel-message-event-search', $template);
    $this->assertStringContainsString('data-mel-message-event=', $template);
    $this->assertStringContainsString('mel-messages-hub__activity-grid', $template);
    $this->assertStringContainsString('mel-messages-hub__tool-list', $template);
    $this->assertStringContainsString('<details class="mel-messages-hub__tool', $template);
    $this->assertStringContainsString('mel-messages-hub__tool--locked', $template);
    $this->assertStringContainsString('templates.upgrade_url', $template);
    $this->assertStringContainsString('branding.upgrade_url', $template);
    $this->assertStringContainsString('branding.available', $template);
    $this->assertStringNotContainsString('{% set audience =', $template);
    $this->assertStringNotContainsString('<summary>{{ audience.title }}</summary>', $template);
  }

  public function testHubSearchUsesDrupalBehaviour(): void {
    $script = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/js/mel-messages-hub.js');
    $this->assertIsString($script);
    $this->assertStringContainsString('Drupal.behaviors.melMessagesHub', $script);
    $this->assertStringContainsString("once('mel-messages-hub'", $script);
    $this->assertStringContainsString("search.addEventListener('input'", $script);
  }

}
