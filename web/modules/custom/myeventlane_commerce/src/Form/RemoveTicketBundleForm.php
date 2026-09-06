<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Form;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Confirms atomic removal of a purchasable ticket bundle from a cart.
 */
final class RemoveTicketBundleForm extends ConfirmFormBase {

  /**
   * The current draft cart.
   */
  private ?OrderInterface $cart = NULL;

  /**
   * The bundle instance being removed.
   */
  private string $bundleInstance = '';

  /**
   * The customer-facing bundle name.
   */
  private string $bundleName = 'ticket bundle';

  public function __construct(
    private readonly CartProviderInterface $cartProvider,
    private readonly CartManagerInterface $cartManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_cart.cart_provider'),
      $container->get('commerce_cart.cart_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_remove_ticket_bundle_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Remove @bundle from your cart?', ['@bundle' => $this->bundleName]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('All tickets included in this bundle will be removed together.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Remove bundle');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('commerce_cart.page');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderInterface $commerce_order = NULL, ?string $bundle_instance = NULL) {
    if (!$commerce_order instanceof OrderInterface
      || !$this->isCurrentCart($commerce_order)
      || $commerce_order->getState()->getId() !== 'draft') {
      throw new AccessDeniedHttpException();
    }

    $bundle_instance = trim((string) $bundle_instance);
    foreach ($commerce_order->getItems() as $order_item) {
      if ((string) $order_item->getData('mel_ticket_bundle_instance', '') !== $bundle_instance) {
        continue;
      }
      $this->cart = $commerce_order;
      $this->bundleInstance = $bundle_instance;
      $this->bundleName = trim((string) $order_item->getData('mel_ticket_bundle_name', 'ticket bundle'));
      break;
    }
    if (!$this->cart instanceof OrderInterface || $this->bundleInstance === '') {
      throw new AccessDeniedHttpException();
    }

    $form = parent::buildForm($form, $form_state);
    $form['actions']['cancel']['#attributes']['class'][] = 'mel-btn';
    $form['actions']['cancel']['#attributes']['class'][] = 'mel-btn--secondary';
    $form['actions']['cancel']['#attributes']['class'][] = 'mel-btn--pill';
    $form['actions']['submit']['#attributes']['class'][] = 'mel-btn';
    $form['actions']['submit']['#attributes']['class'][] = 'mel-btn--destructive';
    $form['actions']['submit']['#attributes']['class'][] = 'mel-btn--pill';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->cart instanceof OrderInterface || !$this->isCurrentCart($this->cart)) {
      throw new AccessDeniedHttpException();
    }

    $removed = 0;
    foreach ($this->cart->getItems() as $order_item) {
      if ((string) $order_item->getData('mel_ticket_bundle_instance', '') !== $this->bundleInstance) {
        continue;
      }
      $this->cartManager->removeOrderItem($this->cart, $order_item, TRUE);
      $removed++;
    }
    if ($removed > 0) {
      $this->messenger()->addStatus($this->t('Removed @bundle from your cart.', ['@bundle' => $this->bundleName]));
    }
    $form_state->setRedirect('commerce_cart.page');
  }

  /**
   * Confirms that Commerce exposes this order as a cart for this session.
   */
  private function isCurrentCart(OrderInterface $order): bool {
    foreach ($this->cartProvider->getCarts() as $cart) {
      if ((int) $cart->id() === (int) $order->id()) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
