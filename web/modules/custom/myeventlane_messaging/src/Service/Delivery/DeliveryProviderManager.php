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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (for future vendor provider selection).
   */
  public function __construct(
    private readonly DrupalMailProvider $drupalMailProvider,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the delivery provider to use.
   *
   * @param string|null $providerId
   *   Provider id (e.g. 'drupal_mail'). NULL to use default.
   * @param mixed $context
   *   Optional context (e.g. event_id) for vendor-specific provider choice.
   *
   * @return \Drupal\myeventlane_messaging\Service\Delivery\DeliveryProviderInterface
   *   The provider instance.
   */
  public function getProvider(?string $providerId = NULL, $context = NULL): DeliveryProviderInterface {
    $id = $providerId ?? 'drupal_mail';
    // Stub: future Postmark/SendGrid/SES would be selected by id or context.
    if ($id === 'drupal_mail') {
      return $this->drupalMailProvider;
    }
    // Default to Drupal mail for any unknown or stub id.
    return $this->drupalMailProvider;
  }

}
