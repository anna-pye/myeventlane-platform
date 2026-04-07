<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;

/**
 * OAuth2 confidential clients from configuration.
 */
final class OAuthClientRepository {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * @return array<string, mixed>|null
   *   Client row or NULL.
   */
  public function getClient(string $clientId): ?array {
    $clients = $this->configFactory->get('myeventlane_auth.settings')->get('oauth_clients') ?: [];
    if (!is_array($clients) || !isset($clients[$clientId]) || !is_array($clients[$clientId])) {
      return NULL;
    }
    return $clients[$clientId];
  }

  /**
   * Validates client_id + client_secret (timing-safe).
   */
  public function validateConfidentialClient(string $clientId, string $clientSecret): bool {
    $root = Settings::get('myeventlane_auth', []);
    $settingsSecrets = (is_array($root) && isset($root['client_secrets']) && is_array($root['client_secrets']))
      ? $root['client_secrets']
      : [];
    if (isset($settingsSecrets[$clientId]) && is_string($settingsSecrets[$clientId])) {
      return hash_equals($settingsSecrets[$clientId], $clientSecret);
    }

    $row = $this->getClient($clientId);
    if ($row === NULL) {
      return FALSE;
    }
    $hash = (string) ($row['secret_hash'] ?? '');
    if ($hash === '') {
      $this->logger->warning('OAuth client @id has empty secret_hash.', ['@id' => $clientId]);
      return FALSE;
    }
    return password_verify($clientSecret, $hash);
  }

}
