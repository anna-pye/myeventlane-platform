<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\user\UserInterface;
use Stripe\Exception\ApiErrorException;

/**
 * Creates Stripe Customer Billing Portal sessions for payment method updates.
 *
 * Commerce Recurring remains canonical; this is a convenience redirect only.
 */
final class ProBillingPortalService {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
    private readonly ?StripeService $stripeService,
  ) {}

  /**
   * Returns an absolute Stripe Billing Portal URL, or NULL when unavailable.
   */
  public function createBillingPortalSessionUrl(UserInterface $user): ?string {
    $config = $this->configFactory->get('myeventlane_pro.settings');
    if (!(bool) ($config->get('billing_portal_enabled') ?? FALSE)) {
      return NULL;
    }

    if ($this->stripeService === NULL) {
      $this->logger->warning('MEL Pro billing portal: Stripe service unavailable.');
      return NULL;
    }

    if (!$this->entityTypeManager->hasDefinition('commerce_payment_method')) {
      $this->logger->warning('MEL Pro billing portal: commerce_payment_method entity missing.');
      return NULL;
    }

    $customerId = $this->resolveStripeCustomerId($user);
    if ($customerId === NULL || $customerId === '') {
      $this->logger->notice('MEL Pro billing portal: no Stripe customer resolved for user @uid.', [
        '@uid' => (string) $user->id(),
      ]);
      return NULL;
    }

    $returnUrl = Url::fromRoute('myeventlane_pro.manage', [], ['absolute' => TRUE])->toString();

    try {
      $client = $this->stripeService->getProBillingClient();
      $session = $client->billingPortal->sessions->create([
        'customer' => $customerId,
        'return_url' => $returnUrl,
      ]);
      $url = $session->url ?? NULL;
      return is_string($url) && $url !== '' ? $url : NULL;
    }
    catch (\RuntimeException $e) {
      $this->logger->error('MEL Pro billing portal: Stripe client misconfiguration: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
    catch (ApiErrorException $e) {
      $this->logger->error('MEL Pro billing portal: Stripe API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Resolves Stripe customer ID (cus_…) from saved payment methods.
   */
  private function resolveStripeCustomerId(UserInterface $user): ?string {
    $storage = $this->entityTypeManager->getStorage('commerce_payment_method');
    $idKey = $this->entityTypeManager->getDefinition('commerce_payment_method')->getKey('id');
    if ($idKey === NULL || $idKey === '') {
      $this->logger->error('MEL Pro billing portal: commerce_payment_method entity has no id key.');
      return NULL;
    }

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $user->id())
      ->sort($idKey, 'DESC')
      ->range(0, 20)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface[] $methods */
    $methods = $storage->loadMultiple($ids);
    foreach ($methods as $method) {
      if (!$method instanceof PaymentMethodInterface) {
        continue;
      }

      $gatewayId = $method->getPaymentGatewayId();
      // Pro billing must never resolve a customer through a ticket or Connect
      // payment method. Only the dedicated recurring gateway is in scope.
      if ($gatewayId !== 'stripe_pe_recurring') {
        continue;
      }

      $remoteId = trim((string) $method->getRemoteId());
      if ($remoteId === '' || !str_starts_with($remoteId, 'pm_')) {
        continue;
      }

      if ($this->stripeService === NULL) {
        return NULL;
      }

      try {
        $client = $this->stripeService->getProBillingClient();
        $pm = $client->paymentMethods->retrieve($remoteId, []);
        $customer = $pm->customer ?? NULL;
        if (is_string($customer) && str_starts_with($customer, 'cus_')) {
          return $customer;
        }
        if (is_object($customer) && isset($customer->id) && is_string($customer->id)) {
          return $customer->id;
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('MEL Pro billing portal: could not expand payment method @pm: @message', [
          '@pm' => $remoteId,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return NULL;
  }

}
