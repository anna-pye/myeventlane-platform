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
    return $this->hasValue($config->get('client_id'))
      && $this->hasValue($config->get('client_secret'));
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

    return is_readable((string) $config->get('key_file_path'));
  }

  /**
   * Whether a credential/config value is non-empty without exposing it.
   */
  private function hasValue(mixed $value): bool {
    return is_string($value) && trim($value) !== '';
  }

}
