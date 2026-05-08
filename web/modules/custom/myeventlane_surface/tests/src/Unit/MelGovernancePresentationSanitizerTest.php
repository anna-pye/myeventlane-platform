<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Unit;

use Drupal\myeventlane_surface\MelGovernancePresentationSanitizer;
use Drupal\myeventlane_core\MelSurfaceId;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_surface\MelGovernancePresentationSanitizer
 *
 * @group myeventlane_surface
 */
final class MelGovernancePresentationSanitizerTest extends TestCase {

  /**
   * @covers ::sanitizeIntelligencePayloadForPresentation
   */
  public function testVendorPayloadStripsSignalInternals(): void {
    $sanitizer = new MelGovernancePresentationSanitizer();
    $payload = [
      'items' => [[
        'id' => 'draft_completion_prompt',
        'category' => 'vendor',
        'presentation_lane' => 'operational',
        'headline' => 'H',
        'recommended_action' => 'A',
        'dismissible' => TRUE,
        'why_shown' => 'Internal definition notes',
        'trigger_signal_keys' => ['route.vendor', 'state.draft'],
        'state_snapshot' => ['draft' => 'satisfied'],
      ]],
      'insights' => [[
        'id' => 'i1',
        'headline' => 'Headline',
        'meta' => ['trigger' => 'state.x'],
      ]],
      'prioritisation_trace' => [['id' => 'x']],
      'orchestration' => [
        'visible_cap' => 2,
        'visible_ids' => ['a'],
        'suppressed_ids' => ['secret'],
        'density_policy' => 'normal',
        'messaging_hints' => [],
        'adaptive_profiles' => [],
      ],
      'explainability' => [
        'framework' => 'Leaky',
        'no_hidden_scores' => TRUE,
        'dismiss_supported' => TRUE,
      ],
      'operational_policy' => [
        'suppression' => ['k' => TRUE],
      ],
      'adaptive_profiles' => ['p' => 1],
      '#cache' => ['contexts' => ['user']],
    ];

    $out = $sanitizer->sanitizeIntelligencePayloadForPresentation($payload, MelSurfaceId::Vendor);
    $item = $out['items'][0];
    $this->assertArrayNotHasKey('trigger_signal_keys', $item);
    $this->assertArrayNotHasKey('why_shown', $item);
    $this->assertArrayNotHasKey('state_snapshot', $item);
    $this->assertSame('H', $item['headline']);

    $this->assertArrayNotHasKey('meta', $out['insights'][0]);

    $this->assertSame([], $out['prioritisation_trace']);
    $this->assertSame([], $out['operational_policy']);
    $this->assertArrayNotHasKey('suppressed_ids', $out['orchestration']);
    $this->assertArrayNotHasKey('framework', $out['explainability']);
  }

  /**
   * @covers ::sanitizeIntelligencePayloadForPresentation
   */
  public function testStaffPreservesExplainabilityInternals(): void {
    $sanitizer = new MelGovernancePresentationSanitizer();
    $payload = [
      'items' => [[
        'id' => 'x',
        'trigger_signal_keys' => ['signal'],
      ]],
      'insights' => [['id' => 'i', 'headline' => 'h', 'meta' => ['k' => 'v']]],
      'explainability' => ['framework' => 'full'],
      'operational_policy' => ['suppression' => []],
      'prioritisation_trace' => [['id' => 'x']],
      'orchestration' => ['visible_ids' => ['x'], 'suppressed_ids' => ['y']],
      'adaptive_profiles' => ['a'],
      '#cache' => [],
    ];
    $out = $sanitizer->sanitizeIntelligencePayloadForPresentation($payload, MelSurfaceId::Staff);
    $this->assertSame(['signal'], $out['items'][0]['trigger_signal_keys']);
    $this->assertSame(['k' => 'v'], $out['insights'][0]['meta']);
    $this->assertSame(['y'], $out['orchestration']['suppressed_ids']);
  }

  /**
   * @covers ::sanitizeEventStudioGovernanceJs
   */
  public function testJsSuppressionKeysClearedOffStaff(): void {
    $sanitizer = new MelGovernancePresentationSanitizer();
    $js = [
      'suppressionActiveIds' => ['a'],
      'experience' => ['escalation_active_ids' => ['e']],
    ];
    $out = $sanitizer->sanitizeEventStudioGovernanceJs($js, MelSurfaceId::Vendor);
    $this->assertSame([], $out['suppressionActiveIds']);
    $this->assertSame([], $out['experience']['escalation_active_ids']);
  }

}
