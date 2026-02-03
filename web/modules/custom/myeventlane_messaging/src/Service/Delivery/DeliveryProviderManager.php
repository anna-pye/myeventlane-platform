<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service\Delivery;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves and returns the delivery provider for sending messages.
 *
 * Vendors may choose provider via config; defaults to drupal_mail.
 */
final class DeliveryProviderManager {

  /**
   * Constructs DeliveryProviderManager.
   *
   * @param \Drupal\myeventlane_messaging\Service\Delivery\DrupalMailProvider $drupalMailProvider
   *   The default Drupal mail provider.
   * @param \Drupal\myeventlane_messaging\Service\Delivery\PostmarkDeliveryProvider|null $postmarkProvider
   *   The Postmark provider (optional).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (for provider selection).
   */
  public function __construct(
    private readonly DrupalMailProvider $drupalMailProvider,
    private readonly ?PostmarkDeliveryProvider $postmarkProvider,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the delivery provider to use.
   *
   * @param string|null $providerId
   *   Provider id (e.g. 'drupal_mail', 'postmark'). NULL to use default from config.
   * @param mixed $context
   *   Optional context (e.g. event_id) for vendor-specific provider choice.
   *
   * @return \Drupal\myeventlane_messaging\Service\Delivery\DeliveryProviderInterface
   *   The provider instance.
   */
  public function getProvider(?string $providerId = NULL, $context = NULL): DeliveryProviderInterface {
    // If provider ID not specified, check config for default.
    if ($providerId === NULL) {
      $config = $this->configFactory->get('myeventlane_messaging.settings');
      $providerId = $config->get('default_provider') ?? 'drupal_mail';
    }

    if ($providerId === 'postmark' && $this->postmarkProvider) {
      return $this->postmarkProvider;
    }

    // Default to Drupal mail.
    return $this->drupalMailProvider;
  }

}
