<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_legal\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the registration consent persistence contract across modules.
 *
 * @group myeventlane_legal
 */
final class RegistrationConsentPersistenceContractTest extends TestCase {

  /**
   * Confirms registration values reach user fields, audit, and messaging.
   */
  public function testRegistrationConsentStorageIsWired(): void {
    $legalPath = dirname(__DIR__, 3);
    $customModulesPath = dirname($legalPath);
    $alter = file_get_contents($legalPath . '/src/Form/UserRegisterLegalAlter.php');
    $module = file_get_contents($legalPath . '/myeventlane_legal.module');
    $install = file_get_contents($legalPath . '/myeventlane_legal.install');
    $messaging = file_get_contents($customModulesPath . '/myeventlane_messaging/myeventlane_messaging.module');

    self::assertIsString($alter);
    self::assertIsString($module);
    self::assertIsString($install);
    self::assertIsString($messaging);
    foreach ([
      'field_customer_terms_version',
      'field_customer_terms_accepted_at',
      'field_privacy_version',
      'field_privacy_accepted_at',
      'field_marketing_opt_in',
      'field_marketing_choice_at',
    ] as $fieldName) {
      self::assertStringContainsString($fieldName, $alter);
      self::assertStringContainsString($fieldName, $install);
    }
    self::assertStringContainsString('function myeventlane_legal_user_insert', $module);
    self::assertStringContainsString('LegalConsentEventInterface::SOURCE_REGISTRATION', $module);
    self::assertStringContainsString('function myeventlane_messaging_user_insert', $messaging);
    self::assertStringContainsString('setMarketingOptOut', $messaging);
  }

}
