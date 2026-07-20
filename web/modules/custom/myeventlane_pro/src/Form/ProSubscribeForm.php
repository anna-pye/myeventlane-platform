<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Drupal\myeventlane_pro\Service\ProProductResolver;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form that adds the Pro subscription variation to cart and redirects.
 *
 * State-changing action (cart add) happens inside submitForm(), which is
 * POST-only and automatically CSRF-protected by Form API.
 *
 * Pro must check out alone on stripe_pe_recurring (off_session). The shared
 * default cart may already hold tickets/boost; those lines are cleared before
 * Pro is added so mixed carts cannot be charged entirely on PE.
 */
final class ProSubscribeForm extends FormBase {

  /**
   * Variation bundle that identifies MEL Pro subscription inventory.
   */
  private const MEL_PRO_VARIATION_TYPE = 'mel_pro_subscription_variation';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ProProductResolver $productResolver,
    private readonly ProActiveResolver $activeResolver,
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
      $container->get('myeventlane_pro.active_resolver'),
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
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if ($user instanceof UserInterface && $this->activeResolver->isUserProActive($user)) {
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
    $manageUrl = new Url('myeventlane_pro.manage');

    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface) {
      $this->logger->error('Unable to load current user @uid for Pro checkout.', ['@uid' => (string) $this->currentUser->id()]);
      $form_state->setRedirectUrl($overviewUrl);
      return;
    }

    if ($this->activeResolver->isUserProActive($user)) {
      $this->messenger()->addStatus($this->t('You already have an active Pro subscription.'));
      $form_state->setRedirectUrl($manageUrl);
      return;
    }

    $variation = $this->productResolver->findActiveVariation();
    if (!$variation) {
      $this->logger->warning(
        'No active Pro variation found (configure a published commerce variation of type @type or set pro_variation_sku). Configured SKU: @sku',
        [
          '@sku' => $this->productResolver->getConfiguredSku() ?: '(empty)',
          '@type' => self::MEL_PRO_VARIATION_TYPE,
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

    // Pro must not share a cart with tickets/boost/etc. Mixed carts would either
    // charge tickets on off_session PE or charge Pro without a dedicated PE path.
    if ($this->cartHasNonProItems($cart)) {
      $this->logger->notice(
        'Clearing non-Pro lines from cart @order_id before Pro subscribe for user @uid.',
        [
          '@order_id' => (string) $cart->id(),
          '@uid' => (string) $currentUid,
        ],
      );
      $this->cartManager->emptyCart($cart);
      $this->messenger()->addStatus($this->t('Your previous cart items were removed so Pro can be checked out on its own. Add tickets again after your subscription is active if needed.'));
    }

    $this->cartManager->addEntity($cart, $variation);
    // Persist customer ownership defensively after cart mutations.
    if ((int) $cart->getCustomerId() !== $currentUid) {
      $cart->setCustomerId($currentUid);
      $cart->save();
    }
    $this->logger->notice(
      'Pro subscribe cart prepared. current_uid=@current_uid cart_order=@order_id cart_customer=@cart_customer store=@store_id',
      [
        '@current_uid' => (string) $currentUid,
        '@order_id' => (string) $cart->id(),
        '@cart_customer' => (string) $cart->getCustomerId(),
        '@store_id' => (string) ($store->id() ?? 0),
      ],
    );
    $form_state->setRedirectUrl($this->buildCheckoutRedirectUrl($cart));
  }

  /**
   * Whether the cart contains any line that is not MEL Pro.
   */
  private function cartHasNonProItems(OrderInterface $cart): bool {
    foreach ($cart->getItems() as $item) {
      if (!$this->isMelProOrderItem($item)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether an order item is MEL Pro subscription inventory.
   */
  private function isMelProOrderItem(OrderItemInterface $item): bool {
    $purchasedEntity = $item->getPurchasedEntity();
    if (!$purchasedEntity) {
      return FALSE;
    }
    return $purchasedEntity->getEntityTypeId() === 'commerce_product_variation'
      && $purchasedEntity->bundle() === self::MEL_PRO_VARIATION_TYPE;
  }

  /**
   * Builds a checkout redirect URL for the provided order.
   */
  private function buildCheckoutRedirectUrl(OrderInterface $order): Url {
    try {
      // Use generic checkout entrypoint first. Commerce resolves the active cart
      // for the current customer and avoids order-specific access denials.
      if ($this->accessManager->checkNamedRoute('commerce_checkout.checkout', [], $this->currentUser, TRUE)->isAllowed()) {
        $this->logger->notice(
          'Redirecting Pro checkout to generic checkout route for order @order_id and user @uid.',
          [
            '@order_id' => (string) $order->id(),
            '@uid' => (string) $this->currentUser->id(),
          ],
        );
        return Url::fromRoute('commerce_checkout.checkout');
      }

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

      // Fallback for environments that require explicit order route params.
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
