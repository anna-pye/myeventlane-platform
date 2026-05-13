<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Governance tests for legacy PDF compatibility isolation (Phase 2B Commit 5).
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class LegacyPdfCompatibilityAdapterKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'options',
    'datetime',
    'datetime_range',
    'commerce',
    'commerce_price',
    'commerce_order',
    'commerce_product',
    'entity_reference_revisions',
    'paragraphs',
    'mel_ticket',
    'myeventlane_vendor',
    'myeventlane_tickets',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    foreach (array_keys($container->getDefinitions()) as $service_id) {
      if (str_starts_with($service_id, 'myeventlane_vendor.') && $service_id !== 'myeventlane_vendor.event_access_checker') {
        $container->removeDefinition($service_id);
      }
    }

    $container->register('myeventlane_vendor.event_access_checker', EventVendorAccessChecker::class);
    $container->register('myeventlane_onboarding.manager', \stdClass::class);
    $container->register('myeventlane_core.vendor_follow', \stdClass::class);
    $container->register('myeventlane_event_studio.save', \stdClass::class);
    $container->register('myeventlane_legal.gatekeeper', \stdClass::class);
    $container->register('myeventlane_core.domain_detector', \stdClass::class);
    $container->register('myeventlane_analytics.order_item_classifier', \stdClass::class);
    $container->register('myeventlane_core.entity_id_normalizer', \stdClass::class);
    $container->register('myeventlane_boost.manager', \stdClass::class);
  }

  /**
   * Canonical ticket PDF methods must not call compatibility adapters.
   */
  public function testCanonicalTicketPdfMethodsDelegateToAdaptersAtFacadeOnly(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $path = $moduleRoot . '/src/Ticket/TicketPdfGenerator.php';
    $this->assertFileExists($path);
    $src = (string) file_get_contents($path);

    $start = strpos($src, 'function buildPdfFromCanonicalModel');
    $this->assertNotFalse($start);
    $next = strpos($src, 'function buildPdfFromLegacyExtraction', $start);
    $this->assertNotFalse($next);
    $canonical = substr($src, $start, $next - $start);
    $this->assertStringNotContainsString('orderItemPdfAdapter', $canonical);
    $this->assertStringNotContainsString('rsvpPdfAdapter', $canonical);
    $this->assertStringNotContainsString('attendeePdfAdapter', $canonical);

    $this->assertSame(1, substr_count($src, '$this->orderItemPdfAdapter->'));
    $this->assertSame(1, substr_count($src, '$this->rsvpPdfAdapter->'));
    $this->assertSame(1, substr_count($src, '$this->attendeePdfAdapter->'));
  }

  /**
   * Order-item / RSVP / attendee formatting must not re-inline postal address keys.
   */
  public function testTicketPdfGeneratorDoesNotDuplicatePostalAddressFormatting(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $generatorSrc = (string) file_get_contents($moduleRoot . '/src/Ticket/TicketPdfGenerator.php');
    $this->assertStringNotContainsString('address_line1', $generatorSrc);

    $helperSrc = (string) file_get_contents($moduleRoot . '/src/Service/TicketPdfEventMetadataHelper.php');
    $this->assertStringContainsString('address_line1', $helperSrc);
  }

  /**
   * Compatibility adapters must not own Dompdf; rendering is centralized.
   */
  public function testCompatibilityAdaptersDoNotReferenceDompdf(): void {
    $moduleRoot = dirname(__DIR__, 3) . '/src/Pdf/Compatibility';
    foreach (glob($moduleRoot . '/*.php') ?: [] as $file) {
      $src = (string) file_get_contents($file);
      $this->assertStringNotContainsString('Dompdf', $src, basename((string) $file) . ' must delegate rendering inward.');
    }
  }

}
