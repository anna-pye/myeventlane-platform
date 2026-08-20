<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Builds a read-only MEL Pro activation readiness report.
 */
final class ProActivationReadinessChecker {

  private const EXPECTED_PRICE = '49.00';
  private const MAX_CRON_AGE = 7200;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StorageInterface $activeStorage,
    private readonly StorageInterface $syncStorage,
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly ProProductResolver $productResolver,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns a non-mutating activation report for staging or production.
   *
   * @return array{environment: string, ready: bool, checks: list<array{id: string, status: string, message: string}>}
   *   The readiness report.
   */
  public function check(string $environment): array {
    if (!in_array($environment, ['staging', 'production'], TRUE)) {
      throw new \InvalidArgumentException('Environment must be staging or production.');
    }

    $checks = [];
    $expectedMode = $environment === 'production' ? 'live' : 'test';

    $standard = $this->gatewaySnapshot('stripe');
    $recurring = $this->gatewaySnapshot('stripe_pe_recurring');

    $this->add($checks, 'gateway.standard', $standard['enabled'] && $standard['plugin'] === 'stripe' ? 'pass' : 'fail',
      'Ordinary checkout gateway must be enabled with the stripe plugin.');
    $this->add($checks, 'gateway.recurring', $recurring['enabled'] && $recurring['plugin'] === 'mel_pro_stripe_payment_element' ? 'pass' : 'fail',
      'MEL Pro must use the dedicated recurring Payment Element gateway.');
    $this->add($checks, 'gateway.recurring_usage', $recurring['usage'] === 'off_session' ? 'pass' : 'fail',
      'MEL Pro payment methods must support off-session renewals.');
    $this->add($checks, 'gateway.mode', $standard['mode'] === $expectedMode && $recurring['mode'] === $expectedMode ? 'pass' : 'fail',
      sprintf('Both gateways must use %s mode in %s.', $expectedMode, $environment));

    foreach (['stripe' => $standard, 'stripe_pe_recurring' => $recurring] as $id => $gateway) {
      $this->add($checks, 'gateway.' . $id . '.credentials', $gateway['publishable_key_present'] && $gateway['secret_key_present'] ? 'pass' : 'fail',
        sprintf('%s must have a publishable key and server key.', $id));
      $keyStatus = $gateway['key_type'] === 'restricted'
        ? 'pass'
        : ($environment === 'production' ? 'fail' : 'warn');
      $this->add($checks, 'gateway.' . $id . '.least_privilege', $keyStatus,
        sprintf('%s should use a dedicated restricted Stripe key.', $id));
      $this->add($checks, 'gateway.' . $id . '.secret_storage', $this->gatewaySecretsAreRuntimeOnly($id) ? 'pass' : 'fail',
        sprintf('%s server and webhook secrets must not be stored in active or exported configuration.', $id));
    }

    $sameServerKey = $standard['secret_key_present']
      && $recurring['secret_key_present']
      && hash_equals($standard['secret_key'], $recurring['secret_key']);
    $this->add($checks, 'gateway.key_separation', !$sameServerKey ? 'pass' : ($environment === 'production' ? 'fail' : 'warn'),
      'Ordinary checkout and MEL Pro should use separate restricted keys, even when they share one Stripe platform account.');
    $this->add($checks, 'gateway.recurring_webhook_secret', $recurring['webhook_secret_present'] ? 'pass' : 'fail',
      'The recurring gateway must have a signing secret.');

    $settings = $this->configFactory->get('myeventlane_pro.settings');
    $this->add($checks, 'pro.price', (float) $settings->get('pro_price') === 49.0 ? 'pass' : 'fail',
      'MEL Pro must be configured at A$49 per month.');
    $this->add($checks, 'pro.billing_portal', (bool) $settings->get('billing_portal_enabled') ? 'pass' : 'fail',
      'Stripe Billing Portal must be enabled.');
    $this->add($checks, 'pro.boost_days', (int) $settings->get('pro_boost_days') === 7 ? 'pass' : 'fail',
      'The included event Boost must be seven days.');
    $this->add($checks, 'pro.webhook_secret_storage', $this->proWebhookSecretIsRuntimeOnly() ? 'pass' : 'fail',
      'The optional Pro audit webhook secret must remain runtime-only.');

    $this->checkSchedule($checks, 'mel_pro_monthly', TRUE);
    $this->checkSchedule($checks, ProBillingSchedule::RESTART, FALSE);
    $this->checkCatalog($checks);
    $this->checkTax($checks);
    $this->checkQueues($checks);

    $cronLast = (int) $this->state->get('system.cron_last', 0);
    $cronAge = $cronLast > 0 ? $this->time->getRequestTime() - $cronLast : PHP_INT_MAX;
    $this->add($checks, 'operations.cron', $cronAge <= self::MAX_CRON_AGE ? 'pass' : 'fail',
      $cronLast > 0
        ? sprintf('Cron last ran %d minutes ago; maximum permitted age is 120 minutes.', (int) floor($cronAge / 60))
        : 'Cron has never completed.');
    $this->add($checks, 'operations.webhook_ledger', $this->database->schema()->tableExists('commerce_stripe_webhook_event') ? 'pass' : 'fail',
      'The durable Commerce Stripe webhook ledger must exist.');
    $this->add($checks, 'operations.pro_ledger', $this->database->schema()->tableExists('myeventlane_pro_webhook_event') ? 'pass' : 'fail',
      'The MEL Pro audit webhook ledger must exist before the optional endpoint is enabled.');

    $this->add($checks, 'provider.webhook_destinations', 'manual',
      'Verify live Stripe endpoint URLs, event selections and account ownership in Workbench.');
    $this->add($checks, 'provider.billing_portal', 'manual',
      'Open one authenticated live-mode Billing Portal session and verify its return route.');
    $this->add($checks, 'hosting.security_headers', 'manual',
      'Verify CSP and HSTS at the production proxy after the production domains are provisioned.');

    return [
      'environment' => $environment,
      'ready' => !array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail'),
      'checks' => $checks,
    ];
  }

