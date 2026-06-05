<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\Form\BoostSelectForm;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for boost purchase pages.
 */
final class BoostController extends ControllerBase {

  /**
   * Constructs a BoostController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Drupal\myeventlane_vendor\Service\VendorEventTabsService $eventTabsService
   *   Event workspace tab definitions (vendor shell).
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FormBuilderInterface $formBuilder,
    private readonly VendorEventTabsService $eventTabsService,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('myeventlane_vendor.service.event_tabs'),
    );
  }

  /**
   * Page title callback (public route uses {node}).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The page title.
   */
  public function title(NodeInterface $node): TranslatableMarkup|string {
    return $this->t('Boost "@title"', ['@title' => $node->label()]);
  }

  /**
   * Title for vendor workspace boost route ({event} parameter).
   */
  public function titleVendor(NodeInterface $event): TranslatableMarkup|string {
    return $this->title($event);
  }

  /**
   * Access callback for boost page.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(NodeInterface $node): AccessResultInterface {
    // Must be an event node.
    if ($node->bundle() !== 'event') {
      return AccessResult::forbidden()
        ->addCacheableDependency($node);
    }

    $account = $this->currentUser();
    $isOwner = ((int) $node->getOwnerId() === (int) $account->id());
    $canPurchase = $account->hasPermission('purchase boost for events');
    $canAdmin = $account->hasPermission('administer nodes');

    // Admins can always access, even for unpublished events.
    if ($canAdmin) {
      return AccessResult::allowed()
        ->addCacheableDependency($node)
        ->cachePerPermissions()
        ->cachePerUser();
    }

    // For non-admins, event must be published.
    if (!$node->isPublished()) {
      return AccessResult::forbidden()
        ->addCacheableDependency($node)
        ->cachePerUser();
    }

    // Check if user is owner or has purchase permission.
    return AccessResult::allowedIf($isOwner || $canPurchase)
      ->addCacheableDependency($node)
      ->cachePerPermissions()
      ->cachePerUser();
  }

  /**
   * Build the boost purchase page (public /event/{node}/boost).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The render array or redirect response.
   */
  public function build(NodeInterface $node): array|RedirectResponse {
    return $this->buildBoostEventPageContent($node, FALSE);
  }

  /**
   * Boost purchase inside vendor event workspace shell.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The render array or redirect response.
   */
  public function buildVendorWorkspace(NodeInterface $event): array|RedirectResponse {
    $inner = $this->buildBoostEventPageContent($event, TRUE);
    if ($inner instanceof RedirectResponse) {
      return $inner;
    }
    return $this->wrapVendorWorkspace($event, $inner);
  }

  /**
   * Shared boost UI used by public and vendor workspace routes.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param bool $vendor_workspace
   *   When TRUE, Cancel targets the event overview in the vendor console.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The render array or redirect response.
   */
  private function buildBoostEventPageContent(NodeInterface $node, bool $vendor_workspace): array|RedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    // Show warning message for vendors trying to boost unpublished events.
    if (!$node->isPublished() && !$this->currentUser()->hasPermission('administer myeventlane')) {
      $this->messenger()->addWarning(
        $this->t('This event must be published before it can be boosted.')
      );

      return $this->redirect('entity.node.edit_form', [
        'node' => $node->id(),
      ]);
    }

