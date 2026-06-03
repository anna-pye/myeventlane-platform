<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service\Delivery;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

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
   * @param \Drupal\myeventlane_messaging\Service\Delivery\PostmarkDeliveryProvider $postmarkProvider
   *   The Postmark delivery provider (registered; transport activation deferred).
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory for site delivery provider selection.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel for unknown provider warnings.
   */
  public function __construct(
    private readonly DrupalMailProvider $drupalMailProvider,
    private readonly PostmarkDeliveryProvider $postmarkProvider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the delivery provider to use.
   *
   * @param string|null $providerId
   *   Provider id (e.g. 'drupal_mail'). NULL to use configured default.
   * @param mixed $context
   *   Optional context (e.g. event_id) for vendor-specific provider choice.
   *
   * @return \Drupal\myeventlane_messaging\Service\Delivery\DeliveryProviderInterface
   *   The provider instance.
   */
  public function getProvider(?string $providerId = NULL, $context = NULL): DeliveryProviderInterface {
    $id = $providerId ?? $this->getConfiguredProviderId();
    return $this->resolveProviderById($id);
  }

  /**
   * Reads delivery_provider from site messaging settings.
   */
  private function getConfiguredProviderId(): string {
    $config = $this->configFactory->get('myeventlane_messaging.settings');
    $id = $config->get('delivery_provider');
    return is_string($id) && $id !== '' ? $id : 'drupal_mail';
  }

  /**
   * Maps a provider id to a registered provider instance.
   */
  private function resolveProviderById(string $id): DeliveryProviderInterface {
    if ($id === 'drupal_mail') {
      return $this->drupalMailProvider;
    }
    if ($id === 'postmark') {
      return $this->postmarkProvider;
    }
    $this->logger->warning('Unknown delivery provider "@id"; falling back to drupal_mail.', [
      '@id' => $id,
    ]);
    return $this->drupalMailProvider;
  }

}
