<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_growth\Service\GrowthTrackingService;
use Drupal\myeventlane_vendor\Controller\VendorConsoleBaseController;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Duplicates an event as a draft for quick rebooking.
 *
 * "Run this again in 2 clicks" — vendors can duplicate past/upcoming events
 * to create a new draft without manual recreation.
 */
final class EventDuplicateController extends VendorConsoleBaseController implements ContainerInjectionInterface {


  /**
   * Constructs the controller.
   */
  public function __construct(
    DomainDetector $domain_detector,
    AccountProxyInterface $current_user,
    MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
    private readonly ?GrowthTrackingService $growthTracking = NULL,
  ) {
    parent::__construct($domain_detector, $current_user, $messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.domain_detector'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('logger.factory')->get('myeventlane_event'),
      $container->has('myeventlane_growth.tracking') ? $container->get('myeventlane_growth.tracking') : NULL,
    );
  }

  /**
   * Duplicates the event and redirects to the edit form.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node to duplicate (bundle: event).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the new event's edit form.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the user cannot access vendor console or does not own the event.
   */
  public function duplicate(NodeInterface $node): RedirectResponse {
    $this->assertEventOwnership($node);

    if ($node->bundle() !== 'event') {
      throw new AccessDeniedHttpException('Only events can be duplicated.');
    }
    try {
      $new_event = $node->createDuplicate();
      $new_event->setTitle($node->label() . ' (Copy)');
      $new_event->set('status', 0);
      $new_event->save();

      $this->messenger->addStatus($this->t('Event duplicated. Edit your new draft below.'));
      if ($this->growthTracking) {
        $this->growthTracking->recordConversionForPrefixes(
          (int) $this->currentUser->id(),
          (int) $node->id(),
          'event_duplicate',
          ['retention_', 'boost_'],
          ['new_nid' => (int) $new_event->id()],
        );
      }
      $this->logger->info('Event @orig duplicated as @new by user @uid.', [
        '@orig' => $node->id(),
        '@new' => $new_event->id(),
        '@uid' => $this->currentUser->id(),
      ]);

      return new RedirectResponse(
        Url::fromRoute('myeventlane_event_studio.edit', ['node' => (int) $new_event->id()])->toString(),
        302
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Event duplicate failed for @id: @message', [
        '@id' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('Could not duplicate event. Please try again.'));
      return new RedirectResponse(
        Url::fromRoute('myeventlane_event_studio.workspace', ['node' => (int) $node->id()])->toString(),
        302
      );
    }
  }

}
