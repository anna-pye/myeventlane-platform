<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_event\Service\WizardReviewSummaryBuilder;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event wizard step: Review (read-only summary, Back + Publish buttons).
 */
final class EventWizardReviewForm extends EventWizardBaseForm {

  /**
   * The review summary builder.
   */
  private WizardReviewSummaryBuilder $wizardReviewSummaryBuilder;

  /**
   * The route provider.
   */
  protected RouteProviderInterface $routeProvider;

  /**
   * Constructs the form.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    RendererInterface $renderer,
    WizardReviewSummaryBuilder $wizard_review_summary_builder,
    RouteProviderInterface $route_provider,
  ) {
    parent::__construct($entity_type_manager, $domain_detector, $current_user, $renderer);
    $this->wizardReviewSummaryBuilder = $wizard_review_summary_builder;
    $this->routeProvider = $route_provider;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('renderer'),
      $container->get('myeventlane_event.wizard_review_summary_builder'),
      $container->get('router.route_provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'event_wizard_review_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $event = $this->getEvent();

    $title = $this->t('Create event: Review & Publish');
    $steps = $this->buildStepper($event, 'review');
    $payload = $this->wizardReviewSummaryBuilder->build($event);
    $tickets_edit_link = $this->buildTicketsEditLink($event);
    $publish_url = Url::fromRoute('myeventlane_event.wizard.publish', ['event' => $event->id()])->toString();

    // Event card preview (matches /event/{node} listing card).
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $card_preview = $view_builder->view($event, 'teaser');

    return [
      '#theme' => 'myeventlane_event_wizard_review',
      '#title' => $title,
      '#event' => $event,
      '#steps' => $steps,
      '#summary' => $payload['groups'],
      '#warnings' => $payload['warnings'],
      '#ready' => $payload['ready'],
      '#tickets_edit_link' => $tickets_edit_link ? $this->ticketsEditLinkRenderArray($tickets_edit_link) : NULL,
      '#publish_url' => $publish_url,
      '#card_preview' => $card_preview,
      '#attached' => [
        'library' => ['myeventlane_event/event_wizard'],
      ],
    ];
  }

  /**
   * Builds the "Edit tickets" link with route-safe fallback.
   *
   * Prefers vendor console tickets page; falls back to wizard tickets step
   * if that route does not exist.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\Core\Link|null
   *   A link to edit tickets, or NULL if no safe route exists.
   */
  protected function buildTicketsEditLink(NodeInterface $event): ?Link {
    $params = ['event' => $event->id()];

    // Preferred: vendor console tickets page.
    if ($this->routeExists('myeventlane_vendor.console.event_tickets')) {
      return Link::createFromRoute(
        $this->t('Edit tickets'),
        'myeventlane_vendor.console.event_tickets',
        $params
      );
    }

    // Fallback: wizard tickets step.
    if ($this->routeExists('myeventlane_event.wizard.tickets')) {
      return Link::createFromRoute(
        $this->t('Edit tickets'),
        'myeventlane_event.wizard.tickets',
        $params
      );
    }

    return NULL;
  }

  /**
   * Builds the render array for the tickets edit link with section styling.
   *
   * @param \Drupal\Core\Link $link
   *   The link from buildTicketsEditLink().
   *
   * @return array
   *   Render array for the link (with mel-review-section__edit class).
   */
  protected function ticketsEditLinkRenderArray(Link $link): array {
    $build = $link->toRenderable();
    $build['#attributes'] = ['class' => ['mel-review-section__edit']];
    return $build;
  }

  /**
   * Checks whether a route exists.
   *
   * @param string $route_name
   *   The route name.
   *
   * @return bool
   *   TRUE if the route exists, FALSE otherwise.
   */
  protected function routeExists(string $route_name): bool {
    try {
      $this->routeProvider->getRouteByName($route_name);
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Gets the previous step.
   *
   * @return array{label: string, route: string}|null
   *   Previous step definition or NULL if at first step.
   */
  protected function getPrevStep(string $current_step_id): ?array {
    $step_ids = array_keys(self::STEPS);
    $current_index = array_search($current_step_id, $step_ids, TRUE);
    if ($current_index === FALSE || $current_index <= 0) {
      return NULL;
    }
    return self::STEPS[$step_ids[$current_index - 1]] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Review step is read-only; no save.
  }

}
