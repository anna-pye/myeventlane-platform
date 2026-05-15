<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\myeventlane_tickets\Service\OperationalWorkspaceBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Staff venue operations workspace (read-only shell).
 */
final class VenueOperationsController extends ControllerBase {

  public function __construct(
    private readonly OperationalWorkspaceBuilder $operationalWorkspaceBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_tickets.operational_workspace_builder'),
    );
  }

  /**
   * Operational workspace page.
   */
  public function page(Request $request): array {
    $event = $this->resolveOptionalEvent($request);
    $raw_lifecycle = $request->query->get('incident_lifecycle');
    $lifecycle_filter = is_string($raw_lifecycle) ? trim($raw_lifecycle) : NULL;
    $workspace = $this->operationalWorkspaceBuilder->build($event, $lifecycle_filter);

    return [
      '#theme' => 'venue_operations_workspace',
      '#sections' => $workspace['sections'],
      '#meta' => $workspace['meta'],
      '#attached' => [
        'library' => ['myeventlane_tickets/venue_operations_workspace'],
      ],
      '#cache' => [
        'keys' => ['venue_operations_workspace', $event?->id() ?? 0],
        'tags' => $workspace['cache_tags'],
        'contexts' => array_merge(
          $workspace['cache_contexts'],
          ['url.query_args:event', 'url.query_args:incident_lifecycle'],
        ),
      ],
    ];
  }

  private function resolveOptionalEvent(Request $request): ?NodeInterface {
    $raw = $request->query->get('event');
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    $nid = (int) $raw;
    if ($nid < 1) {
      return NULL;
    }
    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    if ($node->bundle() !== 'event') {
      return NULL;
    }
    return $node;
  }

  /**
   * Title callback for the workspace route.
   */
  public function title(): TranslatableMarkup {
    return $this->t('Venue operations');
  }

}
