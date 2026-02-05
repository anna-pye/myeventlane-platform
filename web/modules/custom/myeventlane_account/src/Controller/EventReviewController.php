<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_account\Entity\EventReview;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for event review create/edit.
 */
final class EventReviewController extends ControllerBase {

  /**
   * Builds the review form for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The form or redirect.
   */
  public function reviewForm(NodeInterface $node): array|RedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new AccessDeniedHttpException();
    }

    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      $destination = Url::fromRoute('myeventlane_account.event_review', ['node' => $node->id()])->toString();
      return new RedirectResponse(
        Url::fromRoute('user.login', [], ['query' => ['destination' => $destination]])->toString(),
        302
      );
    }

    if (!$this->config('myeventlane_account.reviews')->get('enabled')) {
      throw new AccessDeniedHttpException();
    }

    $review = $this->loadOrCreateReview($node->id(), (int) $account->id());

    if (!$review) {
      throw new AccessDeniedHttpException();
    }

    return $this->entityFormBuilder()->getForm($review);
  }

  /**
   * Loads existing review or creates new one for the given user/event.
   */
  private function loadOrCreateReview(int $eventId, int $uid): ?EventReview {
    $storage = $this->entityTypeManager()->getStorage('event_review');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $eventId)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if (!empty($ids)) {
      $review = $storage->load(reset($ids));
      return $review instanceof EventReview ? $review : NULL;
    }

    $review = $storage->create([
      'event_id' => $eventId,
      'uid' => $uid,
      'rating' => 5,
    ]);
    return $review;
  }

}
