<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Url;
use Drupal\myeventlane_event\Service\EventRecurrenceGenerator;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists and manages recurring event instances for a series template.
 */
final class ManageSeriesInstancesController extends ManageEventControllerBase {

  /**
   * Constructs ManageSeriesInstancesController.
   */
  public function __construct(
    ManageEventNavigation $navigation,
    private readonly EventRecurrenceGenerator $recurrenceGenerator,
  ) {
    parent::__construct($navigation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_vendor.manage_event_navigation'),
      $container->get('myeventlane_event.recurrence_generator'),
    );
  }

  /**
   * Lists instances for a series template.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The series template event.
   *
   * @return array
   *   Render array.
   */
  public function listInstances(NodeInterface $event): array {
    if (!$this->recurrenceGenerator->isSeriesTemplate($event)) {
      $content = [
        '#markup' => '<p>' . $this->t('This event is not a series template.') . '</p>',
      ];
      return $this->buildPage($event, 'myeventlane_vendor.manage_event.series', $content, $this->t('Series instances'));
    }

    $instances = $this->recurrenceGenerator->loadExistingInstances((int) $event->id());

    $rows = [];
    foreach ($instances as $instance_id => $node) {
      $start = $node->get('field_event_start')->value ?? '';
      $start_fmt = $start ? $this->dateFormatter()->format(strtotime($start), 'medium') : '-';
      $rows[] = [
        $node->label(),
        $start_fmt,
        $node->isPublished() ? $this->t('Published') : $this->t('Draft'),
        [
          '#type' => 'link',
          '#title' => $this->t('Edit'),
          '#url' => $node->toUrl('edit-form'),
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--sm']],
        ],
      ];
    }

    $generate_url = Url::fromRoute('myeventlane_event.generate_series_instances', ['event' => $event->id()]);

    $content = [
      '#theme' => 'myeventlane_manage_series_instances_content',
      '#instance_rows' => $rows,
      '#generate_url' => $generate_url,
      '#empty' => empty($rows),
    ];

    return $this->buildPage($event, 'myeventlane_vendor.manage_event.series', $content, $this->t('Series instances'));
  }

  /**
   * {@inheritdoc}
   */
  protected function getPageTitle(NodeInterface $event): string {
    return (string) $this->t('Series instances');
  }

}
