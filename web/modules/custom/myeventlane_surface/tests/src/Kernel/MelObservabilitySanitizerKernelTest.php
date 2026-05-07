<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelObservabilityTraceSanitizer;

/**
 * Trace sanitizer redaction (no production orchestration).
 *
 * @group myeventlane_surface
 */
final class MelObservabilitySanitizerKernelTest extends MelSurfaceGovernanceKernelTestBase {

  public function testSanitizeRowsRedactsEmailStripeLongDigits(): void {
    $dirty_message = 'Contact bad.actor@example.com acct_1AbCdEfGhIjKlMn charge pi_9ZZZZZZZZZZZZZZZ sk_live_ABCDEFGHIJ order 12345678';
    $rows = MelObservabilityTraceSanitizer::sanitizeRows([
      [
        'observability_id' => 'mel.obs.test',
        'code' => 'case_a',
        'message' => $dirty_message,
        'deterministic_key' => 'mel.obs.test|case_a',
      ],
    ]);
    $this->assertCount(1, $rows);
    $clean = $rows[0]['message'];
    $this->assertStringNotContainsString('bad.actor@example.com', $clean);
    $this->assertStringNotContainsString('acct_1AbCdEfGhIjKlMn', $clean);
    $this->assertStringNotContainsString('pi_9ZZZZZZZZZZZZZZZ', $clean);
    $this->assertStringNotContainsString('sk_live_', $clean);
    $this->assertStringNotContainsString('12345678', $clean);
    $this->assertSame('mel.obs.test', $rows[0]['observability_id']);
    $this->assertSame('mel.obs.test|case_a', $rows[0]['deterministic_key']);
  }

  public function testSanitizeRegistryMetaRedactsStringFields(): void {
    $meta = MelObservabilityTraceSanitizer::sanitizeRegistryMeta([
      [
        'id' => 'policy_a',
        'category' => 'trust',
        'sources' => 'Reach ops@internal.example.org about pi_1234567890',
        'privacy' => 'No leaks',
        'accessibility' => 'OK',
      ],
    ]);
    $this->assertStringNotContainsString('ops@internal.example.org', $meta[0]['sources']);
    $this->assertStringNotContainsString('pi_1234567890', $meta[0]['sources']);
  }

}
