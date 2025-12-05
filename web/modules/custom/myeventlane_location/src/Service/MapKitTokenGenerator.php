<?php

declare(strict_types=1);

namespace Drupal\myeventlane_location\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Generates MapKit JS JWT tokens for Apple Maps integration.
 *
 * This service generates JWT tokens required for MapKit JS authentication.
 * The token is signed using the Apple-provided private key.
 *
 * @see https://developer.apple.com/documentation/mapkitjs/creating_an_api_key
 */
final class MapKitTokenGenerator {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  /**
   * Constructs a MapKitTokenGenerator.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('myeventlane_location');
  }

  /**
   * Generates a MapKit JS JWT token.
   *
   * @return string
   *   The JWT token, or empty string if generation fails.
   *
   * @todo Implement JWT signing using firebase/php-jwt or similar library.
   *   The token should include:
   *   - Header: {"alg": "ES256", "kid": "<KEY_ID>", "typ": "JWT"}
   *   - Payload: {"iss": "<TEAM_ID>", "iat": <timestamp>, "exp": <timestamp+3600>}
   *   - Signature: ES256 signature using the private key
   *
   *   Example implementation:
   *   ```php
   *   use Firebase\JWT\JWT;
   *   use Firebase\JWT\Key;
   *
   *   $config = $this->configFactory->get('myeventlane_location.settings');
   *   $team_id = $config->get('apple_maps_team_id');
   *   $key_id = $config->get('apple_maps_key_id');
   *   $private_key = $config->get('apple_maps_private_key');
   *
   *   if (empty($team_id) || empty($key_id) || empty($private_key)) {
   *     return '';
   *   }
   *
   *   $header = ['alg' => 'ES256', 'kid' => $key_id, 'typ' => 'JWT'];
   *   $payload = [
   *     'iss' => $team_id,
   *     'iat' => time(),
   *     'exp' => time() + 3600,
   *   ];
   *
   *   return JWT::encode($payload, $private_key, 'ES256', $key_id, $header);
   *   ```
   */
  public function generateToken(): string {
    $config = $this->configFactory->get('myeventlane_location.settings');
    $team_id = $config->get('apple_maps_team_id');
    $key_id = $config->get('apple_maps_key_id');
    $private_key = $config->get('apple_maps_private_key');

    // Allow override from settings.php or environment.
    if (empty($team_id) && defined('MYEVENTLANE_APPLE_MAPS_TEAM_ID')) {
      $team_id = MYEVENTLANE_APPLE_MAPS_TEAM_ID;
    }
    if (empty($key_id) && defined('MYEVENTLANE_APPLE_MAPS_KEY_ID')) {
      $key_id = MYEVENTLANE_APPLE_MAPS_KEY_ID;
    }
    if (empty($private_key) && defined('MYEVENTLANE_APPLE_MAPS_PRIVATE_KEY')) {
      $private_key = MYEVENTLANE_APPLE_MAPS_PRIVATE_KEY;
    }

    if (empty($team_id) || empty($key_id) || empty($private_key)) {
      $this->logger->warning('MapKit token generation failed: missing credentials. Configure Team ID, Key ID, and private key in location settings or settings.php.');
      return '';
    }

    // TODO: Implement JWT signing.
    // For now, return empty string to indicate token generation is not yet implemented.
    // To implement:
    // 1. Add firebase/php-jwt to composer.json: composer require firebase/php-jwt
    // 2. Uncomment and adapt the example code in the docblock above.
    // 3. Ensure the private key is in PEM format and properly formatted.

    $this->logger->warning('MapKit token generation not yet implemented. Install firebase/php-jwt and implement JWT signing.');
    return '';
  }

  /**
   * Checks if token generation is properly configured.
   *
   * @return bool
   *   TRUE if all required credentials are available.
   */
  public function isConfigured(): bool {
    $config = $this->configFactory->get('myeventlane_location.settings');
    $team_id = $config->get('apple_maps_team_id');
    $key_id = $config->get('apple_maps_key_id');
    $private_key = $config->get('apple_maps_private_key');

    // Check for settings.php overrides.
    if (empty($team_id) && defined('MYEVENTLANE_APPLE_MAPS_TEAM_ID')) {
      $team_id = MYEVENTLANE_APPLE_MAPS_TEAM_ID;
    }
    if (empty($key_id) && defined('MYEVENTLANE_APPLE_MAPS_KEY_ID')) {
      $key_id = MYEVENTLANE_APPLE_MAPS_KEY_ID;
    }
    if (empty($private_key) && defined('MYEVENTLANE_APPLE_MAPS_PRIVATE_KEY')) {
      $private_key = MYEVENTLANE_APPLE_MAPS_PRIVATE_KEY;
    }

    return !empty($team_id) && !empty($key_id) && !empty($private_key);
  }

}




