<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_vendor\Service\UserVendorMembershipQuery;
use Drupal\myeventlane_vendor\Service\VendorEventStudioCreateService;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_vendor\Service\VendorEventStudioCreateService
 *
 * @group myeventlane_vendor
 */
final class VendorEventStudioCreateDraftLogicTest extends TestCase {

  private VendorEventStudioCreateService $service;

  protected function setUp(): void {
    parent::setUp();
    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translateString')->willReturnCallback(
      static fn ($markup) => method_exists($markup, 'getUntranslatedString')
        ? $markup->getUntranslatedString()
        : (string) $markup,
    );
    $this->service = new VendorEventStudioCreateService(
      $this->createMock(EntityTypeManagerInterface::class),
      new UserVendorMembershipQuery(
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(EntityFieldManagerInterface::class),
      ),
      $this->createMock(LoggerInterface::class),
      $this->createMock(MessengerInterface::class),
      $translator,
    );
  }

  /**
   * @covers ::isResumableDraftEvent
   */
  public function testUnpublishedEmptyStateIsResumable(): void {
    $event = $this->event(published: FALSE, state: NULL);
    $this->assertTrue($this->service->isResumableDraftEvent($event));
  }

  /**
   * @covers ::isResumableDraftEvent
   */
  public function testUnpublishedDraftStateIsResumable(): void {
    $event = $this->event(published: FALSE, state: 'draft');
    $this->assertTrue($this->service->isResumableDraftEvent($event));
  }

  /**
   * @covers ::isResumableDraftEvent
   */
  public function testUnpublishedEndedStateIsNotResumable(): void {
    $event = $this->event(published: FALSE, state: 'ended');
    $this->assertFalse($this->service->isResumableDraftEvent($event));
  }

  /**
   * @covers ::isResumableDraftEvent
   */
  public function testUnpublishedCancelledStateIsNotResumable(): void {
    $event = $this->event(published: FALSE, state: 'cancelled');
    $this->assertFalse($this->service->isResumableDraftEvent($event));
  }

  /**
   * @covers ::isResumableDraftEvent
   */
  public function testPublishedEventIsNotResumable(): void {
    $event = $this->event(published: TRUE, state: 'draft');
    $this->assertFalse($this->service->isResumableDraftEvent($event));
  }

  /**
   * Lookup must filter lifecycle in SQL, not a newest-N PHP window.
   *
   * @covers ::findLatestResumableDraftNidForUser
   */
  public function testDraftLookupFiltersLifecycleInEntityQuery(): void {
    $path = dirname(__DIR__, 3) . '/src/Service/VendorEventStudioCreateService.php';
    $this->assertFileExists($path);
    $raw = file_get_contents($path);
    $this->assertIsString($raw);
    $this->assertStringContainsString("notExists('field_event_state')", $raw);
    $this->assertStringContainsString("condition('field_event_state', 'draft')", $raw);
    $this->assertStringContainsString('orConditionGroup()', $raw);
    $this->assertStringContainsString('->range(0, 1)', $raw);
    // The previous newest-10 PHP filter missed older drafts behind non-drafts.
    $this->assertStringNotContainsString('->range(0, 10)', $raw);
    $this->assertStringNotContainsString('loadMultiple($ids)', $raw);
  }

  private function event(bool $published, ?string $state): NodeInterface {
    $event = $this->createMock(NodeInterface::class);
    $event->method('bundle')->willReturn('event');
    $event->method('isPublished')->willReturn($published);
    $event->method('hasField')->with('field_event_state')->willReturn(TRUE);

    $list = new class($state) {
      public mixed $value;

      public function __construct(?string $state) {
        $this->value = $state;
      }

      public function isEmpty(): bool {
        return $this->value === NULL;
      }
    };
    $event->method('get')->with('field_event_state')->willReturn($list);
    return $event;
  }

}
