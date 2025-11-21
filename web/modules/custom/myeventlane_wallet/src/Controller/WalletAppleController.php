<?php

namespace Drupal\myeventlane_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class WalletAppleController extends ControllerBase {

  public function __construct(
    private readonly $pkpassBuilder,
    private readonly FileSystemInterface $fs,
  ) {}

  public static function create(ContainerInterface $c): self {
    return new self(
      $c->get('myeventlane_wallet.pkpass_builder'),
      $c->get('file_system'),
    );
  }

  public function download(string $order_item_id): Response {
    /** @var \Drupal\commerce_order\Entity\OrderItem $item */
    $item = OrderItem::load($order_item_id);
    if (!$item) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $pass_path = $this->pkpassBuilder->generate($item);

    return new Response(
      file_get_contents($pass_path),
      200,
      [
        'Content-Type' => 'application/vnd.apple.pkpass',
        'Content-Disposition' => 'attachment; filename="ticket.pkpass"',
      ]
    );
  }

}