<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\myeventlane_wallet\Service\PkPassBuilder;
use Drupal\myeventlane_wallet\Service\WalletDownloadAccessChecker;
use Drupal\myeventlane_wallet\Service\WalletTicketResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for Apple Wallet pass downloads.
 */
final class WalletAppleController extends ControllerBase {

  public function __construct(
    private readonly PkPassBuilder $pkpassBuilder,
    private readonly FileSystemInterface $fileSystem,
    private readonly WalletTicketResolver $walletTicketResolver,
    private readonly WalletDownloadAccessChecker $walletDownloadAccessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self(
      $container->get('myeventlane_wallet.pkpass_builder'),
      $container->get('file_system'),
      $container->get('myeventlane_wallet.ticket_resolver'),
      $container->get('myeventlane_wallet.download_access'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Downloads an Apple Wallet pass for an order item.
   *
   * @param string $order_item_id
   *   The order item ID (route compatibility key).
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The pass file response.
   */
  public function download(string $order_item_id): Response {
    if (!ctype_digit($order_item_id)) {
      throw new BadRequestHttpException('Invalid order item ID.');
    }

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface|null $item */
    $item = $this->entityTypeManager
      ->getStorage('commerce_order_item')
      ->load((int) $order_item_id);
    if (!$item) {
      throw new NotFoundHttpException();
    }

    $ticket = $this->walletTicketResolver->resolvePrimaryTicketForOrderItem($item, $this->currentUser());
    $this->walletDownloadAccessChecker->assertAuthorized($item, $ticket, $this->currentUser());

    $passPath = $this->pkpassBuilder->generate($item, $ticket);
    $realPath = $this->fileSystem->realpath($passPath) ?: $passPath;

    if (!is_file($realPath) || !is_readable($realPath)) {
      throw new NotFoundHttpException();
    }

    $response = new BinaryFileResponse($realPath);
    $response->headers->set('Content-Type', 'application/vnd.apple.pkpass');
    $response->setContentDisposition('attachment', 'ticket.pkpass');
    $response->deleteFileAfterSend(TRUE);

    return $response;
  }

}
