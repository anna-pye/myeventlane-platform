<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\MessageRenderer;
use Drupal\myeventlane_messaging\Service\VendorBrandResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Previews messaging templates.
 *
 * Uses the same pipeline as delivery: brand resolve and preheader injection
 * so preview output matches send output (wrapper, preheader, marketing block).
 */
final class TemplatePreviewController extends ControllerBase {

  /**
   * Constructs TemplatePreviewController.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\myeventlane_messaging\Service\MessageRenderer $messageRenderer
   *   The message renderer.
   * @param \Drupal\myeventlane_messaging\Service\VendorBrandResolver $vendorBrandResolver
   *   The vendor brand resolver (same as delivery path).
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MessageRenderer $messageRenderer,
    private readonly VendorBrandResolver $vendorBrandResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('myeventlane_messaging.message_renderer'),
      $container->get('myeventlane_messaging.vendor_brand_resolver'),
    );
  }

  /**
   * Previews a template.
   *
   * @param string $template
   *   The template key.
   *
   * @return array
   *   A render array.
   */
  public function preview(string $template): array {
    $configName = "myeventlane_messaging.template.{$template}";
    $config = $this->configFactory->get($configName);

    if ($config->isNew()) {
      return [
        '#markup' => $this->t('Template @template not found.', ['@template' => $template]),
      ];
    }

    $context = $this->getSampleContext($template);
    // Same pipeline as delivery: brand then preheader so preview matches send.
    $context += $this->vendorBrandResolver->resolve($context);
    $preheaderTpl = (string) ($config->get('preheader') ?? '');
    if ($preheaderTpl !== '') {
      $preheaderRaw = $this->messageRenderer->renderString($preheaderTpl, $context);
      $context['preheader'] = Unicode::truncate(
        trim(strip_tags(Html::decodeEntities($preheaderRaw))),
        200,
        TRUE,
        TRUE
      );
    }
    else {
      $context['preheader'] = '';
    }
    $subject = (string) ($config->get('subject') ?? '');
    $renderedSubject = $this->messageRenderer->renderString($subject, $context);
    $renderedBody = $this->messageRenderer->renderHtmlBody($config, $context);

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['template-preview'],
      ],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('← Back to templates'),
        '#url' => Url::fromRoute('myeventlane_messaging.templates'),
        '#attributes' => ['class' => ['button']],
      ],
      'preview' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['email-preview-window'],
          'style' => 'max-width: 800px; margin: 20px auto; background: #f5f5f5; padding: 20px; border-radius: 8px;',
        ],
        'subject' => [
          '#type' => 'container',
          '#attributes' => [
            'style' => 'background: white; padding: 15px; margin-bottom: 10px; border-radius: 4px; border: 1px solid #ddd;',
          ],
          'label' => [
            '#markup' => '<strong>' . $this->t('Subject:') . '</strong> ',
          ],
          'value' => [
            '#markup' => htmlspecialchars($renderedSubject),
          ],
        ],
        'body' => [
          '#type' => 'container',
          '#attributes' => [
            'style' => 'background: white; padding: 20px; border-radius: 4px; border: 1px solid #ddd;',
          ],
          'content' => [
            '#markup' => $renderedBody,
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Gets sample context for preview.
   *
   * @param string $template
   *   The template key.
   *
   * @return array
   *   Sample context array.
   */
  private function getSampleContext(string $template): array {
    $base = [
      'first_name' => 'John',
      'last_name' => 'Doe',
      'email' => 'john.doe@example.com',
      'event_title' => 'Sample Event',
      'event_url' => 'https://example.com/events/sample',
      'event_name' => 'Sample Event',
      'order_number' => 'ORD-12345',
      'order_email' => 'john.doe@example.com',
      'order_url' => 'https://example.com/orders/12345',
      'total_paid' => '$99.00',
      'message_body' => '<p>This is a sample message from the event organizer.</p>',
    ];

    // Template-specific context.
    $specific = match ($template) {
      'order_receipt' => [
        'events' => [
          [
            'title' => 'Sample Event',
            'start_date' => 'January 30, 2026',
            'start_time' => '7:00 PM',
            'location' => '123 Main St, City, State',
          ],
        ],
        'ticket_items' => [
          [
            'title' => 'General Admission',
            'quantity' => 2,
            'price' => '$50.00',
            'attendees' => [
              ['name' => 'John Doe', 'email' => 'john.doe@example.com'],
              ['name' => 'Jane Doe', 'email' => 'jane.doe@example.com'],
            ],
          ],
        ],
        'donation_total' => 0,
      ],
      default => [],
    };

    return array_merge($base, $specific);
  }

}
