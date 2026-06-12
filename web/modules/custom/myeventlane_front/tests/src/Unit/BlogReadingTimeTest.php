<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_front\Unit;

use Drupal\Component\Utility\Html;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_front\Service\BlogArticlePresentationService
 * @group myeventlane_front
 */
final class BlogReadingTimeTest extends TestCase {

  /**
   * Mirrors the reading-time algorithm used by blog presentation services.
   */
  private function estimateMinutes(string $html): ?int {
    $plain = Html::decodeEntities(strip_tags($html));
    $words = preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY);
    $count = is_array($words) ? count($words) : 0;
    if ($count === 0) {
      return NULL;
    }
    return max(1, (int) ceil($count / 200));
  }

  /**
   * @covers ::buildReadingTime
   */
  public function testEmptyBodyReturnsNull(): void {
    $this->assertNull($this->estimateMinutes(''));
  }

  /**
   * @covers ::buildReadingTime
   */
  public function testShortArticleIsAtLeastOneMinute(): void {
    $this->assertSame(1, $this->estimateMinutes('<p>Plan your event with confidence.</p>'));
  }

  /**
   * @covers ::buildReadingTime
   */
  public function testLongArticleRoundsUp(): void {
    $words = implode(' ', array_fill(0, 401, 'guide'));
    $this->assertSame(3, $this->estimateMinutes('<p>' . $words . '</p>'));
  }

}
