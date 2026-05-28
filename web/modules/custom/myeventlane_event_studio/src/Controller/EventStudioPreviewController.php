<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only preview step between description and publish.
 */
final class EventStudioPreviewController extends ControllerBase {

  public function __construct(
    private readonly DomainDetector $domainDetector,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function build(NodeInterface $node): array {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if ((int) $node->getOwnerId() !== (int) $this->currentUser()->id() && !$this->currentUser()->hasPermission('administer nodes')) {
      throw new NotFoundHttpException();
    }

    $build = [
      '#theme' => 'mel_event_studio_wizard_preview',
      '#node' => $node,
      '#preview_url' => $this->domainDetector->publicUrl(
        Url::fromRoute('entity.node.canonical', ['node' => (int) $node->id()])->toString()
      ),
      '#attached' => [
        'library' => ['myeventlane_event_studio/mel_event_studio'],
      ],
      '#cache' => [
        'tags' => $node->getCacheTags(),
        'contexts' => ['user', 'user.permissions'],
      ],
    ];
    return $build;
  }

  /**
   * Title callback for preview route.
   */
  public function title(NodeInterface $node): string {
    return (string) $this->t('Preview');
  }

}
