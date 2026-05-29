<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for mel defaults provider extraction path.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioMelDefaultsProviderContractTest extends TestCase {

  public function testProviderDoesNotReferenceGovernanceOrMonolithForm(): void {
    $path = dirname(__DIR__, 3) . '/src/Service/EventStudioMelDefaultsProvider.php';
    $contents = (string) file_get_contents($path);
    $this->assertStringNotContainsString('EventStudioGovernanceBuilder', $contents);
    $this->assertStringNotContainsString('governance_builder', $contents);
    $this->assertDoesNotMatchRegularExpression('/\bEventStudioForm::class\b/', $contents);
    $this->assertDoesNotMatchRegularExpression('/use Drupal\\\\myeventlane_event_studio\\\\Form\\\\EventStudioForm;/', $contents);
    $this->assertStringNotContainsString('EventStudioAiAssistBuilder', $contents);
    $this->assertStringNotContainsString('readinessHelper', $contents);
  }

  public function testWizardBaselineUsesProviderNotMonolithForm(): void {
    $path = dirname(__DIR__, 3) . '/src/Service/EventStudioWizardMelBaseline.php';
    $contents = (string) file_get_contents($path);
    $this->assertDoesNotMatchRegularExpression('/\bEventStudioForm::class\b/', $contents);
    $this->assertStringNotContainsString('FormBuilderInterface', $contents);
    $this->assertStringContainsString('EventStudioMelDefaultsProvider', $contents);
  }

}
