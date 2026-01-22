<?php

declare(strict_types=1);

namespace Drupal\myeventlane_api\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service for API authentication using vendor API keys.
 */
final class ApiAuthenticationService {

  /**
   * Constructs ApiAuthenticationService.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Authenticates a request using Bearer token.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The authenticated vendor entity, or NULL if authentication failed.
   */
  public function authenticate(Request $request): ?Vendor {
    $auth_header = $request->headers->get('Authorization');
    if (!$auth_header) {
      return NULL;
    }

    // Extract Bearer token.
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
      return NULL;
    }

    $token = $matches[1];
    if (empty($token)) {
      return NULL;
    }

    return $this->authenticateToken($token);
  }

  /**
   * Authenticates an API token and returns the vendor.
   *
   * @param string $token
   *   The plain API token.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity if authentication succeeds, NULL otherwise.
   */
  public function authenticateToken(string $token): ?Vendor {
    if (empty($token)) {
      return NULL;
    }

    // We can't query by hash directly since password_hash generates different
    // hashes each time. Instead, we need to load all vendors with API keys
    // and verify the token against each hash.
    // In practice, this could be optimized with a token->vendor lookup table
    // or by using a deterministic hash (like SHA-256) for lookup and
    // password_hash for storage. For now, we'll iterate.
    // @todo Optimize this for large vendor counts with a lookup table.
    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('api_key_hash', NULL, 'IS NOT NULL')
      ->condition('api_key_hash', '', '<>');

    $vendor_ids = $query->execute();
    if (empty($vendor_ids)) {
      return NULL;
    }

    $vendors = $storage->loadMultiple($vendor_ids);
    foreach ($vendors as $vendor) {
      $hash = $vendor->getApiKeyHash();
      if ($hash && $this->verifyToken($token, $hash)) {
        return $vendor;
      }
    }

    return NULL;
  }

  /**
   * Generates a new API key token.
   *
   * @return string
   *   A random API key token (plain text - must be shown to user once).
   */
  public function generateToken(): string {
    // Generate a secure random token.
    return bin2hex(random_bytes(32));
  }

  /**
   * Hashes an API token for storage.
   *
   * @param string $token
   *   The plain API token.
   *
   * @return string
   *   The hashed token.
   */
  public function hashToken(string $token): string {
    // Use password_hash with PASSWORD_DEFAULT for secure hashing.
    return password_hash($token, PASSWORD_DEFAULT);
  }

  /**
   * Verifies a token against a hash.
   *
   * @param string $token
   *   The plain token.
   * @param string $hash
   *   The stored hash.
   *
   * @return bool
   *   TRUE if the token matches the hash.
   */
  public function verifyToken(string $token, string $hash): bool {
    return password_verify($token, $hash);
  }

  /**
   * Regenerates the API key for a vendor.
   *
   * @param \Drupal\myeventlane_vendor\Entity\Vendor $vendor
   *   The vendor entity.
   *
   * @return string
   *   The new plain API key (must be shown to user once).
   */
  public function regenerateApiKey(Vendor $vendor): string {
    $token = $this->generateToken();
    $hash = $this->hashToken($token);
    $vendor->setApiKeyHash($hash);
    $vendor->save();
    return $token;
  }

}
