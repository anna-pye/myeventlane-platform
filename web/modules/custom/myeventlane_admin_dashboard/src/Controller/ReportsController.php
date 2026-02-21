<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports tab – delegates to myeventlane_reporting admin overview.
 */
final class ReportsController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected $adminReportsController,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_reporting.admin_reports'),
    );
  }

  /**
   * Returns the Reports overview page (delegates to reporting module).
   */
  public function overview(): array {
    return $this->adminReportsController->overview();
  }

}
