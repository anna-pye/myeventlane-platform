<?php

declare(strict_types=1);

namespace Drupal\myeventlane_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Fails social login controls closed until a provider is configured.
 */
final class SocialLoginProviderAvailability {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Whether Google login has the required environment credentials.
   */
  public function googleIsAvailable(): bool {
    if (!$this->moduleHandler->moduleExists('social_auth_google')) {
      return FALSE;
    }

    $config = $this->configFactory->get('social_auth_google.settings');
    $clientId = $config->get('client_id');
    return $this->hasValue($clientId)
      && str_ends_with((string) $clientId, '.apps.googleusercontent.com')
      && $this->hasValue($config->get('client_secret'))
      && $this->hasOAuth2Defaults($config->get('scopes'), $config->get('endpoints'));
  }

  /**
   * Whether Apple login has the required environment credentials.
   */
  public function appleIsAvailable(): bool {
    if (!$this->moduleHandler->moduleExists('social_auth_apple')) {
      return FALSE;
    }

    $config = $this->configFactory->get('social_auth_apple.settings');
    foreach (['client_id', 'team_id', 'key_file_id', 'key_file_path'] as $key) {
      if (!$this->hasValue($config->get($key))) {
        return FALSE;
      }
    }

    $teamId = trim((string) $config->get('team_id'));
    $keyId = trim((string) $config->get('key_file_id'));

    return preg_match('/^[A-Z0-9]{10}$/', $teamId) === 1
      && preg_match('/^[A-Z0-9]{10}$/', $keyId) === 1
      && is_readable((string) $config->get('key_file_path'))
      && $this->hasOAuth2Defaults($config->get('scopes'), $config->get('endpoints'));
  }

  /**
   * Whether a credential/config value is non-empty without exposing it.
   */
  private function hasValue(mixed $value): bool {
    return is_string($value) && trim($value) !== '';
  }

  /**
   * Whether contrib's strictly typed OAuth2 settings are initialised.
   */
  private function hasOAuth2Defaults(mixed $scopes, mixed $endpoints): bool {
    return is_string($scopes) && is_string($endpoints);
  }

}
