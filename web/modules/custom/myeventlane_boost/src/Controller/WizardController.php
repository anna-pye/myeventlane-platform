<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_boost\Form\Wizard\BoostPaymentRedirectForm;
use Drupal\myeventlane_boost\Form\Wizard\ChooseBudgetForm;
use Drupal\myeventlane_boost\Form\Wizard\ChooseDatesForm;
use Drupal\myeventlane_boost\Form\Wizard\ChoosePlacementForm;
use Drupal\myeventlane_boost\Form\Wizard\PreviewConfirmForm;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the Boost configuration wizard for vendors (steps 1–5).
 */
final class WizardController extends VendorConsoleBaseController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domainDetector
   *   The domain detector.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    DomainDetector $domainDetector,
    AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    private readonly FormBuilderInterface $formBuilder,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($domainDetector, $currentUser, $messenger);
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
      $container->get('logger.channel.myeventlane_boost'),
    );
  }

  /**
   * Vendor Boost wizard entry: redirects to Step 1.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node loaded from the route.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to wizard step 1.
   */
  public function wizard(NodeInterface $event): RedirectResponse {
    if ($event->bundle() !== 'event') {
      $this->logger->error('Boost wizard requested for non-event node @nid (@bundle).', [
        '@nid' => (int) $event->id(),
        '@bundle' => $event->bundle(),
      ]);
      throw new NotFoundHttpException();
    }

    $this->assertEventOwnership($event);

    $event_id = (int) $event->id();
    return new RedirectResponse(
      Url::fromRoute('myeventlane_boost.wizard.step1', ['event' => $event_id])->setAbsolute()->toString(),
      302
    );
  }

  /**
   * Step 1: Choose placement (Form API).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node loaded from the route.
   *
   * @return array
   *   A render array.
   */
  public function step1(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    // Enforce vendor access + ownership (server-side).
    $this->assertEventOwnership($event);

    $form = $this->formBuilder->getForm(ChoosePlacementForm::class, $event);

    return [
      '#theme' => 'myeventlane_boost_wizard',
      '#event_id' => (int) $event->id(),
      '#event_title' => $event->label(),
      '#content' => $form,
      '#attributes' => [
        'class' => ['myeventlane-boost-wizard', 'myeventlane-boost-wizard--step-1'],
        'data-event-id' => (int) $event->id(),
        'data-step' => '1',
      ],
    ];
  }

  /**
   * Step 2: Choose budget (Form API).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node loaded from the route.
   *
   * @return array
   *   A render array.
   */
  public function step2(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    // Enforce vendor access + ownership (server-side).
    $this->assertEventOwnership($event);

    $form = $this->formBuilder->getForm(ChooseBudgetForm::class, $event);

    return [
      '#theme' => 'myeventlane_boost_wizard',
      '#event_id' => (int) $event->id(),
      '#event_title' => $event->label(),
      '#content' => $form,
      '#attributes' => [
        'class' => ['myeventlane-boost-wizard', 'myeventlane-boost-wizard--step-2'],
        'data-event-id' => (int) $event->id(),
        'data-step' => '2',
      ],
    ];
  }

  /**
   * Step 3: Choose dates (Form API).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node loaded from the route.
   *
   * @return array
   *   A render array.
   */
  public function step3(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    // Enforce vendor access + ownership (server-side).
    $this->assertEventOwnership($event);

    $form = $this->formBuilder->getForm(ChooseDatesForm::class, $event);

    return [
      '#theme' => 'myeventlane_boost_wizard',
      '#event_id' => (int) $event->id(),
      '#event_title' => $event->label(),
      '#content' => $form,
      '#attributes' => [
        'class' => ['myeventlane-boost-wizard', 'myeventlane-boost-wizard--step-3'],
        'data-event-id' => (int) $event->id(),
        'data-step' => '3',
      ],
    ];
  }

  /**
   * Step 4: Preview & confirm (Form API).
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node loaded from the route.
   *
   * @return array
   *   A render array.
   */
  public function step4(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    // Enforce vendor access + ownership (server-side).
    $this->assertEventOwnership($event);

    $form = $this->formBuilder->getForm(PreviewConfirmForm::class, $event);

    return [
      '#theme' => 'myeventlane_boost_wizard',
      '#event_id' => (int) $event->id(),
      '#event_title' => $event->label(),
      '#content' => $form,
      '#attributes' => [
        'class' => ['myeventlane-boost-wizard', 'myeventlane-boost-wizard--step-4'],
        'data-event-id' => (int) $event->id(),
        'data-step' => '4',
      ],
    ];
  }

  /**
   * Step 5: Pay for Boost — render payment redirect form in wizard shell.
   *
   * Renders BoostPaymentRedirectForm ("Go to payment") inside the shared
   * wizard shell; submit redirects to myeventlane_boost.boost_page.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node loaded from the route.
   *
   * @return array
   *   A render array.
   */
  public function step5(NodeInterface $event): array {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    // Enforce vendor access + ownership (server-side).
    $this->assertEventOwnership($event);

    $form = $this->formBuilder->getForm(BoostPaymentRedirectForm::class, $event);

    return [
      '#theme' => 'myeventlane_boost_wizard',
      '#event_id' => (int) $event->id(),
      '#event_title' => $event->label(),
      '#content' => $form,
      '#attributes' => [
        'class' => ['myeventlane-boost-wizard', 'myeventlane-boost-wizard--step-5'],
        'data-event-id' => (int) $event->id(),
        'data-step' => '5',
      ],
    ];
  }

}

