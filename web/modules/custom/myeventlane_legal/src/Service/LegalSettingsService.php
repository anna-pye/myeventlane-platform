<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Provides legal policy versions and URLs from config.
 */
final class LegalSettingsService {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Gets the legal settings config.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config object.
   */
  public function getConfig(): \Drupal\Core\Config\ImmutableConfig {
    return $this->configFactory->get('myeventlane_legal.settings');
  }

  /**
   * Gets customer terms version.
   */
  public function getCustomerTermsVersion(): string {
    return (string) ($this->getConfig()->get('customer_terms_version') ?? '1.0');
  }

  /**
   * Gets vendor terms version.
   */
  public function getVendorTermsVersion(): string {
    return (string) ($this->getConfig()->get('vendor_terms_version') ?? '1.0');
  }

  /**
   * Gets privacy policy version.
   */
  public function getPrivacyVersion(): string {
    return (string) ($this->getConfig()->get('privacy_version') ?? '1.0');
  }

  /**
   * Gets customer terms URL.
   */
  public function getCustomerTermsUrl(): string {
    return (string) ($this->getConfig()->get('customer_terms_url') ?? '');
  }

  /**
   * Gets vendor terms URL.
   */
  public function getVendorTermsUrl(): string {
    return (string) ($this->getConfig()->get('vendor_terms_url') ?? '');
  }

  /**
   * Gets privacy policy URL.
   */
  public function getPrivacyUrl(): string {
    return (string) ($this->getConfig()->get('privacy_url') ?? '');
  }

  /**
   * Gets refund policy URL.
   */
  public function getRefundPolicyUrl(): string {
    return (string) ($this->getConfig()->get('refund_policy_url') ?? '');
  }

  /**
   * Gets cookie policy URL.
   */
  public function getCookiePolicyUrl(): string {
    return (string) ($this->getConfig()->get('cookie_policy_url') ?? '');
  }

  /**
   * Gets collection notice text for RSVP forms (APP 5).
   */
  public function getCollectionNoticeRsvp(): string {
    return (string) ($this->getConfig()->get('collection_notice_rsvp') ?? '');
  }

  /**
   * Gets collection notice text for checkout (APP 5).
   */
  public function getCollectionNoticeCheckout(): string {
    return (string) ($this->getConfig()->get('collection_notice_checkout') ?? '');
  }

  /**
   * Whether to store vendor IP/UA on terms acceptance.
   */
  public function storeVendorIpUa(): bool {
    return (bool) ($this->getConfig()->get('store_vendor_ip_ua') ?? FALSE);
  }

  /**
   * Gets the logger channel.
   */
  public function getLogger(): LoggerChannelInterface {
    return $this->loggerFactory->get('myeventlane_legal');
  }

}
