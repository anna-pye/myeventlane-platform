<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\myeventlane_checkout_flow\Service\TaxInvoicePresentationBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Streams a PDF tax invoice built from Commerce order data (Dompdf).
 */
final class OrderTaxInvoicePdfController extends ControllerBase {

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly TaxInvoicePresentationBuilder $taxInvoicePresentation,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('renderer'),
      $container->get('myeventlane_checkout_flow.tax_invoice_presentation'),
      $container->get('logger.channel.myeventlane_checkout_flow'),
    );
  }

  /**
   * Downloads PDF tax invoice for a placed order with non-zero total.
   */
  public function download(OrderInterface $commerce_order): Response {
    $total = $commerce_order->getTotalPrice();
    if (!$total || $total->isZero()) {
      throw new NotFoundHttpException();
    }

    $placed = $commerce_order->getPlacedTime();
    if (!$placed) {
      $this->logger->warning('Tax invoice PDF requested for order @id without placed timestamp.', [
        '@id' => (string) $commerce_order->id(),
      ]);
    }

    $invoice = $this->taxInvoicePresentation->build($commerce_order);

    $build = [
      '#theme' => 'mel_tax_invoice_pdf',
      '#invoice' => $invoice,
      '#order_entity' => $commerce_order,
      '#cache' => [
        'max-age' => 0,
        'tags' => [
          'commerce_order:' . $commerce_order->id(),
          $commerce_order->getStore() ? 'commerce_store:' . $commerce_order->getStore()->id() : 'commerce_store_list',
        ],
      ],
    ];

    try {
      $html = (string) $this->renderer->renderRoot($build);
    }
    catch (\Throwable $e) {
      $this->logger->error('Tax invoice PDF render failed for order @id: @message', [
        '@id' => (string) $commerce_order->id(),
        '@message' => $e->getMessage(),
      ]);
      throw new NotFoundHttpException();
    }

    $options = new Options();
    $options->set('isRemoteEnabled', TRUE);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $safe_number = preg_replace('/[^a-zA-Z0-9_-]/', '-', $commerce_order->getOrderNumber());
    $filename = 'tax-invoice-' . ($safe_number !== '' ? $safe_number : (string) $commerce_order->id()) . '.pdf';

    return new Response($dompdf->output(), 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control' => 'private, no-store',
    ]);
  }

}
