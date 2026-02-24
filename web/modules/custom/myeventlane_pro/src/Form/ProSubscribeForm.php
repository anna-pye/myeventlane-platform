<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
      $this->logger->error('No active Pro subscription variation found.');
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

    $user = \Drupal::entityTypeManager()->getStorage('user')->load($this->currentUser->id());
    if (!$user instanceof UserInterface) {
      $form_state->setRedirectUrl($overviewUrl);
      return;
    }

    $cart = $this->cartProvider->getCart('default', $store, $user);
    if (!$cart) {
      $cart = $this->cartProvider->createCart('default', $store, $user);
    }

    $this->cartManager->addEntity($cart, $variation);

    try {
      $checkoutUrl = Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $cart->id(),
      ]);
      $form_state->setRedirectUrl($checkoutUrl);
    }
    catch (\Exception) {
      $form_state->setRedirectUrl(Url::fromRoute('commerce_cart.page'));
    }
  }

}
