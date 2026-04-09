<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform orders tab – embeds the vendor_orders View (admin_orders display).
 *
 * Uses a dedicated route so menu links and PCC tabs never reference a Views-provided
 * route that may be absent when configuration is not imported.
 */
final class OrdersController extends ControllerBase {

  public function __construct(
    private readonly MelAdminShellBuilder $adminShellBuilder,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.mel_admin_shell_builder'),
      $container->get('logger.channel.myeventlane_admin_dashboard'),
    );
  }

  /**
   * Renders the admin orders listing.
   */
  public function build(): array {
    $storage = $this->entityTypeManager()->getStorage('view');
    $view_entity = $storage->load('vendor_orders');
    if ($view_entity === NULL) {
      $this->logger->error('PCC Orders: View @id is missing; import configuration or enable the vendor_orders view.', [
        '@id' => 'vendor_orders',
      ]);
      $main = [
        '#markup' => '<p>' . $this->t('The orders listing is not available on this site.') . '</p>',
        '#cache' => [
          'max-age' => 0,
        ],
      ];
      return $this->adminShellBuilder->wrapStandard(
        $main,
        $this->t('Orders'),
        $this->t('Platform order listing and messaging actions.'),
      );
    }

    $executable = $view_entity->getExecutable();
    if (!$executable->setDisplay('admin_orders')) {
      $this->logger->error('PCC Orders: Display @display is missing on view @view.', [
        '@display' => 'admin_orders',
        '@view' => 'vendor_orders',
      ]);
      $main = [
        '#markup' => '<p>' . $this->t('The orders listing is misconfigured.') . '</p>',
        '#cache' => [
          'max-age' => 0,
        ],
      ];
      return $this->adminShellBuilder->wrapStandard(
        $main,
        $this->t('Orders'),
        $this->t('Platform order listing and messaging actions.'),
      );
    }

    $build = $executable->executeDisplay('admin_orders');
    return $this->adminShellBuilder->wrapStandard(
      $build,
      $this->t('Orders'),
      $this->t('Platform order listing and messaging actions.'),
    );
  }

}
