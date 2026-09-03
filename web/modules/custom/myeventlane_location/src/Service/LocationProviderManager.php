<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Manages location provider configuration and selection.
 */
final class LocationProviderManager {

  /**
   * The config factory.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * MapKit JWT token generator.
   */
  private MapKitTokenGenerator $tokenGenerator;

  /**
   * Constructs a LocationProviderManager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\myeventlane_location\Service\MapKitTokenGenerator $token_generator
   *   MapKit JWT token generator.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    MapKitTokenGenerator $token_generator,
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('myeventlane_location');
    $this->tokenGenerator = $token_generator;
  }

  /**
   * Gets the default provider.
   *
   * @return string
   *   The provider identifier ('google_maps' or 'apple_maps').
   */
  public function getDefaultProvider(): string {
    $config = $this->configFactory->get('myeventlane_location.settings');
    return $config->get('default_provider') ?? 'apple_maps';
  }

  /**
   * Gets Google Maps API key.
   *
   * @return string
   *   The API key, or empty string if not configured.
   */
  public function getGoogleMapsApiKey(): string {
    $config = $this->configFactory->get('myeventlane_location.settings');
    $key = $config->get('google_maps_api_key') ?? '';
    // Allow override from settings.php or environment.
    if (empty($key) && defined('MYEVENTLANE_GOOGLE_MAPS_API_KEY')) {
      $key = MYEVENTLANE_GOOGLE_MAPS_API_KEY;
    }
    return $key;
  }

  /**
   * Gets Apple Maps Team ID.
   *
   * @return string
   *   The Team ID, or empty string if not configured.
   */
  public function getAppleMapsTeamId(): string {
    $config = $this->configFactory->get('myeventlane_location.settings');
    $team_id = $config->get('apple_maps_team_id') ?? '';
    // Allow override from settings.php or environment.
    if (empty($team_id) && defined('MYEVENTLANE_APPLE_MAPS_TEAM_ID')) {
      $team_id = MYEVENTLANE_APPLE_MAPS_TEAM_ID;
    }
    return $team_id;
  }

  /**
   * Gets Apple Maps Key ID.
   *
   * @return string
   *   The Key ID, or empty string if not configured.
   */
  public function getAppleMapsKeyId(): string {
    $config = $this->configFactory->get('myeventlane_location.settings');
    $key_id = $config->get('apple_maps_key_id') ?? '';
    // Allow override from settings.php or environment.
    if (empty($key_id) && defined('MYEVENTLANE_APPLE_MAPS_KEY_ID')) {
      $key_id = MYEVENTLANE_APPLE_MAPS_KEY_ID;
    }
    return $key_id;
  }

  /**
   * Gets Apple Maps private key.
   *
   * @return string
   *   The private key in PEM format, or empty string if not configured.
   */
  public function getAppleMapsPrivateKey(): string {
    $config = $this->configFactory->get('myeventlane_location.settings');
    $private_key = $config->get('apple_maps_private_key') ?? '';
    // Allow override from settings.php or environment.
    if (empty($private_key) && defined('MYEVENTLANE_APPLE_MAPS_PRIVATE_KEY')) {
      $private_key = MYEVENTLANE_APPLE_MAPS_PRIVATE_KEY;
    }
    return $private_key;
  }

  /**
   * Checks if a provider is properly configured.
   *
   * @param string $provider
   *   The provider identifier.
   *
   * @return bool
   *   TRUE if the provider has all required configuration.
   */
  public function isProviderConfigured(string $provider): bool {
    switch ($provider) {
      case 'google_maps':
        return !empty($this->getGoogleMapsApiKey());

      case 'apple_maps':
        return $this->tokenGenerator->isConfigured();

      default:
        return FALSE;
    }
  }

  /**
   * Gets public-safe settings for frontend JavaScript.
   *
   * For Apple Maps this includes a short-lived MapKit JWT. The private key is
   * never exposed to the browser.
   *
   * @return array
   *   An array with provider and public API keys / tokens.
   */
  public function getFrontendSettings(): array {
    $provider = $this->getDefaultProvider();
    $settings = [
      'provider' => $provider,
    ];

    if ($provider === 'google_maps') {
      $settings['google_maps_api_key'] = $this->getGoogleMapsApiKey();
    }
    elseif ($provider === 'apple_maps') {
      // Team/Key IDs are non-secret identifiers; JWT is required by MapKit JS.
      $settings['apple_maps_team_id'] = $this->getAppleMapsTeamId();
      $settings['apple_maps_key_id'] = $this->getAppleMapsKeyId();
      $token = $this->tokenGenerator->generateToken();
      if ($token !== '') {
        $settings['apple_maps_token'] = $token;
      }
      else {
        $this->logger->warning('Apple Maps is the default provider but MapKit JWT generation returned an empty token.');
      }
    }

    return $settings;
  }

}
