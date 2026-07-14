<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Signs Apple Wallet pass manifests with configured PKCS#7 credentials.
 *
 * Certificate material comes from Drupal $settings / environment paths only —
 * never from exported config/sync.
 */
final class WalletSigner {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether Apple signing material is present and loadable.
   */
  public function isReady(): bool {
    try {
      $this->loadSigningMaterial();
      return TRUE;
    }
    catch (RuntimeException) {
      return FALSE;
    }
  }

  /**
   * Signs a pass manifest.json file and returns the raw PKCS#7 DER signature.
   *
   * @param string $manifestPath
   *   Absolute path to manifest.json.
   *
   * @throws \RuntimeException
   *   When signing material is missing or OpenSSL signing fails.
   */
  public function sign(string $manifestPath): string {
    if (!is_file($manifestPath) || !is_readable($manifestPath)) {
      throw new RuntimeException('Apple Wallet manifest is missing or unreadable.');
    }

    $material = $this->loadSigningMaterial();
    $signaturePath = $manifestPath . '.sig.smime';
    $extraCerts = $material['wwdr_path'];

    // Prefer file:// URIs so OpenSSL loads material via filesystem APIs.
    $cert_uri = 'file://' . $material['certificate_path'];
    $key_uri = $material['private_key_password'] !== ''
      ? ['file://' . $material['private_key_path'], $material['private_key_password']]
      : 'file://' . $material['private_key_path'];

    while (openssl_error_string() !== FALSE) {
      // Drain stale OpenSSL error queue before signing.
    }

    $signed = openssl_pkcs7_sign(
      $manifestPath,
      $signaturePath,
      $cert_uri,
      $key_uri,
      [],
      PKCS7_BINARY | PKCS7_DETACHED,
      $extraCerts,
    );

    if ($signed !== TRUE || !is_file($signaturePath)) {
      $openssl_error = trim((string) openssl_error_string());
      $this->logger->error('Apple Wallet PKCS#7 signing failed.');
      throw new RuntimeException(
        $openssl_error !== ''
          ? 'Apple Wallet PKCS#7 signing failed: ' . $openssl_error
          : 'Apple Wallet PKCS#7 signing failed.',
      );
    }

    try {
      return $this->extractDetachedSignatureDer($signaturePath);
    }
    finally {
      @unlink($signaturePath);
    }
  }

  /**
   * Loads certificate / key material from site settings paths.
   *
   * @return array{certificate_path: string, private_key_path: string, private_key_password: string, wwdr_path: string}
   *   Absolute credential paths validated for OpenSSL.
   */
  private function loadSigningMaterial(): array {
    $paths = $this->credentialPaths();
    foreach (['certificate_path', 'private_key_path', 'wwdr_certificate_path'] as $key) {
      $path = $paths[$key];
      if ($path === '' || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Apple Wallet signing credential path is not readable.');
      }
      // Normalise to realpath for file:// OpenSSL URIs.
      $real = realpath($path);
      if ($real === FALSE) {
        throw new RuntimeException('Apple Wallet signing credential path could not be resolved.');
      }
      $paths[$key] = $real;
    }

    $certificate = @file_get_contents($paths['certificate_path']);
    $private_key_pem = @file_get_contents($paths['private_key_path']);
    if (!is_string($certificate) || $certificate === '' || !is_string($private_key_pem) || $private_key_pem === '') {
      throw new RuntimeException('Apple Wallet signing credential contents are empty.');
    }

    if (@openssl_x509_read($certificate) === FALSE) {
      throw new RuntimeException('Apple Wallet pass certificate is invalid.');
    }

    $password = $paths['private_key_password'];
    $key = $password !== ''
      ? [$private_key_pem, $password]
      : $private_key_pem;

    if (@openssl_pkey_get_private($key) === FALSE) {
      throw new RuntimeException('Apple Wallet private key is invalid.');
    }

    if (@openssl_x509_read((string) file_get_contents($paths['wwdr_certificate_path'])) === FALSE) {
      throw new RuntimeException('Apple WWDR certificate is invalid.');
    }

    // Config keys are checked by the presentation gate; keep signer credential-focused.
    $config = $this->configFactory->get('myeventlane_wallet.settings');
    if (trim((string) $config->get('apple_team_id')) === '' || trim((string) $config->get('apple_pass_type_id')) === '') {
      throw new RuntimeException('Apple Wallet team ID or pass type ID is not configured.');
    }

    return [
      'certificate_path' => $paths['certificate_path'],
      'private_key_path' => $paths['private_key_path'],
      'private_key_password' => $password,
      'wwdr_path' => $paths['wwdr_certificate_path'],
    ];
  }

  /**
   * Resolves credential filesystem paths from Settings (never config export).
   *
   * @return array{certificate_path: string, private_key_path: string, wwdr_certificate_path: string, private_key_password: string}
   *   Absolute or relative credential paths.
   */
  private function credentialPaths(): array {
    $wallet = Settings::get('myeventlane_wallet', []);
    if (!is_array($wallet)) {
      $wallet = [];
    }

    return [
      'certificate_path' => trim((string) ($wallet['apple_certificate_path'] ?? '')),
      'private_key_path' => trim((string) ($wallet['apple_private_key_path'] ?? '')),
      'wwdr_certificate_path' => trim((string) ($wallet['apple_wwdr_certificate_path'] ?? '')),
      'private_key_password' => (string) ($wallet['apple_private_key_password'] ?? ''),
    ];
  }

  /**
   * Strips S/MIME headers from openssl_pkcs7_sign output to leave DER bytes.
   */
  private function extractDetachedSignatureDer(string $smimePath): string {
    $smime = file_get_contents($smimePath);
    if (!is_string($smime) || $smime === '') {
      throw new RuntimeException('Apple Wallet signature file is empty.');
    }

    $normalized = str_replace("\r\n", "\n", $smime);
    $marker = 'filename="smime.p7s"';
    $start = strpos($normalized, $marker);
    if ($start !== FALSE) {
      $normalized = substr($normalized, $start + strlen($marker));
    }
    else {
      $parts = explode("\n\n", $normalized, 2);
      $normalized = $parts[1] ?? '';
    }

    $end = strpos($normalized, '------');
    if ($end !== FALSE) {
      $normalized = substr($normalized, 0, $end);
    }

    $normalized = preg_replace('/\s+/', '', $normalized) ?? '';
    $der = base64_decode($normalized, TRUE);
    if ($der === FALSE || $der === '') {
      throw new RuntimeException('Apple Wallet signature could not be decoded.');
    }
    return $der;
  }

}
