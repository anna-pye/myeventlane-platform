<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use Drupal\myeventlane_venue\Service\VenueCandidateScorer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests conservative provider-independent venue matching.
 */
#[CoversClass(VenueCandidateScorer::class)]
#[Group('myeventlane_venue')]
final class VenueCandidateScorerTest extends TestCase {

  /**
   * The candidate scorer under test.
   */
  private VenueCandidateScorer $scorer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->scorer = new VenueCandidateScorer();
  }

  /**
   * Tests that exact nearby venue details produce the maximum score.
   */
  public function testExactNearbyVenueIsHighConfidenceMatch(): void {
    $score = $this->scorer->score(
      'Abbotsford Convent',
      '1 St Heliers Street, Abbotsford VIC 3067',
      -37.8024,
      145.0034,
      [
        'name' => 'Abbotsford Convent',
        'address' => '1 St Heliers Street, Abbotsford VIC 3067',
        'latitude' => -37.80241,
        'longitude' => 145.00339,
        'confidence' => 0.95,
      ],
    );

    self::assertSame(100, $score);
  }

  /**
   * Tests that matching ignores punctuation, whitespace and letter case.
   */
  public function testNormalisationHandlesPunctuationAndCase(): void {
    self::assertSame(
      'the workers club fitzroy',
      $this->scorer->normalize('  The Workers’ Club — FITZROY!  '),
    );
  }

  /**
   * Tests that an unrelated distant venue stays below the match threshold.
   */
  public function testUnrelatedVenueIsRejectedByThreshold(): void {
    $score = $this->scorer->score(
      'Town Hall',
      'Sydney NSW',
      -33.8732,
      151.2060,
      [
        'name' => 'Regional Sports Pavilion',
        'address' => 'Geelong VIC',
        'latitude' => -38.1499,
        'longitude' => 144.3617,
      ],
    );

    self::assertLessThan(35, $score);
  }

}
