<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Plugin\views\access;

use Drupal\user\Plugin\views\access\Permission;
use Drupal\views\Attribute\ViewsAccess;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Compatibility alias for legacy "permission" Views access plugin ID.
 *
 * Some config may reference "permission" instead of "perm".
 * This plugin provides the same behaviour under the legacy ID.
 *
 * @see \Drupal\user\Plugin\views\access\Permission
 */
#[ViewsAccess(
  id: 'permission',
  title: new TranslatableMarkup('Permission (legacy)'),
  help: new TranslatableMarkup('Access will be granted to users with the specified permission string.'),
)]
final class PermissionCompat extends Permission {

}
