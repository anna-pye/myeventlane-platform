<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_escalations\Entity\EscalationInterface;

/**
 * Renders the admin escalation view with the case console layout.
 *
 * Replaces the default entity view controller for entity.escalation.canonical.
 * By controlling the controller, we ensure the entity view build is fully
 * processed (including entity_view_alter) before being returned, so the
 * case console layout is always applied.
 */
final class AdminEscalationViewController extends ControllerBase {

  /**
   * Renders the admin escalation with case console layout.
   *
   * @param \Drupal\myeventlane_escalations\Entity\EscalationInterface $escalation
   *   The escalation entity from the route.
   *
   * @return array
   *   A render array.
   */
  public function view(EscalationInterface $escalation): array {
    $viewBuilder = $this->entityTypeManager()->getViewBuilder('escalation');
    $build = $viewBuilder->view($escalation, 'full');

    // Run the #pre_render callback now so entity_view_alter executes.
    // This populates fields and allows our portal alter to replace with the console.
    $build = $viewBuilder->build($build);

    // Add controller additions (title, canonical link).
    $build['#title'] = $escalation->label();
    $build['#entity_type'] = 'escalation';
    $build['#' . $build['#entity_type']] = $escalation;

    if ($escalation->hasLinkTemplate('canonical') && !$escalation->isNew()) {
      $url = $escalation->toUrl('canonical')->setAbsolute(TRUE);
      $build['#attached']['html_head_link'][] = [
        ['rel' => 'canonical', 'href' => $url->toString()],
      ];
      $build['#attached']['html_head_link'][] = [
        ['rel' => 'shortlink', 'href' => $url->setOption('alias', TRUE)->toString()],
      ];
      $build['#cache']['contexts'][] = 'url.site';
    }

    return $build;
  }

}
