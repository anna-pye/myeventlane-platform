<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Protects the event cover backfill architecture contract.
 *
 * @group myeventlane_event_studio
 */
final class EventCoverMediaBackfillContractTest extends UnitTestCase {

  /**
   * The backfill fills Media provenance without removing the legacy field.
   */
  public function testBackfillPreservesLegacyFieldAndUsesMediaManager(): void {
    $module = dirname(__DIR__, 3);
    $update = file_get_contents($module . '/myeventlane_event_studio.post_update.php');
    self::assertIsString($update);

    self::assertStringContainsString('post_update_backfill_event_cover_media', $update);
    self::assertStringContainsString("->notExists('field_mel_event_cover_media')", $update);
    self::assertStringContainsString('$manager->capture($event)', $update);
    self::assertStringContainsString("\$event->set('field_mel_event_cover_media'", $update);
    self::assertStringContainsString('throw new UpdateException', $update);
    self::assertStringNotContainsString("delete('field_event_image')", $update);
    self::assertStringNotContainsString("set('field_event_image', [])", $update);
  }

}