  /**
   * Reads the effective configuration for a payment gateway.
   *
   * @return array<string, mixed>
   *   The non-secret readiness snapshot.
   */
  private function gatewaySnapshot(string $id): array {
    $config = $this->configFactory->get('commerce_payment.commerce_payment_gateway.' . $id);
    $configuration = (array) $config->get('configuration');
    $serverKey = trim((string) ($configuration['secret_key'] ?? ''));

    return [
      'enabled' => !$config->isNew() && (bool) $config->get('status'),
      'plugin' => (string) $config->get('plugin'),
      'mode' => (string) ($configuration['mode'] ?? ''),
      'usage' => (string) ($configuration['payment_method_usage'] ?? ''),
      'publishable_key_present' => trim((string) ($configuration['publishable_key'] ?? '')) !== '',
      'secret_key_present' => $serverKey !== '',
      'webhook_secret_present' => trim((string) ($configuration['webhook_signing_secret'] ?? '')) !== '',
      'key_type' => str_starts_with($serverKey, 'rk_') ? 'restricted' : (str_starts_with($serverKey, 'sk_') ? 'secret' : 'unknown'),
      'secret_key' => $serverKey,
    ];
  }

  /**
   * Checks one canonical Pro billing schedule.
   *
   * @param list<array{id: string, status: string, message: string}> $checks
   *   The report checks, passed by reference.
   * @param string $id
   *   The billing schedule configuration ID.
   * @param bool $trialExpected
   *   Whether the schedule must include the first-time trial.
   */
  private function checkSchedule(array &$checks, string $id, bool $trialExpected): void {
    $config = $this->configFactory->get('commerce_recurring.commerce_billing_schedule.' . $id);
    $configuration = (array) $config->get('configuration');
    $interval = (array) ($configuration['interval'] ?? []);
    $trial = (array) ($configuration['trial_interval'] ?? []);
    $monthly = (int) ($interval['number'] ?? 0) === 1 && ($interval['unit'] ?? '') === 'month';
    $trialCorrect = $trialExpected
      ? (int) ($trial['number'] ?? 0) === 30 && ($trial['unit'] ?? '') === 'day'
      : (int) ($trial['number'] ?? 0) === 0;

    $this->add($checks, 'schedule.' . $id, !$config->isNew() && (bool) $config->get('status') && $monthly && $trialCorrect ? 'pass' : 'fail',
      $trialExpected
        ? 'First-time organisers require a monthly schedule with a 30-day trial.'
        : 'Returning organisers require a monthly schedule with no trial.');
  }

