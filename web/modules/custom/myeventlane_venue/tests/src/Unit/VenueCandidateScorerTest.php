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

  /**
   * Tests that formatting differences do not bypass exact duplicate checks.
   */
  public function testDuplicateRequiresSameNormalisedNameAndAddress(): void {
    self::assertTrue($this->scorer->isDuplicate(
      'The Workers’ Club',
      '51 Brunswick Street, Fitzroy VIC 3065',
      NULL,
      NULL,
      [
        'name' => 'THE WORKERS CLUB!',
        'address' => '51 Brunswick Street — Fitzroy, VIC 3065',
      ],
    ));
  }

  /**
   * Tests that branches with the same name remain valid distinct venues.
   */
  public function testSameNameAtDifferentAddressIsNotDuplicate(): void {
    self::assertFalse($this->scorer->isDuplicate(
      'Community Hall',
      '1 High Street, Carlton VIC 3053',
      NULL,
      NULL,
      [
        'name' => 'Community Hall',
        'address' => '99 Beach Road, St Kilda VIC 3182',
      ],
    ));
  }

  /**
   * Tests that provider coordinates catch minor address-format differences.
   */
  public function testSameNameWithinSeventyFiveMetresIsDuplicate(): void {
    self::assertTrue($this->scorer->isDuplicate(
      'Abbotsford Convent',
      '1 St Heliers St, Abbotsford',
      -37.8024,
      145.0034,
      [
        'name' => 'Abbotsford Convent',
        'address' => '1 Saint Heliers Street, Abbotsford VIC 3067',
        'latitude' => -37.80241,
        'longitude' => 145.00339,
      ],
    ));
  }

  /**
   * Tests that co-located organisations are not merged by address alone.
   */
  public function testDifferentNameAtSameAddressIsNotDuplicate(): void {
    self::assertFalse($this->scorer->isDuplicate(
      'Gallery One',
      '100 Arts Lane, Melbourne VIC 3000',
      -37.8100,
      144.9600,
      [
        'name' => 'Gallery Two',
        'address' => '100 Arts Lane, Melbourne VIC 3000',
        'latitude' => -37.8100,
        'longitude' => 144.9600,
      ],
    ));
  }

}
