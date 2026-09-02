<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_venue\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects duplicate prevention across organiser venue creation paths.
 *
 * @group myeventlane_venue
 */
final class VenueDuplicatePreventionContractTest extends TestCase {

  /**
   * Ensures the central creation service rejects a detected duplicate.
   */
  public function testVenueManagerEnforcesDuplicateGuardBeforeCreation(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $manager = (string) file_get_contents($moduleRoot . '/src/Service/VenueManager.php');
    $create_position = strpos($manager, 'Venue::create($venue_values)');
    $guard_position = strpos($manager, '$this->duplicateGuard->findDuplicate(');
    $throw_position = strpos($manager, 'throw new DuplicateVenueException($duplicate);');

    self::assertIsInt($create_position);
    self::assertIsInt($guard_position);
    self::assertIsInt($throw_position);
    self::assertLessThan($create_position, $guard_position);
    self::assertLessThan($create_position, $throw_position);
    self::assertStringContainsString('public function guardVenueCreation(', $manager);
    self::assertStringContainsString('$this->lock->acquire($lock_name, 15.0)', $manager);
    self::assertStringContainsString('$this->lock->release($lock_name)', $manager);
  }

  /**
   * Ensures both organiser forms provide an actionable validation message.
   */
  public function testVenueFormsValidateBeforeSaving(): void {
    $moduleRoot = dirname(__DIR__, 3);
    foreach (['VenueForm.php', 'VenueQuickCreateForm.php'] as $file) {
      $form = (string) file_get_contents($moduleRoot . '/src/Form/' . $file);
      self::assertStringContainsString('$this->duplicateGuard->findDuplicate(', $form);
      self::assertStringContainsString('This venue already exists as', $form);
    }
    $full_form = (string) file_get_contents($moduleRoot . '/src/Form/VenueForm.php');
    self::assertStringContainsString('$this->venueManager->guardVenueCreation(', $full_form);
    self::assertStringContainsString('No duplicate was created.', $full_form);
  }

  /**
   * Ensures Event Studio turns the central rejection into useful guidance.
   */
  public function testEventStudioHandlesDuplicateVenueException(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $webRoot = dirname($moduleRoot, 3);
    $studio = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_event_studio/src/Service/EventStudioSaveService.php',
    );

    self::assertStringContainsString('catch (DuplicateVenueException $e)', $studio);
    self::assertStringContainsString('Choose it from your saved venues instead.', $studio);
  }

  /**
   * Ensures the guard remains access-aware and uses conservative matching.
   */
  public function testGuardUsesAccessibleVenuesAndTrustedIdentitySignals(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $guard = (string) file_get_contents($moduleRoot . '/src/Service/VenueDuplicateGuard.php');
    $services = (string) file_get_contents($moduleRoot . '/myeventlane_venue.services.yml');

    self::assertStringContainsString('$this->accessResolver->getAccessibleVenues($account)', $guard);
    self::assertStringContainsString("hasField('enrichment_source_id')", $guard);
    self::assertStringContainsString('$this->scorer->isDuplicate(', $guard);
    self::assertStringContainsString('myeventlane_venue.duplicate_guard:', $services);
    self::assertStringContainsString("- '@myeventlane_venue.duplicate_guard'", $services);
    self::assertStringContainsString("- '@lock'", $services);
  }

}
