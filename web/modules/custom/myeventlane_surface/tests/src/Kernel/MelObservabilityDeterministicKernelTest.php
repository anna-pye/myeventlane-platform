<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelObservabilityDeterministicKey;

/**
 * Deterministic observability keys (locale-invariant, pipe-normalised).
 *
 * @group myeventlane_surface
 */
final class MelObservabilityDeterministicKernelTest extends MelSurfaceGovernanceKernelTestBase {

  public function testFormatNormalisesPipeCharacters(): void {
    $key = MelObservabilityDeterministicKey::format('mel.obs.a', 'b|c', 'd|e');
    $this->assertStringNotContainsString('|', $key);
    $this->assertSame('mel.obs.a|b_c|d_e', $key);
  }

  /**
   * Mirrors MELObservabilityManager::sortTraces() strcmp ordering contract.
   */
  public function testDeterministicKeyLexicalSortContract(): void {
    $first = MelObservabilityDeterministicKey::format('mel.obs.workflow.activation', 'active', 'alpha');
    $second = MelObservabilityDeterministicKey::format('mel.obs.workflow.activation', 'active', 'beta');
    $this->assertLessThan(0, strcmp($first, $second));
  }

}
