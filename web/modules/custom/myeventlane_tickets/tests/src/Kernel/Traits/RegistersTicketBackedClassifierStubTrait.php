<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel\Traits;

use Drupal\Tests\myeventlane_tickets\Kernel\Support\KernelTestTicketBackedOrderItemClassifier;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers a permissive ticket-backed classifier for ticket kernel tests.
 */
trait RegistersTicketBackedClassifierStubTrait {

  /**
   * Registers the kernel-test classifier when commerce is not in the test stack.
   */
  protected function registerTicketBackedClassifierStub(ContainerBuilder $container): void {
    if ($container->hasDefinition('myeventlane_commerce.ticket_backed_order_item_classifier')) {
      return;
    }

    $container->register(
      'myeventlane_commerce.ticket_backed_order_item_classifier',
      KernelTestTicketBackedOrderItemClassifier::class,
    );
  }

}
