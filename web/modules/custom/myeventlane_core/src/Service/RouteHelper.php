<?php
namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Routing\RouterInterface;

final class RouteHelper {
  public function __construct(private RouterInterface $router) {}
}
