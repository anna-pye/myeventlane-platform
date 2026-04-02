<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Service\ManageEventNavigation;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base controller for vendor event management pages.
 */
abstract class ManageEventControllerBase extends ControllerBase {

  /**
   * Constructs ManageEventControllerBase.
   */
  public function __construct(
    protected readonly ManageEventNavigation $navigation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_vendor.manage_event_navigation'),
    );
  }

  /**
   * Access check for vendor event management routes.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function access(NodeInterface $event, AccountInterface $account): AccessResultInterface {
    // Must be an event node.
    if ($event->bundle() !== 'event') {
      return AccessResult::forbidden('Not an event node.');
    }

    // Admin can access all.
    if ($account->hasPermission('administer nodes')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // Check if user is the event owner.
    $is_owner = (int) $event->getOwnerId() === (int) $account->id();

    if ($is_owner && $account->hasPermission('edit own event content')) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($event);
    }

    // Check vendor relationship if field exists.
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_users')) {
        $vendor_users = $vendor->get('field_vendor_users')->getValue();
        foreach ($vendor_users as $item) {
          if (isset($item['target_id']) && (int) $item['target_id'] === (int) $account->id()) {
            return AccessResult::allowed()
              ->cachePerUser()
              ->addCacheableDependency($event)
              ->addCacheableDependency($vendor);
          }
        }
      }
    }

    return AccessResult::forbidden('You do not have access to manage this event.');
  }

  /**
   * Builds the base render array with navigation and content.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $current_step
   *   The current step route name.
   * @param array $content
   *   The content render array for the right panel.
   * @param string $page_title
   *   Optional page title override.
   *
   * @return array
   *   Render array.
   */
  protected function buildPage(NodeInterface $event, string $current_step, array $content, string $page_title = ''): array {
    $steps = $this->navigation->getSteps($event, $current_step);
    $next_step = $this->navigation->getNextStep($event, $current_step);
    $prev_step = $this->navigation->getPreviousStep($event, $current_step);

    // Get event status.
    $status = $event->isPublished() ? 'Published' : 'Draft';
    $status_class = $event->isPublished() ? 'status-published' : 'status-draft';

    // Build preview URL.
    $preview_url = NULL;
    if (!$event->isNew()) {
      $preview_url = $event->toUrl('canonical');
    }

    // Vendor edit: Event Studio only (legacy wizard is staff-only / redirected).
    $edit_url = NULL;
    if (!$event->isNew()) {
      $edit_url = Url::fromRoute('myeventlane_event_studio.edit', ['node' => (int) $event->id()]);
    }

    return [
      '#theme' => 'myeventlane_manage_event',
      '#event' => $event,
      '#event_title' => $event->label(),
      '#event_status' => $status,
      '#event_status_class' => $status_class,
      '#steps' => $steps,
      '#current_step' => $current_step,
      '#content' => $content,
      '#page_title' => $page_title ?: $this->getPageTitle($event),
      '#preview_url' => $preview_url,
      '#edit_url' => $edit_url,
      '#next_step_url' => $next_step,
      '#prev_step_url' => $prev_step,
      '#attached' => [
        'library' => [
          'myeventlane_vendor/manage_event',
          'myeventlane_vendor/hide_admin_sidebar',
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'url.path'],
        'tags' => ['node:' . $event->id()],
      ],
    ];
  }

  /**
   * Gets the page title for the current step.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return string
   *   Page title.
   */
  abstract protected function getPageTitle(NodeInterface $event): string;

}
