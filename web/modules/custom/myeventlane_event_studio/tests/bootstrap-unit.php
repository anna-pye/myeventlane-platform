<?php

declare(strict_types=1);

/**
 * Lightweight autoload for Event Studio unit tests run without Drupal bootstrap.
 *
 * Prefer the full suite when integration coverage is needed:
 *   cd web && ../vendor/bin/phpunit -c phpunit-event-studio.xml
 */

$module_root = dirname(__DIR__);
$custom_root = dirname($module_root);

if (!interface_exists(\Drupal\node\NodeInterface::class)) {
  eval('namespace Drupal\node; interface NodeInterface { public function id(); }');
}

require_once $custom_root . '/myeventlane_core/src/Commerce/OperationalProductBundles.php';
require_once $custom_root . '/myeventlane_commerce/src/Service/OperationalExtraVisualPresenter.php';
require_once $custom_root . '/myeventlane_commerce/src/Service/OperationalMerchandiseManager.php';
require_once $custom_root . '/myeventlane_commerce/src/Service/OperationalVariationStockResolver.php';
require_once $module_root . '/src/Service/VendorProductisationStudioManager.php';
require_once $module_root . '/src/Service/OperationalProductStudioFieldRegistry.php';
require_once $module_root . '/src/Service/VendorOperationalProductCreationManager.php';
require_once $module_root . '/src/Service/EventStudioExtrasProductEditorBuilder.php';
