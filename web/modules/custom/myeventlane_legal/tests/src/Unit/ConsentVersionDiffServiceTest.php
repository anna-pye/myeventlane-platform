<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_legal\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_legal\Service\ConsentVersionDiffService;
use Drupal\myeventlane_legal\Service\LegalSettingsService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for ConsentVersionDiffService.
 *
 * @group myeventlane_legal
 */
final class ConsentVersionDiffServiceTest extends UnitTestCase {

  /**
   * Tests no prior consent when email and uid are empty.
   */
  public function testNoPriorConsentWhenContextEmpty(): void {
    $service = $this->createService('2.0', '2.0', []);

    $status = $service->getRsvpConsentStatus(NULL, NULL, NULL);

    $this->assertFalse($status['has_prior_consent']);
    $this->assertFalse($status['requires_reconsent']);
    $this->assertSame('2.0', $status['current_terms_version']);
    $this->assertSame('2.0', $status['current_privacy_version']);
  }

  /**
   * Tests no prior consent when email is empty and uid is 0.
   */
  public function testNoPriorConsentWhenBothEmpty(): void {
    $service = $this->createService('1.0', '1.0', []);

    $status = $service->getRsvpConsentStatus('', 0, 1);

    $this->assertFalse($status['has_prior_consent']);
    $this->assertFalse($status['requires_reconsent']);
  }

  /**
   * Tests structure of returned array when no prior consent.
   */
  public function testReturnStructure(): void {
    $service = $this->createService('1.0', '1.0', []);

    $status = $service->getRsvpConsentStatus('test@example.com', NULL, 1);

    $this->assertArrayHasKey('has_prior_consent', $status);
    $this->assertArrayHasKey('requires_reconsent', $status);
    $this->assertArrayHasKey('current_terms_version', $status);
    $this->assertArrayHasKey('current_privacy_version', $status);
    $this->assertArrayHasKey('last_terms_version', $status);
    $this->assertArrayHasKey('last_privacy_version', $status);
    $this->assertArrayHasKey('terms_changed', $status);
    $this->assertArrayHasKey('privacy_changed', $status);
    $this->assertNull($status['last_terms_version']);
    $this->assertNull($status['last_privacy_version']);
  }

  /**
   * Creates the service with mocked dependencies.
   *
   * @param string $termsVersion
   *   Current terms version from settings.
   * @param string $privacyVersion
   *   Current privacy version from settings.
   * @param array $consentEventIds
   *   Empty array for no prior consent; non-empty to simulate found events.
   */
  private function createService(string $termsVersion, string $privacyVersion, array $consentEventIds): ConsentVersionDiffService {
    $settings = $this->createMock(LegalSettingsService::class);
    $settings->method('getCustomerTermsVersion')->willReturn($termsVersion);
    $settings->method('getPrivacyVersion')->willReturn($privacyVersion);
    $logger = $this->createMock(\Drupal\Core\Logger\LoggerChannelInterface::class);
    $settings->method('getLogger')->willReturn($logger);

    $query = $this->createMock(\Drupal\Core\Entity\Query\QueryInterface::class);
    $query->method('execute')->willReturn($consentEventIds);

    $storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('legal_consent_event')->willReturn($storage);

    $currentUser = $this->createMock(AccountProxyInterface::class);

    return new ConsentVersionDiffService($entityTypeManager, $settings, $currentUser);
  }

}
