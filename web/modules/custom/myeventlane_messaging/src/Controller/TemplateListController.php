<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists all messaging templates.
 */
final class TemplateListController extends ControllerBase {

  /**
   * Constructs TemplateListController.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * Lists all templates.
   *
   * @return array
   *   A render array.
   */
  public function list(): array {
    $templateNames = $this->configFactory->listAll('myeventlane_messaging.template.');
    $templates = [];

    foreach ($templateNames as $configName) {
      $key = str_replace('myeventlane_messaging.template.', '', $configName);
      $config = $this->configFactory->get($configName);
      $enabled = (bool) $config->get('enabled');
      $category = $this->getCategory($key);
      $label = $this->getLabel($key);

      $templates[$key] = [
        'key' => $key,
        'label' => $label,
        'enabled' => $enabled,
        'category' => $category,
        'edit_url' => Url::fromRoute('myeventlane_messaging.template_edit', ['template' => $key]),
        'preview_url' => Url::fromRoute('myeventlane_messaging.template_preview', ['template' => $key]),
        'test_url' => Url::fromRoute('myeventlane_messaging.template_test', ['template' => $key]),
      ];
    }

    ksort($templates);

    $build = [
      '#type' => 'table',
      '#header' => [
        $this->t('Template'),
        $this->t('Category'),
        $this->t('Status'),
        $this->t('Actions'),
      ],
      '#rows' => [],
    ];

    foreach ($templates as $template) {
      $build['#rows'][] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => $template['label'],
            '#url' => $template['edit_url'],
          ],
          'data-key' => $template['key'],
        ],
        $this->getCategoryLabel($template['category']),
        $template['enabled'] ? $this->t('Enabled') : $this->t('Disabled'),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => $template['edit_url'],
              ],
              'preview' => [
                'title' => $this->t('Preview'),
                'url' => $template['preview_url'],
              ],
              'test' => [
                'title' => $this->t('Send test'),
                'url' => $template['test_url'],
              ],
            ],
          ],
        ],
      ];
    }

    return $build;
  }

  /**
   * Gets the category for a template key.
   *
   * @param string $key
   *   The template key.
   *
   * @return string
   *   'transactional', 'operational', or 'marketing'.
   */
  private function getCategory(string $key): string {
    $transactional = [
      'order_receipt',
      'vendor_event_cancellation',
      'vendor_event_important_change',
      'vendor_event_update',
    ];
    $operational = [
      'event_reminder',
      'event_reminder_24h',
      'event_reminder_7d',
      'cart_abandoned',
      'boost_reminder',
    ];

    if (in_array($key, $transactional, TRUE)) {
      return 'transactional';
    }
    if (in_array($key, $operational, TRUE)) {
      return 'operational';
    }
    return 'marketing';
  }

  /**
   * Gets a human-readable label for a template key.
   *
   * @param string $key
   *   The template key.
   *
   * @return string
   *   The label.
   */
  private function getLabel(string $key): string {
    $labels = [
      'order_receipt' => $this->t('Order receipt'),
      'vendor_event_cancellation' => $this->t('Event cancellation'),
      'vendor_event_important_change' => $this->t('Important change'),
      'vendor_event_update' => $this->t('Event update'),
      'event_reminder' => $this->t('Event reminder'),
      'event_reminder_24h' => $this->t('Event reminder (24h)'),
      'event_reminder_7d' => $this->t('Event reminder (7d)'),
      'cart_abandoned' => $this->t('Cart abandoned'),
      'boost_reminder' => $this->t('Boost reminder'),
    ];
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
  }

  /**
   * Gets a human-readable category label.
   *
   * @param string $category
   *   The category.
   *
   * @return string
   *   The label.
   */
  private function getCategoryLabel(string $category): string {
    return match ($category) {
      'transactional' => $this->t('Transactional'),
      'operational' => $this->t('Operational'),
      'marketing' => $this->t('Marketing'),
      default => $category,
    };
  }

}
