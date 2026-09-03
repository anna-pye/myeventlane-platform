<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel\Traits;

use Drupal\Tests\myeventlane_tickets\Kernel\Support\KernelTestTicketBackedOrderItemClassifier;
use Drupal\myeventlane_commerce\Service\OperationalMerchandiseManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers lightweight Commerce services for ticket kernel tests.
 */
trait RegistersTicketBackedClassifierStubTrait {

  /**
   * Registers the kernel-test classifier when commerce is not in the test stack.
   */
  protected function registerTicketBackedClassifierStub(ContainerBuilder $container): void {
    if (!$container->hasDefinition('myeventlane_commerce.operational_merchandise_manager')) {
      $container->register(
        'myeventlane_commerce.operational_merchandise_manager',
        OperationalMerchandiseManager::class,
      )
        ->addArgument(new Reference('entity_type.manager'))
        ->addArgument(new Reference('string_translation'))
        ->addArgument(new Reference('logger.channel.myeventlane_commerce'));
    }

    if (!$container->hasDefinition('myeventlane_commerce.ticket_backed_order_item_classifier')) {
      $container->register(
        'myeventlane_commerce.ticket_backed_order_item_classifier',
        KernelTestTicketBackedOrderItemClassifier::class,
      );
    }
  }

}
