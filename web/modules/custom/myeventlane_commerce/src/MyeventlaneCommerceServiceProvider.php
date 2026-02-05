<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Breaks the circular dependency between currency_formatter and serializer.
 *
 * Commerce's AdjustmentItemNormalizer injects commerce_price.currency_formatter,
 * creating: currency_formatter → serialization.exception.default → serializer
 * → AdjustmentItemNormalizer → currency_formatter.
 *
 * This provider swaps the normalizer's currency formatter argument for a lazy
 * proxy that defers resolution until first use.
 */
final class MyeventlaneCommerceServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('commerce_order.normalizer.adjustment_item')) {
      return;
    }

    $definition = $container->getDefinition('commerce_order.normalizer.adjustment_item');
    $args = $definition->getArguments();

    // Replace the first (and only) argument with our lazy formatter.
    $args[0] = new Reference('myeventlane_commerce.currency_formatter.lazy');
    $definition->setArguments($args);
  }

}
