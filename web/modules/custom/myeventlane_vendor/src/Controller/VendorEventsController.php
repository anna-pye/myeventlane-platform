<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    );
  }

  /**
   * Displays a list of vendor events with bulk delete.
   */
  public function list(): array {
    // Build the form with bulk actions functionality.
    $form = $this->formBuilder->getForm('Drupal\myeventlane_vendor\Form\VendorEventsBulkActionsForm');

    return $this->buildVendorPage('myeventlane_vendor_console_page', [
      'title' => t('Events'),
      'header_actions' => [
        ['label' => t('Create Event'), 'url' => '/vendor/events/add'],
      ],
      'tabs' => [],
      'body' => $form,
    ]);
  }

}
