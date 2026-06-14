<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\ViewExecutableFactory;

/**
 * Lightweight checks for whether homepage discovery sections have content.
 *
 * Avoids rendering section shells (headings) when a placed block would be empty.
 */
final class HomepageSectionVisibility {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ViewExecutableFactory $viewExecutableFactory,
    private readonly FeaturedEventsRenderBuilder $featuredEventsRenderBuilder,
  ) {}

  /**
   * Whether at least one published blog post exists for the homepage guides rail.
   */
  public function hasPublicBlogPosts(): bool {
    try {
      $count = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'blog_post')
        ->condition('status', 1)
        ->range(0, 1)
        ->count()
        ->execute();
      return $count > 0;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Whether promoted featured events exist for the homepage featured region.
   */
  public function hasFeaturedEvents(): bool {
    return $this->featuredEventsRenderBuilder->hasResults();
  }

  /**
   * Whether the homepage discover embed display returns at least one row.
   */
  public function hasDiscoverEvents(): bool {
    return $this->viewDisplayHasResults('mel_home_events', 'embed_discover');
  }

  /**
   * Whether the homepage Hidden Gems display returns at least one row.
   */
  public function hasHiddenGemEvents(): bool {
    return $this->viewDisplayHasResults('upcoming_events', 'homepage_hidden_gems');
  }

  /**
   * Whether a View display returns at least one row (after access checks).
   */
  public function viewDisplayHasResults(string $viewId, string $displayId): bool {
    try {
      $storage = $this->entityTypeManager->getStorage('view')->load($viewId);
      if ($storage === NULL) {
        return FALSE;
      }

      $view = $this->viewExecutableFactory->get($storage);
      if (!$view->access($displayId)) {
        return FALSE;
      }

      $view->setDisplay($displayId);
      $view->execute();

      return $view->result !== [];
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
