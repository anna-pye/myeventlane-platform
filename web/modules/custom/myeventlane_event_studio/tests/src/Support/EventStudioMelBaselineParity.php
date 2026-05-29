<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Support;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Canonicalizes mel baseline arrays for strict parity comparison.
 */
final class EventStudioMelBaselineParity {

  /**
   * @param mixed $value
   *
   * @return mixed
   */
  public static function canonicalize(mixed $value): mixed {
    if ($value instanceof DrupalDateTime) {
      return $value->format('Y-m-d\TH:i:s');
    }
    if ($value instanceof Url) {
      return $value->toString();
    }
    if ($value instanceof EntityInterface) {
      return $value->getEntityTypeId() . ':' . $value->id();
    }
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    if (!is_array($value)) {
      return $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
      $out[$key] = self::canonicalize($item);
    }
    return $out;
  }

  /**
   * @param array<string, mixed> $legacy
   * @param array<string, mixed> $modern
   */
  public static function assertSameCanonicalized(array $legacy, array $modern): void {
    $canonicalLegacy = self::canonicalize($legacy);
    $canonicalModern = self::canonicalize($modern);
    \PHPUnit\Framework\Assert::assertSame($canonicalLegacy, $canonicalModern);
  }

}
