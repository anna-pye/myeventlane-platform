<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_wallet\Service\GoogleWalletBuilder;
use Drupal\myeventlane_wallet\Service\WalletDownloadAccessChecker;
use Drupal\myeventlane_wallet\Service\WalletTicketResolver;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for Google Wallet pass links.
 */
final class WalletGoogleController extends ControllerBase {

  public function __construct(
    private readonly GoogleWalletBuilder $googleBuilder,
    private readonly WalletTicketResolver $walletTicketResolver,
    private readonly WalletDownloadAccessChecker $walletDownloadAccessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self(
      $container->get('myeventlane_wallet.google_wallet_builder'),
      $container->get('myeventlane_wallet.ticket_resolver'),
      $container->get('myeventlane_wallet.download_access'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Redirects to the Google Wallet save URL after ownership checks.
   *
   * @param string $order_item_id
   *   The order item ID (route compatibility key).
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Redirect to the HTTPS Google save URL.
   */
  public function link(string $order_item_id): Response {
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

    try {
      $url = $this->googleBuilder->generateSaveLink($item, $ticket);
    }
    catch (RuntimeException) {
      throw new HttpException(503, 'Google Wallet is temporarily unavailable.');
    }

    $parts = parse_url($url) ?: [];
    $scheme = $parts['scheme'] ?? '';
    if (strtolower($scheme) !== 'https') {
      throw new BadRequestHttpException('Invalid wallet URL.');
    }

    $response = new RedirectResponse($url, 302);
    $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
