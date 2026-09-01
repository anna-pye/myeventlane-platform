<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\myeventlane_venue\Service\OverturePlaceImporter;
use Drupal\myeventlane_venue\Service\VenueCandidateScorer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the bounded Australian Overture CSV import contract.
 */
#[CoversClass(OverturePlaceImporter::class)]
#[Group('myeventlane_venue')]
final class OverturePlaceImporterTest extends TestCase {

  /**
   * Tests dry-run validation without writes and rejects non-Australian rows.
   */
  public function testDryRunValidatesAustralianRowsWithoutDatabaseWrites(): void {
    $path = tempnam(sys_get_temp_dir(), 'mel-overture-');
    self::assertIsString($path);
    file_put_contents($path, implode("\n", [
      'id,name,address,locality,postcode,region,country,latitude,longitude,website,phone,email,socials,confidence,source_dataset,source_updated',
      'gers-1,Test Hall,"1 Main Street, Melbourne VIC 3000",Melbourne,3000,VIC,AU,-37.8136,144.9631,https://example.com,+61390000000,hello@example.com,"[""https://www.instagram.com/testhall""]",0.92,meta,2026-08-19',
      'gers-2,Outside Australia,Somewhere,Auckland,1010,AUK,NZ,-36.8509,174.7645,,,,[],0.80,meta,2026-08-19',
    ]) . "\n");

    $database = $this->createMock(Connection::class);
    $database->expects(self::never())->method('merge');
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1788260000);
    $importer = new OverturePlaceImporter($database, $time, new VenueCandidateScorer());

    try {
      $report = $importer->import($path, TRUE);
    }
    finally {
      unlink($path);
    }

    self::assertSame(2, $report['processed']);
    self::assertSame(1, $report['imported']);
    self::assertSame(1, $report['skipped']);
    self::assertCount(1, $report['errors']);
    self::assertStringContainsString('Australian places only', $report['errors'][0]);
  }

  /**
   * Tests that an extract missing required columns is rejected.
   */
  public function testMissingRequiredHeaderIsRejected(): void {
    $path = tempnam(sys_get_temp_dir(), 'mel-overture-');
    self::assertIsString($path);
    file_put_contents($path, "id,name\ngers-1,Test Hall\n");
    $time = $this->createStub(TimeInterface::class);
    $importer = new OverturePlaceImporter(
      $this->createStub(Connection::class),
      $time,
      new VenueCandidateScorer(),
    );

    try {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage('latitude, longitude');
      $importer->import($path, TRUE);
    }
    finally {
      unlink($path);
    }
  }

}
