<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_location\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_location\Service\MapKitTokenGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Covers environment-owned MapKit credentials.
 *
 * @group myeventlane_location
 */
final class MapKitTokenGeneratorEnvironmentTest extends TestCase {

  /**
   * Environment variables used by this test.
   */
  private const ENVIRONMENT_NAMES = [
    'MEL_APPLE_MAPS_TEAM_ID',
    'MEL_APPLE_MAPS_KEY_ID',
    'MEL_APPLE_MAPS_PRIVATE_KEY_PATH',
    'MEL_APPLE_MAPS_ORIGIN',
  ];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (self::ENVIRONMENT_NAMES as $name) {
      putenv($name);
      unset($_ENV[$name], $_SERVER[$name]);
    }
    parent::tearDown();
  }

  /**
   * A missing key file keeps the provider unconfigured.
   */
  public function testMissingKeyFileIsRejected(): void {
    $this->setCredentialEnvironment('/path/that/does/not/exist.p8');

    self::assertFalse($this->createGenerator()->isConfigured());
  }

  /**
   * An empty key file keeps the provider unconfigured.
   */
  public function testEmptyKeyFileIsRejected(): void {
    $path = tempnam(sys_get_temp_dir(), 'mapkit-empty-');
    self::assertIsString($path);

    try {
      $this->setCredentialEnvironment($path);
      self::assertFalse($this->createGenerator()->isConfigured());
    }
    finally {
      unlink($path);
    }
  }

  /**
   * An unreadable key file keeps the provider unconfigured.
   */
  public function testUnreadableKeyFileIsRejected(): void {
    $path = tempnam(sys_get_temp_dir(), 'mapkit-unreadable-');
    self::assertIsString($path);
    self::assertNotFalse(file_put_contents($path, 'not-used'));
    self::assertTrue(chmod($path, 0000));

    try {
      $this->setCredentialEnvironment($path);
      self::assertFalse($this->createGenerator()->isConfigured());
    }
    finally {
      chmod($path, 0600);
      unlink($path);
    }
  }

  /**
   * A valid environment-owned key produces a browser-scoped JWT.
   */
  public function testValidKeyFileGeneratesTokenWithRequestOrigin(): void {
    $key = openssl_pkey_new([
      'private_key_type' => OPENSSL_KEYTYPE_EC,
      'curve_name' => 'prime256v1',
    ]);
    self::assertNotFalse($key);
    self::assertTrue(openssl_pkey_export($key, $privateKey));

    $path = tempnam(sys_get_temp_dir(), 'mapkit-valid-');
    self::assertIsString($path);
    self::assertNotFalse(file_put_contents($path, $privateKey));

    try {
      $this->setCredentialEnvironment($path);
      $generator = $this->createGenerator('https://staging.example.test', TRUE);
      self::assertTrue($generator->isConfigured());
      $token = $generator->generateToken();
      $segments = explode('.', $token);

      self::assertCount(3, $segments);
      $payload = json_decode($this->decodeBase64Url($segments[1]), TRUE, flags: JSON_THROW_ON_ERROR);
      self::assertSame('TEAM123456', $payload['iss']);
      self::assertSame('https://staging.example.test', $payload['origin']);
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Creates the service with empty Drupal configuration.
   */
  private function createGenerator(?string $origin = NULL, bool $expectNoError = FALSE): MapKitTokenGenerator {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerChannelInterface::class);
    if ($expectNoError) {
      $logger->expects(self::never())->method('error');
    }
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $requestStack = new RequestStack();
    if ($origin !== NULL) {
      $requestStack->push(Request::create($origin));
    }

    return new MapKitTokenGenerator($configFactory, $loggerFactory, $requestStack);
  }

  /**
   * Sets complete non-secret identifiers and the supplied key path.
   */
  private function setCredentialEnvironment(string $keyPath): void {
    $values = [
      'MEL_APPLE_MAPS_TEAM_ID' => 'TEAM123456',
      'MEL_APPLE_MAPS_KEY_ID' => 'KEY1234567',
      'MEL_APPLE_MAPS_PRIVATE_KEY_PATH' => $keyPath,
    ];
    foreach ($values as $name => $value) {
      putenv($name . '=' . $value);
      $_ENV[$name] = $value;
      $_SERVER[$name] = $value;
    }
  }

  /**
   * Decodes one JWT base64url segment.
   */
  private function decodeBase64Url(string $value): string {
    $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
    $decoded = base64_decode(strtr($value, '-_', '+/'), TRUE);
    self::assertIsString($decoded);
    return $decoded;
  }

}
