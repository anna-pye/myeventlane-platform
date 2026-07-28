<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
    EntityTypeManagerInterface $entity_type_manager,
    FormBuilderInterface $form_builder,
    private readonly VendorEventIndexViewModelBuilder $eventIndexViewModelBuilder,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('myeventlane_vendor.event_index_view_model_builder'),
    );
  }

  /**
   * Displays a list of vendor events with bulk delete.
   */
  public function list(Request $request): array {
    $model = $this->eventIndexViewModelBuilder->build($this->currentUser, [
      'status' => $request->query->get('status') ?? 'all',
      'sort' => $request->query->get('sort') ?? 'created',
    ]);

    $body = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-vendor-events-console-layout']],
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/mel_vendor_events',
        ],
      ],
      'index' => [
        '#theme' => 'myeventlane_vendor_events_grid',
        '#vendor_event_index_model' => $model,
        '#events' => [],
        '#cache' => [
          'contexts' => ['user'],
          'max-age' => 0,
        ],
      ],
    ];

    // Avoid duplicate empty states: index model covers the zero-event case.
    $totalEvents = (int) ($model['summary']['total'] ?? 0);
    if ($totalEvents > 0) {
      $form = $this->formBuilder->getForm(VendorEventsBulkActionsForm::class);
      $body['bulk'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-vendor-events-console-layout__bulk']],
        'form' => $form,
      ];
    }

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => NULL,
      'header_actions' => [],
      'tabs' => [],
      'body' => $body,
    ]);
  }

}