    $boostPerformance = NULL;
    if (\Drupal::hasService('myeventlane_boost.performance')) {
      try {
        $boostPerformance = \Drupal::service('myeventlane_boost.performance')
          ->getPerformanceForEvent($node);
      }
      catch (\Throwable $e) {
        \Drupal::logger('myeventlane_boost')->warning('Boost performance failed: @msg', [
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    $showStripeCta = !$this->currentUser()->hasPermission('administer myeventlane')
      && !$this->checkStripeConnection($this->currentUser());

    if ($vendor_workspace) {
      try {
        $cancelUrl = Url::fromRoute('myeventlane_vendor.console.event_overview', ['event' => $node->id()]);
        $cancelLink = Link::fromTextAndUrl($this->t('Cancel'), $cancelUrl)->toRenderable();
      }
      catch (\Throwable) {
        $cancelLink = Link::fromTextAndUrl(
          $this->t('Cancel'),
          $node->toUrl('canonical')
        )->toRenderable();
      }
    }
    else {
      $cancelLink = Link::fromTextAndUrl(
        $this->t('Cancel'),
        $node->toUrl('canonical')
      )->toRenderable();
    }
    $cancelLink['#attributes']['class'][] = 'button';
    $cancelLink['#attributes']['class'][] = 'button--ghost';
    $cancelLink['#attributes']['class'][] = 'boost-cancel';

    $body = $showStripeCta
      ? $this->buildStripeConnectCta($node, $vendor_workspace)
      : $this->formBuilder->getForm(BoostSelectForm::class, $node, $boostPerformance);

    // Theme variables must use # prefix; bare keys are treated as child render elements.
    return [
      '#theme' => 'boost_event_page',
      '#boost_performance' => $boostPerformance,
      '#event' => $node,
      '#form' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-boost-page__card']],
        'body' => $body,
        'footer' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['boost-footer']],
          'left' => $cancelLink,
        ],
      ],
      '#attached' => [
        'library' => ['myeventlane_boost/boost'],
      ],
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'tags' => $node->getCacheTags(),
      ],
    ];
  }

  /**
   * Wraps boost_event_page in the vendor theme workspace shell.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event.
   * @param array $boost_page_render
   *   Render array for boost_event_page.
   *
   * @return array
   *   Render array for mel_event_workspace.
   */
  private function wrapVendorWorkspace(NodeInterface $event, array $boost_page_render): array {
    $base_attached = [
      'library' => [
        'myeventlane_vendor_theme/global-styling',
      ],
    ];
    if (isset($boost_page_render['#attached']) && is_array($boost_page_render['#attached'])) {
      $extra = $boost_page_render['#attached'];
      if (!empty($extra['library']) && is_array($extra['library'])) {
        $base_attached['library'] = array_values(array_unique(array_merge(
          $base_attached['library'],
          $extra['library'],
        )));
      }
      if (!empty($extra['drupalSettings']) && is_array($extra['drupalSettings'])) {
        $base_attached['drupalSettings'] = array_replace_recursive(
          $base_attached['drupalSettings'] ?? [],
          $extra['drupalSettings'],
        );
      }
    }

    return [
      '#theme' => 'mel_event_workspace',
      '#attached' => $base_attached,
      '#event' => $event,
      '#tabs' => $this->eventTabsService->getTabs($event, 'boost'),
      '#meta' => NULL,
      '#sidebar' => NULL,
      '#content' => $boost_page_render,
      '#actions' => [],
    ];
  }

  /**
   * Bridge route: displays the boost selection page.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array
   *   The render array.
   */
  public function bridgeAddToCart(NodeInterface $node): array {
    return $this->build($node);
  }

  /**
   * Builds the Stripe connection CTA for boost page.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event being boosted.
   * @param bool $vendor_workspace
   *   Whether the page is rendered inside the vendor event workspace.
   *
   * @return array
   *   A render array with CTA content.
   */
  private function buildStripeConnectCta(NodeInterface $event, bool $vendor_workspace): array {
    $returnDestination = '/vendor/boost';
    try {
      $returnDestination = $vendor_workspace
        ? Url::fromRoute('myeventlane_boost.vendor_event_boost', ['event' => $event->id()])->toString()
        : Url::fromRoute('myeventlane_boost.boost_page', ['node' => $event->id()])->toString();
    }
    catch (\Throwable) {
      // Fallback path retained when route is not available.
    }

    $connectUrl = '/vendor/payouts';

    try {
      $connectUrl = Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
        'query' => ['destination' => $returnDestination],
      ])->toString();
    }
    catch (\Exception) {
      // Fallback path retained when route is not available.
    }

    $connectLink = Link::fromTextAndUrl(
      $this->t('Connect Stripe to continue'),
      Url::fromUri('internal:' . $connectUrl)
    )->toRenderable();
    $connectLink['#attributes']['class'][] = 'button';
    $connectLink['#attributes']['class'][] = 'button--primary';
    $connectLink['#attributes']['class'][] = 'boost-connect-stripe';
    $connectLink['#attributes']['class'][] = 'mel-button';
    $connectLink['#attributes']['class'][] = 'mel-button--primary';
    $connectLink['#attributes']['class'][] = 'mel-button--stripe';

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['boost-stripe-cta']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Connect Stripe to purchase Boost'),
        '#attributes' => ['class' => ['boost-stripe-cta__title']],
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Boost helps more people discover your event across MyEventLane.'),
        '#attributes' => ['class' => ['boost-stripe-cta__intro']],
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Connect Stripe to finish your payout setup. When you are ready, return here to choose a Boost package.'),
        '#attributes' => ['class' => ['boost-stripe-cta__text']],
      ],
      'action' => $connectLink,
    ];
  }

  /**
   * Checks if user has Stripe Connect account configured.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return bool
   *   TRUE if Stripe is connected, FALSE otherwise.
   */
  private function checkStripeConnection(AccountInterface $account): bool {
    $userId = (int) $account->id();
    if ($userId === 0) {
      return FALSE;
    }

    try {
      $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
      $storeIds = $storeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $userId)
        ->range(0, 1)
        ->execute();

      if (!empty($storeIds)) {
        $store = $storeStorage->load(reset($storeIds));
        if ($store && $store->hasField('field_stripe_account_id') && !$store->get('field_stripe_account_id')->isEmpty()) {
          if ($store->hasField('field_stripe_connected')) {
            return (bool) $store->get('field_stripe_connected')->value;
          }
          return !empty($store->get('field_stripe_account_id')->value);
        }
      }

      $vendorStorage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $vendorIds = $vendorStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $userId)
        ->range(0, 1)
        ->execute();

      if (!empty($vendorIds)) {
        $vendor = $vendorStorage->load(reset($vendorIds));
        if ($vendor && $vendor->hasField('field_stripe_account_id') && !$vendor->get('field_stripe_account_id')->isEmpty()) {
          return TRUE;
        }
      }
    }
    catch (\Exception $e) {
      $this->getLogger('myeventlane_boost')->warning('Error checking Stripe connection for user @uid: @message', [
        '@uid' => $userId,
        '@message' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

}
