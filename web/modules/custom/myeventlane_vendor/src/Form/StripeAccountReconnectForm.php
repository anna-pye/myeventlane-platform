<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\StripeService;
use Drupal\myeventlane_vendor\EventSubscriber\VendorStoreSubscriber;
use Drupal\myeventlane_vendor\Service\CurrentVendorResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Confirms non-destructive replacement of an incompatible Stripe account.
 */
final class StripeAccountReconnectForm extends ConfirmFormBase {

  public function __construct(
    private readonly StripeService $stripeService,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly VendorStoreSubscriber $vendorStoreSubscriber,
    private readonly RequestStack $reconnectRequestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.stripe'),
      $container->get('myeventlane_vendor.current_vendor_resolver'),
      $container->get('myeventlane_vendor.vendor_store_subscriber'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_vendor_stripe_account_reconnect';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Reconnect Stripe for direct ticket payments?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('Stripe will create a replacement connected account with the Full Stripe Dashboard. Stripe will bill its processing fees to you and will be liable for negative balances on the connected account. Your current account remains recorded for historical payments and refunds until the replacement is fully set up and verified.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Start Stripe reconnection');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('myeventlane_vendor.console.payments');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $destination = $this->resolveReturnDestination();

    // Drupal reserves the "destination" query parameter and uses it to
    // override every redirect response during KernelEvents::RESPONSE. Submit
    // this form with a non-reserved parameter so the trusted one-time Stripe
    // Account Link remains the actual Location header.
    $form['#action'] = Url::fromRoute('myeventlane_vendor.stripe_reconnect', [], [
      'query' => ['return_to' => $destination],
    ])->toString();
    $form['migration_notice'] = [
      '#type' => 'container',
      '#weight' => -10,
      'summary' => [
        '#markup' => '<p>' . $this->t('Paid ticket sales stay blocked for this organiser during reconnection. MyEventLane does not disconnect or delete the previous Stripe account.') . '</p>',
      ],
    ];
    return $form;
  }

  /**
   * Resolves a safe organiser return path without exposing a redirect target.
   */
  private function resolveReturnDestination(): string {
    $request = $this->reconnectRequestStack->getCurrentRequest();
    $destination = $request?->query->get('return_to');
    if (!is_string($destination) || $destination === '') {
      // Backwards compatibility for links rendered before this fix.
      $destination = $request?->query->get('destination');
    }

    return is_string($destination)
      && str_starts_with($destination, '/')
      && !str_starts_with($destination, '//')
      ? $destination
      : '/vendor/payments';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $vendor = $this->vendorResolver->resolveFromCurrentUser();
    if ($vendor === NULL) {
      $this->messenger()->addError($this->t('We could not find your organiser profile.'));
      $form_state->setRedirect('myeventlane_vendor.console.dashboard');
      return;
    }

    $store = $this->vendorStoreSubscriber->ensureStoreForVendor($vendor);
    if ($store === NULL) {
      $this->messenger()->addError($this->t('We could not find your organiser payment account.'));
      $form_state->setRedirect('myeventlane_vendor.console.dashboard');
      return;
    }

    $email = trim((string) $this->currentUser()->getEmail());
    if ($email === '') {
      $this->messenger()->addError($this->t('Your account must have an email address to reconnect Stripe.'));
      return;
    }

    $destination = $this->resolveReturnDestination();

    try {
      $replacementAccountId = $this->stripeService->beginConnectAccountReplacement($store, $email, 'AU');
      $query = [
        'replacement' => '1',
        'return_to' => $destination,
      ];
      $returnUrl = Url::fromRoute('myeventlane_vendor.stripe_callback', [], [
        'absolute' => TRUE,
        'query' => $query,
      ])->toString();
      $refreshUrl = Url::fromRoute('myeventlane_vendor.stripe_reconnect', [], [
        'absolute' => TRUE,
        'query' => ['return_to' => $destination],
      ])->toString();
      $accountLink = $this->stripeService->createAccountLink($replacementAccountId, $returnUrl, $refreshUrl);
      $url = is_string($accountLink->url ?? NULL) ? $accountLink->url : '';
      if ($url === '' || !str_starts_with($url, 'https://connect.stripe.com')) {
        throw new \RuntimeException('Stripe returned an invalid replacement onboarding URL.');
      }

      $response = new TrustedRedirectResponse($url);
      $response->setTrustedTargetUrl($url);
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      $form_state->setResponse($response);
    }
    catch (\Throwable $exception) {
      $this->getLogger('myeventlane_vendor')->error('Stripe reconnection could not start: @message', [
        '@message' => $exception->getMessage(),
      ]);
      $this->messenger()->addError($this->t('We could not start Stripe reconnection. No existing account was replaced. Please try again or contact support.'));
    }
  }

}
