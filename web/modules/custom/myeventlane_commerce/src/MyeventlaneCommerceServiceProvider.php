<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\myeventlane_commerce\Service\OperationalStockLocations;
use Drupal\myeventlane_commerce\Service\OperationalStockMigration;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds organiser scoping only when Commerce Stock Local is enabled.
 */
final class MyeventlaneCommerceServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    // Preserve the existing currency formatter/serializer cycle break.
    if ($container->hasDefinition('commerce_order.normalizer.adjustment_item')) {
      $container->getDefinition('commerce_order.normalizer.adjustment_item')
        ->replaceArgument(0, new Reference('myeventlane_commerce.currency_formatter.lazy'));
    }
    if (!$container->hasDefinition('commerce_stock.local_stock_service_config')) {
      return;
    }
    $container->setDefinition('myeventlane_commerce.operational_stock_locations', (new Definition(
      OperationalStockLocations::class,
      [
        new Reference('myeventlane_commerce.operational_stock_locations.inner'),
        new Reference('entity_type.manager'),
        new Reference('keyvalue'),
        new Reference('lock'),
        new Reference('database'),
        new Reference('config.factory'),
      ],
    ))->setDecoratedService('commerce_stock.local_stock_service_config')->setPublic(TRUE));
    $container->setDefinition('myeventlane_commerce.operational_stock_migration', (new Definition(
      OperationalStockMigration::class,
      array_map(static fn(string $id) => new Reference($id), [
        'commerce_stock.service_manager',
        'myeventlane_commerce.operational_stock_locations',
        'myeventlane_commerce.operational_stock_hold_manager',
        'myeventlane_commerce.operational_stock_sale_manager',
        'entity_type.manager',
        'database',
        'config.factory',
        'keyvalue',
      ]),
    ))->setPublic(TRUE));
  }

}
