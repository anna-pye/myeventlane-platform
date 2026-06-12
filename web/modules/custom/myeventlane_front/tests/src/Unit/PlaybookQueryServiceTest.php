<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname(__DIR__, 3) . '/src/Service/PlaybookQueryService.php';

/**
 * @group myeventlane_front
 */
final class PlaybookQueryServiceTest extends TestCase {

  /**
   * Ensures featured playbook cap matches Organiser Hub requirements.
   */
  public function testFeaturedPlaybookCapIsSix(): void {
    $reflection = new ReflectionClass(\Drupal\myeventlane_front\Service\PlaybookQueryService::class);
    $this->assertTrue($reflection->hasConstant('MAX_FEATURED'));
    $this->assertSame(6, $reflection->getConstant('MAX_FEATURED'));
  }

  /**
   * Documents that featured playbooks must be explicitly flagged, not merely sorted.
   */
  public function testFeaturedPlaybookQueryRequiresFeaturedFlag(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Service/PlaybookQueryService.php');
    $this->assertIsString($source);
    $this->assertStringContainsString("->condition('field_featured_playbook', 1)", $source);
    $this->assertStringNotContainsString("->sort('field_featured_playbook', 'DESC')", $source);
  }

}
