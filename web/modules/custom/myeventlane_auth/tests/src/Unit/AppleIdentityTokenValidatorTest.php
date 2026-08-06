<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_auth\Unit;

use Drupal\myeventlane_auth\SocialAuth\AppleIdentityTokenValidator;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_auth\SocialAuth\AppleIdentityTokenValidator
 * @group myeventlane_auth
 */
final class AppleIdentityTokenValidatorTest extends TestCase {

  private const CLIENT_ID = 'com.myeventlane.myeventlane';
  private const NONCE = 'test-browser-session-nonce';

  /**
   * @covers ::validate
   */
  public function testValidSignedAppleIdentityTokenIsAccepted(): void {
    [$privateKey, $jwk] = $this->createRsaKeyPair();
    $token = JWT::encode($this->validClaims(), $privateKey, 'RS256', 'test-key');
    $validator = $this->validatorForJwk($jwk);

    $claims = $validator->validate($token, self::CLIENT_ID, self::NONCE);

    self::assertSame('apple-user-123', $claims['sub']);
  }

  /**
   * @covers ::validateClaims
   */
  #[DataProvider('invalidClaimsProvider')]
  public function testRequiredAppleClaimsFailClosed(array $changes): void {
    $validator = $this->validatorForJwk([]);
    $claims = array_replace($this->validClaims(), $changes);

    $this->expectException(\UnexpectedValueException::class);
    $validator->validateClaims($claims, self::CLIENT_ID, self::NONCE);
  }

  /**
   * Invalid issuer, audience, expiry, subject and nonce examples.
   */
  public static function invalidClaimsProvider(): array {
    return [
      'issuer' => [['iss' => 'https://example.com']],
      'audience' => [['aud' => 'different-client']],
      'expiry' => [['exp' => 1]],
      'subject' => [['sub' => '']],
      'nonce' => [['nonce' => 'different-nonce']],
    ];
  }

  /**
   * Provides a complete valid Apple identity-token claim set.
   *
   * @return array<string, mixed>
   *   Valid claims.
   */
  private function validClaims(): array {
    return [
      'iss' => 'https://appleid.apple.com',
      'aud' => self::CLIENT_ID,
      'exp' => time() + 300,
      'iat' => time(),
      'sub' => 'apple-user-123',
      'nonce' => self::NONCE,
    ];
  }

  /**
   * Creates a disposable RSA key pair and its public JWK.
   *
   * @return array{0: string, 1: array<string, string>}
   *   The PEM private key and public JWK.
   */
  private function createRsaKeyPair(): array {
    $resource = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    self::assertNotFalse($resource);
    self::assertTrue(openssl_pkey_export($resource, $privateKey));
    $details = openssl_pkey_get_details($resource);
    self::assertIsArray($details);

    return [
      $privateKey,
      [
        'kty' => 'RSA',
        'kid' => 'test-key',
        'use' => 'sig',
        'alg' => 'RS256',
        'n' => $this->base64UrlEncode($details['rsa']['n']),
        'e' => $this->base64UrlEncode($details['rsa']['e']),
      ],
    ];
  }

  /**
   * Creates a validator backed by a deterministic Apple key response.
   */
  private function validatorForJwk(array $jwk): AppleIdentityTokenValidator {
    $handler = new MockHandler([
      new Response(200, [], json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR)),
    ]);
    return new AppleIdentityTokenValidator(new Client([
      'handler' => HandlerStack::create($handler),
    ]));
  }

  /**
   * Encodes binary RSA parameters for a JSON Web Key.
   */
  private function base64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

}
