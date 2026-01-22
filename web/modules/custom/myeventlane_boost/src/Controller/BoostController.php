<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\myeventlane_boost\Form\BoostSelectForm;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FormBuilderInterface $formBuilder,
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
    );
  }

  /**
   * Page title callback.
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
   * Build the boost purchase page.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The render array or redirect response.
   */
  public function build(NodeInterface $node): array|RedirectResponse {
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

    $form = $this->formBuilder->getForm(BoostSelectForm::class, $node);

    $cancelLink = Link::fromTextAndUrl(
      $this->t('Cancel'),
      $node->toUrl('canonical')
    )->toRenderable();
    $cancelLink['#attributes']['class'][] = 'button';
    $cancelLink['#attributes']['class'][] = 'button--ghost';
    $cancelLink['#attributes']['class'][] = 'boost-cancel';

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-boost-page']],
      'lead' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['boost-hero']],
        'content' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['boost-hero__content']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h1',
            '#value' => $this->t('Boost "@title"', ['@title' => $node->label()]),
            '#attributes' => ['class' => ['boost-title']],
          ],
          'kicker' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $this->t('Featured placement + badge. Choose a boost duration below.'),
            '#attributes' => ['class' => ['boost-kicker']],
          ],
        ],
        'art' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => '📈',
          '#attributes' => [
            'class' => ['boost-hero__art'],
            'aria-hidden' => 'true',
          ],
        ],
      ],
      'card' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['boost-card']],
        'form' => $form,
        'footer' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['boost-footer']],
          'left' => $cancelLink,
        ],
      ],
      '#attached' => [
        'library' => ['myeventlane_boost/boost'],
      ],
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

}
