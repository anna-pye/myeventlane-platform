<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_pro\Unit;

use Drupal\myeventlane_boost\Entity\BoostEntitlementInterface;
use Drupal\myeventlane_pro\Service\ProBoostGrantPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests the fixed Pro Boost grant policy.
 *
 * @group myeventlane_pro
 */
final class ProBoostGrantPolicyTest extends TestCase {

  /**
   * The policy under test.
   */
  private ProBoostGrantPolicy $policy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->policy = new ProBoostGrantPolicy();
  }

  /**
   * Tests the standard configured seven-day grant.
   */
  public function testNewGrantIsExactlySevenDays(): void {
    $now = 1_700_000_000;
    self::assertSame($now + (7 * 86400), $this->policy->newGrantEnd($now, NULL, 7));
  }

  /**
   * Tests that a grant cannot outlive the Pro entitlement.
   */
  public function testNewGrantIsCappedByProEnd(): void {
    $now = 1_700_000_000;
    $proEnd = $now + 86400;
    self::assertSame($proEnd, $this->policy->newGrantEnd($now, $proEnd, 7));
  }

  /**
   * Tests that an ended Pro entitlement cannot create a grant.
   */
  public function testNewGrantIsRejectedWhenProHasEnded(): void {
    $now = 1_700_000_000;
    self::assertNull($this->policy->newGrantEnd($now, $now, 7));
  }

  /**
   * Tests that legacy longer grants are shortened to the configured duration.
   */
  public function testLegacyLongGrantIsCappedWithoutExtension(): void {
    $startsAt = 1_700_000_000;
    $now = $startsAt + 3600;
    $legacyEnd = $startsAt + (30 * 86400);
    self::assertSame(
      $startsAt + (7 * 86400),
      $this->policy->existingGrantEnd($now, $startsAt, $legacyEnd, NULL, 7),
    );
  }

  /**
   * Tests that reconciliation cannot extend an existing shorter grant.
   */
  public function testRepeatedSyncDoesNotExtendExistingGrant(): void {
    $startsAt = 1_700_000_000;
    $existingEnd = $startsAt + (3 * 86400);
    self::assertSame(
      $existingEnd,
      $this->policy->existingGrantEnd($startsAt + 3600, $startsAt, $existingEnd, NULL, 7),
    );
  }

  /**
   * Tests that reconciliation cannot revive an expired grant.
   */
  public function testExpiredGrantIsNotRevived(): void {
    $startsAt = 1_700_000_000;
    $existingEnd = $startsAt + (7 * 86400);
    self::assertNull(
      $this->policy->existingGrantEnd($existingEnd, $startsAt, $existingEnd, NULL, 7),
    );
  }

  /**
   * Tests that an elapsed legacy grant still exposes its seven-day cap.
   */
  public function testElapsedLegacyGrantRetainsCanonicalCapForPersistence(): void {
    $startsAt = 1_700_000_000;
    $legacyEnd = $startsAt + (30 * 86400);

    self::assertSame(
      $startsAt + (7 * 86400),
      $this->policy->cappedExistingGrantEnd($startsAt, $legacyEnd, NULL, 7),
    );
  }

  /**
   * Tests that expiry reconciliation never overwrites a revocation outcome.
   */
  public function testElapsedGrantPreservesRevokedStatus(): void {
    self::assertSame(
      BoostEntitlementInterface::STATUS_REVOKED,
      $this->policy->elapsedGrantStatus(BoostEntitlementInterface::STATUS_REVOKED),
    );
    self::assertSame(
      BoostEntitlementInterface::STATUS_EXPIRED,
      $this->policy->elapsedGrantStatus(BoostEntitlementInterface::STATUS_ACTIVE),
    );
    self::assertSame(
      BoostEntitlementInterface::STATUS_EXPIRED,
      $this->policy->elapsedGrantStatus(BoostEntitlementInterface::STATUS_EXPIRED),
    );
  }

}
