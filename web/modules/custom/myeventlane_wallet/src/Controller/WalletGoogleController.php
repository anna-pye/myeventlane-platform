<?php

namespace Drupal\myeventlane_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_order\Entity\OrderItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class WalletGoogleController extends ControllerBase {

  public function __construct(
    private readonly $googleBuilder,
  ) {}

  public static function create(ContainerInterface $c): self {
    return new self(
      $c->get('myeventlane_wallet.google_wallet_builder'),
    );
  }

  public function link(string $order_item_id): array {
    $item = OrderItem::load($order_item_id);
    if (!$item) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $url = $this->googleBuilder->generateLink($item);

    return [
      '#type' => 'markup',
      '#markup' => '<a class="mel-btn" href="'.$url.'">Add to Google Wallet</a>',
    ];
  }

}