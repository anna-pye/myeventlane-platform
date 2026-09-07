<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_commerce\Service\OperationalOrderItemDisplayBuilder;
use Drupal\myeventlane_core\MelReadinessHelper;
use Psr\Log\LoggerInterface;

/**
 * Attaches the grouped summary and checkout reassurance presentation.
 */
final class CheckoutUxAttacher {

  use StringTranslationTrait;

  public function __construct(
    private readonly MelCheckoutSummaryPresenter $checkoutSummaryPresenter,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LoggerInterface $logger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MelReadinessHelper $readinessHelper,
    private readonly OrganiserCheckoutContext $organiserCheckoutContext,
    private readonly OperationalOrderItemDisplayBuilder $operationalOrderItemDisplayBuilder,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Attaches grouped summary and payment confidence elements.
   *
   * @param array<string, mixed> $form
   *   Checkout form render array.
   */
  public function attach(array &$form): void {
    $order = $this->resolveOrder();
    if (!$order instanceof OrderInterface) {
      $this->logger->warning('MEL checkout UX: commerce_order route parameter missing or invalid while altering checkout form.');
      return;
    }

    $this->replaceSidebarWithGroupedSummary($form, $order, ['surface' => 'checkout']);
    $this->addExtrasAndCollection($form, $order);
    $this->addPaymentConfidence($form, $order);
  }

  /**
   * Replaces the completion sidebar with the canonical summary.
   *
   * @param array<string, mixed> $form
   *   Checkout form render array.
   */
  public function attachCompleteStepSidebar(array &$form): void {
    $order = $this->resolveOrder();
    if (!$order instanceof OrderInterface) {
      $this->logger->warning('MEL checkout UX: commerce_order route parameter missing or invalid on checkout complete.');
      return;
    }
    $this->replaceSidebarWithGroupedSummary($form, $order, ['surface' => 'complete']);
  }

  /**
   * Resolves the checkout order from the current route.
   */
  private function resolveOrder(): ?OrderInterface {
    $order = $this->routeMatch->getParameter('commerce_order');
    if (is_numeric($order)) {
      $loaded = $this->entityTypeManager->getStorage('commerce_order')->load((int) $order);
      return $loaded instanceof OrderInterface ? $loaded : NULL;
    }
    return $order instanceof OrderInterface ? $order : NULL;
  }

  /**
   * Replaces the core sidebar order summary.
   *
   * @param array<string, mixed> $form
   *   Checkout form render array.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Checkout order.
   * @param array<string, mixed> $presenter_options
   *   Passed to MelCheckoutSummaryPresenter::buildGroupedSummaryRenderArray().
   */
  private function replaceSidebarWithGroupedSummary(
    array &$form,
    OrderInterface $order,
    array $presenter_options = [],
  ): void {
    if (!isset($form['sidebar']['order_summary']) || !is_array($form['sidebar']['order_summary'])) {
      return;
    }

    $render = $this->checkoutSummaryPresenter->buildGroupedSummaryRenderArray($order, $presenter_options);
    $form['sidebar']['order_summary']['#attributes']['class'][] = 'mel-checkout-summary-pane';

    if (isset($form['sidebar']['order_summary']['summary']) && is_array($form['sidebar']['order_summary']['summary'])) {
      $form['sidebar']['order_summary']['summary'] = $render;
      return;
    }

    // Complete step panes may expose the View embed without a summary child.
    $form['sidebar']['order_summary']['summary'] = $render;
  }

  /**
   * Adds the visually hidden confidence announcement.
   *
   * @param array<string, mixed> $form
   *   Checkout form render array.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Checkout order.
   */
  private function addPaymentConfidence(array &$form, OrderInterface $order): void {
    $context = $this->organiserCheckoutContext->build($order);
    $lines = is_array($context['confidence'] ?? NULL)
      ? $context['confidence']
      : $this->readinessHelper->customerCheckoutSidebarConfidenceLines();
    // Rendered near the summary for checkout reassurance.
    $form['mel_checkout_confidence'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-checkout-confidence']],
      '#weight' => 3.7,
      'secure' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $lines['secure'],
      ],
      'instant' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $lines['instant'],
      ],
      'calendar' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $lines['calendar_hint'],
      ],
    ];
  }

  /**
   * Adds a read-only checkout card for merchandise and operational extras.
   *
   * @param array<string, mixed> $form
   *   Checkout form render array.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Checkout order.
   */
  private function addExtrasAndCollection(array &$form, OrderInterface $order): void {
    $items = [];
    $cache_tags = $order->getCacheTags();

    foreach ($order->getItems() as $order_item) {
      $display = $this->operationalOrderItemDisplayBuilder->buildForOrderItem($order_item);
      if ($display === NULL) {
        continue;
      }

      $quantity = (int) $order_item->getQuantity();
      if ($quantity <= 0) {
        continue;
      }

      $cache_tags = Cache::mergeTags($cache_tags, $order_item->getCacheTags());
      $purchased_entity = $order_item->getPurchasedEntity();
      if ($purchased_entity !== NULL) {
        $cache_tags = Cache::mergeTags($cache_tags, $purchased_entity->getCacheTags());
        if (method_exists($purchased_entity, 'getProduct')) {
          $product = $purchased_entity->getProduct();
          if ($product !== NULL) {
            $cache_tags = Cache::mergeTags($cache_tags, $product->getCacheTags());
          }
        }
      }

      $item = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-checkout-extra']],
      ];
      $thumbnailUrl = trim((string) ($display['thumbnail_url'] ?? ''));
      if ($thumbnailUrl !== '') {
        $item['image'] = [
          '#theme' => 'image',
          '#uri' => $thumbnailUrl,
          '#alt' => (string) ($display['thumbnail_alt'] ?? $display['title'] ?? ''),
          '#attributes' => [
            'class' => ['mel-checkout-extra__image'],
            'width' => '72',
            'height' => '72',
            'loading' => 'lazy',
          ],
        ];
      }

      $item['content'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-checkout-extra__content']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => Html::escape((string) ($display['title'] ?? $this->t('Event extra'))),
          '#attributes' => ['class' => ['mel-checkout-extra__title']],
        ],
        'meta' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-checkout-extra__meta']],
          'variation' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => Html::escape(trim((string) ($display['variation_label'] ?? ''))),
            '#access' => trim((string) ($display['variation_label'] ?? '')) !== '',
          ],
          'quantity' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => Html::escape((string) $this->formatPlural($quantity, 'Quantity: 1', 'Quantity: @count')),
          ],
        ],
      ];

      $collectionNote = trim((string) ($display['pickup_note'] ?? ''));
      if ($collectionNote === '') {
        $collectionNote = trim((string) ($display['description'] ?? ''));
      }
      if ($collectionNote !== '') {
        $item['content']['collection'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => Html::escape($collectionNote),
          '#attributes' => ['class' => ['mel-checkout-extra__collection']],
        ];
      }

      $items[] = $item;
    }

    if ($items === []) {
      return;
    }

    $form['mel_checkout_extras'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-checkout-extras'],
        'aria-labelledby' => 'mel-checkout-extras-heading',
      ],
      'intro' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-checkout-extras__header']],
        'title' => [
          '#markup' => '<h2 id="mel-checkout-extras-heading" class="mel-checkout-heading">' . Html::escape((string) $this->t('Extras and collection')) . '</h2>',
        ],
        'help' => [
          '#markup' => '<p class="mel-checkout-section__intro">' . Html::escape((string) $this->t('No attendee details are needed for these items. Check the collection information below.')) . '</p>',
        ],
      ],
      'items' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-checkout-extras__items']],
      ] + $items,
      'edit' => [
        '#type' => 'link',
        '#title' => $this->t('Edit extras in cart'),
        '#url' => Url::fromRoute('commerce_cart.page'),
        '#attributes' => ['class' => ['mel-checkout-extras__edit', 'mel-util-link']],
      ],
      '#cache' => [
        'tags' => $cache_tags,
        'contexts' => $order->getCacheContexts(),
      ],
    ];
  }

}
