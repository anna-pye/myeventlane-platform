<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Sets documentation status to archived (node may remain published for admins).
 */
#[Action(
  id: 'mel_help_documentation_archive',
  label: new TranslatableMarkup('MEL: Archive documentation'),
  type: 'node',
)]
final class HelpArticleDocumentationArchiveAction extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'help_article') {
      return;
    }
    if ($entity->hasField('field_help_status')) {
      $entity->set('field_help_status', 'archived');
    }
    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    if (!$object instanceof NodeInterface || $object->bundle() !== 'help_article') {
      return $return_as_object ? \Drupal\Core\Access\AccessResult::forbidden() : FALSE;
    }
    $result = $object->access('update', $account, TRUE);
    if ($object->hasField('field_help_status')) {
      $result = $result->andIf($object->get('field_help_status')->access('edit', $account, TRUE));
    }
    return $return_as_object ? $result : $result->isAllowed();
  }

}
