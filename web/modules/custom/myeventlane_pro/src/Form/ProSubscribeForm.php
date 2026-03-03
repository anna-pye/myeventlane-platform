<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProProductResolver;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form that adds the Pro subscription variation to cart and redirects.
 *
 * State-changing action (cart add) happens inside submitForm(), which is
 * POST-only and automatically CSRF-protected by Form API.
 */
final class ProSubscribeForm extends FormBase {

  private const PRO_ROLE = 'pro_organiser';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ProProductResolver $productResolver,
    private readonly CartProviderInterface $cartProvider,
    private readonly CartManagerInterface $cartManager,
    private readonly AccessManagerInterface $accessManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('myeventlane_pro.product_resolver'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('commerce_cart.cart_manager'),
      $container->get('access_manager'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.myeventlane_pro'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_pro_subscribe';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $pro_price = NULL): array {
    if (in_array(self::PRO_ROLE, $this->currentUser->getRoles(), TRUE)) {
      return $form;
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upgrade to Pro — @price/month', ['@price' => $pro_price ?? '$49']),
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--cta', 'mel-btn--lg', 'mel-pro-upgrade-btn'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $overviewUrl = new Url('myeventlane_pro.overview');

    if (in_array(self::PRO_ROLE, $this->currentUser->getRoles(), TRUE)) {
      $this->messenger()->addStatus($this->t('You already have an active Pro subscription.'));
      $form_state->setRedirectUrl($overviewUrl);
      return;
    }

    $variation = $this->productResolver->findActiveVariation();
    if (!$variation) {
      $this->logger->error(
        'No active Pro variation found. Configured SKU: @sku; expected type fallback: @type',
        [
          '@sku' => $this->productResolver->getConfiguredSku(),
          '@type' => 'mel_pro_subscription_variation',
        ],
      );
      $this->messenger()->addError($this->t('Pro subscription is not currently available.'));
      $form_state->setRedirectUrl($overviewUrl);
      return;
    }

    $product = $variation->getProduct();
    if (!$product) {
      $this->logger->error('Pro subscription variation has no parent product.');
      $this->messenger()->addError($this->t('Unable to process subscription. Please contact support.'));
      $form_state->setRedirectUrl($overviewUrl);
      return;
    }

    $stores = $product->getStores();
    $store = reset($stores);
    if (!$store) {
      $this->logger->error('Pro subscription product has no store assigned.');
      $this->messenger()->addError($this->t('Unable to process subscription. Please contact support.'));
      $form_state->setRedirectUrl($overviewUrl);
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user instanceof UserInterface) {
      $this->logger->error('Unable to load current user @uid for Pro checkout.', ['@uid' => (string) $this->currentUser->id()]);
      $form_state->setRedirectUrl($overviewUrl);
      return;
    }

    $cart = $this->cartProvider->getCart('default', $store, $user);
    if (!$cart) {
      $cart = $this->cartProvider->createCart('default', $store, $user);
    }
    $currentUid = (int) $this->currentUser->id();
    if ((int) $cart->getCustomerId() !== $currentUid) {
      $this->logger->warning(
        'Pro checkout cart ownership mismatch. order=@order_id customer=@customer_id current=@current_uid. Resetting customer.',
        [
          '@order_id' => (string) $cart->id(),
          '@customer_id' => (string) $cart->getCustomerId(),
          '@current_uid' => (string) $currentUid,
        ],
      );
      $cart->setCustomerId($currentUid);
      $cart->save();
    }

    $this->cartManager->addEntity($cart, $variation);
    $form_state->setRedirectUrl($this->buildCheckoutRedirectUrl($cart));
  }

  /**
   * Builds a checkout redirect URL for the provided order.
   */
  private function buildCheckoutRedirectUrl(OrderInterface $order): Url {
    try {
      if ($order->hasField('checkout_flow') && !$order->get('checkout_flow')->isEmpty()) {
        $flow = $order->get('checkout_flow')->entity;
        if ($flow) {
          $plugin = $flow->getPlugin();
          $steps = $plugin->getSteps();
          $firstStep = array_key_first($steps);
          if (is_string($firstStep) && $firstStep !== '') {
            $stepParams = [
              'commerce_order' => $order->id(),
              'step' => $firstStep,
            ];
            if ($this->accessManager->checkNamedRoute('commerce_checkout.form', $stepParams, $this->currentUser, TRUE)->isAllowed()) {
              return Url::fromRoute('commerce_checkout.form', $stepParams);
            }
            $this->logger->warning(
              'Checkout access denied for order @order_id and step @step; trying route without step.',
              [
                '@order_id' => (string) $order->id(),
                '@step' => $firstStep,
              ],
            );
          }
        }
      }

      // Fallback for environments where checkout step is auto-resolved.
      $params = [
        'commerce_order' => $order->id(),
      ];
      if ($this->accessManager->checkNamedRoute('commerce_checkout.form', $params, $this->currentUser, TRUE)->isAllowed()) {
        return Url::fromRoute('commerce_checkout.form', $params);
      }
      $this->logger->warning(
        'Checkout access denied for order @order_id and user @uid.',
        [
          '@order_id' => (string) $order->id(),
          '@uid' => (string) $this->currentUser->id(),
        ],
      );
    }
    catch (\Throwable $exception) {
      $this->logger->error(
        'Failed to build checkout redirect for order @order_id: @message',
        [
          '@order_id' => (string) $order->id(),
          '@message' => $exception->getMessage(),
        ],
      );
    }

    $this->logger->warning(
      'Checkout flow could not be resolved for order @order_id; redirecting to cart.',
      ['@order_id' => (string) $order->id()],
    );
    $this->messenger()->addWarning($this->t('Checkout could not be started. Please review your cart.'));
    return Url::fromRoute('commerce_cart.page');
  }

}
