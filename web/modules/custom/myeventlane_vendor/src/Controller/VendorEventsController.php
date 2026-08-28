<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_vendor\Form\VendorEventsBulkActionsForm;
use Drupal\myeventlane_vendor\Service\VendorEventIndexViewModelBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Vendor events listing controller.
 */
final class VendorEventsController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * The form builder.
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    FormBuilderInterface $form_builder,
    private readonly VendorEventIndexViewModelBuilder $eventIndexViewModelBuilder,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('form_builder'),
      $container->get('myeventlane_vendor.event_index_view_model_builder'),
    );
  }

  /**
   * Displays a list of vendor events with bulk delete.
   */
  public function list(Request $request): array {
    $model = $this->eventIndexViewModelBuilder->build($this->currentUser, [
      'status' => $request->query->get('status') ?? 'current',
      'sort' => $request->query->get('sort') ?? 'recommended',
      'search' => $request->query->get('search') ?? '',
    ]);

    $form = $this->formBuilder->getForm(
      VendorEventsBulkActionsForm::class,
      $model,
    );
    $body = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-vendor-events-console-layout']],
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/mel_vendor_events',
        ],
      ],
      'index' => [
        'form' => $form,
      ],
      'pager' => [
        '#type' => 'pager',
      ],
      '#cache' => [
        'contexts' => ['user', 'url.query_args'],
        'max-age' => 0,
      ],
    ];

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => NULL,
      'header_actions' => [],
      'tabs' => [],
      'body' => $body,
    ]);
  }

}