  /**
   * Confirms both trial and restart catalog variations are sellable.
   *
   * @param list<array{id: string, status: string, message: string}> $checks
   *   The report checks, passed by reference.
   */
  private function checkCatalog(array &$checks): void {
    foreach ([
      ['eligible' => TRUE, 'label' => 'trial'],
      ['eligible' => FALSE, 'label' => 'restart'],
    ] as $catalog) {
      $variation = $this->productResolver->findVariationForEligibility($catalog['eligible']);
      $price = $variation instanceof ProductVariationInterface ? $variation->getPrice() : NULL;
      $valid = $variation instanceof ProductVariationInterface
        && $price !== NULL
        && $price->getCurrencyCode() === 'AUD'
        && (float) $price->getNumber() === (float) self::EXPECTED_PRICE;
      $this->add($checks, 'catalog.' . $catalog['label'], $valid ? 'pass' : 'fail',
        sprintf('The %s variation must be published and priced at A$49.', $catalog['label']));
    }
  }

  /**
   * Confirms the Australian GST configuration used by MEL Pro.
   *
   * @param list<array{id: string, status: string, message: string}> $checks
   *   The report checks, passed by reference.
   */
  private function checkTax(array &$checks): void {
    $config = $this->configFactory->get('commerce_tax.commerce_tax_type.australian_gst');
    $configuration = (array) $config->get('configuration');
    $rates = (array) ($configuration['rates'] ?? []);
    $firstRate = (array) ($rates[0] ?? []);
    $valid = !$config->isNew()
      && (bool) $config->get('status')
      && (bool) ($configuration['display_inclusive'] ?? FALSE)
      && (string) ($firstRate['percentage'] ?? '') === '0.1';
    $this->add($checks, 'tax.australian_gst', $valid ? 'pass' : 'fail',
      'Australian GST must be enabled at 10% with tax-inclusive display.');
  }

  /**
   * Confirms each lifecycle queue is enabled for cron processing.
   *
   * @param list<array{id: string, status: string, message: string}> $checks
   *   The report checks, passed by reference.
   */
  private function checkQueues(array &$checks): void {
    foreach (['commerce_recurring', 'commerce_stripe_webhook_event', 'pro_boost_sync'] as $id) {
      $config = $this->configFactory->get('advancedqueue.advancedqueue_queue.' . $id);
      $this->add($checks, 'queue.' . $id, !$config->isNew() && (bool) $config->get('status') && $config->get('processor') === 'cron' ? 'pass' : 'fail',
        sprintf('%s must be enabled and processed by cron.', $id));
    }
  }

  /**
   * Confirms gateway secrets are not persisted in configuration storage.
   */
  private function gatewaySecretsAreRuntimeOnly(string $id): bool {
    $name = 'commerce_payment.commerce_payment_gateway.' . $id;
    foreach ([$this->activeStorage->read($name), $this->syncStorage->read($name)] as $data) {
      $configuration = is_array($data) ? (array) ($data['configuration'] ?? []) : [];
      if (trim((string) ($configuration['secret_key'] ?? '')) !== ''
        || trim((string) ($configuration['webhook_signing_secret'] ?? '')) !== '') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Confirms the optional Pro audit secret is supplied at runtime only.
   */
  private function proWebhookSecretIsRuntimeOnly(): bool {
    $storedConfigs = [
      $this->activeStorage->read('myeventlane_pro.settings'),
      $this->syncStorage->read('myeventlane_pro.settings'),
    ];
    foreach ($storedConfigs as $data) {
      if (is_array($data) && trim((string) ($data['subscription_webhook_secret'] ?? '')) !== '') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Adds one result to the readiness report.
   *
   * @param list<array{id: string, status: string, message: string}> $checks
   *   The report checks, passed by reference.
   * @param string $id
   *   Stable machine-readable check identifier.
   * @param string $status
   *   One of pass, warn, fail, or manual.
   * @param string $message
   *   Human-readable operator guidance.
   */
  private function add(array &$checks, string $id, string $status, string $message): void {
    $checks[] = [
      'id' => $id,
      'status' => $status,
      'message' => $message,
    ];
  }

}
