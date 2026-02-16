<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Access check for RSVP cancel route.
 *
 * Policy:
 * - Owner (user_id matches): allow.
 * - Guest (no user_id): require valid token = hash(rsvp_id:email:created:secret).
 * - Admin: allow (administer rsvps or administer site configuration).
 */
final class RsvpCancelAccess {

  /**
   * Constructs RsvpCancelAccess.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('request_stack')
    );
  }

  /**
   * Checks access to cancel an RSVP.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $rsvp_id = $route_match->getParameter('rsvp_id');
    if ($rsvp_id === NULL || $rsvp_id === '') {
      return AccessResult::forbidden();
    }

    $rsvp_id = (int) $rsvp_id;
    $storage = $this->entityTypeManager->getStorage('rsvp_submission');
    $rsvp = $storage->load($rsvp_id);

    if (!$rsvp instanceof RsvpSubmission) {
      return AccessResult::forbidden()->addCacheableDependency($rsvp ?? new \stdClass());
    }

    // Admin override.
    if ($account->hasPermission('administer rsvps') || $account->hasPermission('administer site configuration')) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($rsvp);
    }

    // Event owner/vendor: can cancel any RSVP for their event (e.g. from vendor dashboard).
    $event = $rsvp->getEvent();
    if ($event && $account->hasPermission('manage own event rsvps')) {
      $is_event_owner = (int) $event->getOwnerId() === (int) $account->id();
      if ($is_event_owner) {
        return AccessResult::allowed()->cachePerUser()->addCacheableDependency($rsvp)->addCacheableDependency($event);
      }
      if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
        $vendor = $event->get('field_event_vendor')->entity;
        if ($vendor && $vendor->hasField('field_vendor_users')) {
          foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
            if (isset($item['target_id']) && (int) $item['target_id'] === (int) $account->id()) {
              return AccessResult::allowed()->cachePerUser()->addCacheableDependency($rsvp)->addCacheableDependency($event);
            }
          }
        }
      }
    }

    // Owner (logged-in user who submitted).
    $owner_id = $rsvp->get('user_id')->target_id ?? 0;
    if ($owner_id && (int) $owner_id === (int) $account->id()) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($rsvp);
    }

    // Guest (no owner): require valid token.
    if (!$owner_id || (int) $owner_id === 0) {
      $request = $this->requestStack->getCurrentRequest();
      $token = $request ? $request->query->get('token', '') : '';
      if ($token && $this->validateCancelToken($rsvp, $token)) {
        return AccessResult::allowed()->cachePerUser()->addCacheableDependency($rsvp);
      }
      return AccessResult::forbidden('Valid cancellation link required.')
        ->addCacheableDependency($rsvp);
    }

    return AccessResult::forbidden()->addCacheableDependency($rsvp);
  }

  /**
   * Validates the cancel token for a guest RSVP.
   */
  private function validateCancelToken(RsvpSubmission $rsvp, string $token): bool {
    $secret = $this->configFactory->get('myeventlane_rsvp.settings')->get('private_qr_key') ?: 'mel-rsvp-cancel';
    $email = $rsvp->getEmail() ?? '';
    $created = (int) ($rsvp->get('created')->value ?? 0);
    $id = (int) $rsvp->id();

    $expected = hash('sha256', $id . ':' . $email . ':' . $created . ':' . $secret);
    return hash_equals($expected, $token);
  }

  /**
   * Generates a cancel token for a guest RSVP (for use in email links).
   *
   * @param \Drupal\myeventlane_rsvp\Entity\RsvpSubmission $rsvp
   *   The RSVP submission.
   *
   * @return string
   *   The token.
   */
  public function generateToken(RsvpSubmission $rsvp): string {
    $secret = $this->configFactory->get('myeventlane_rsvp.settings')->get('private_qr_key') ?: 'mel-rsvp-cancel';
    $email = $rsvp->getEmail() ?? '';
    $created = (int) ($rsvp->get('created')->value ?? 0);
    $id = (int) $rsvp->id();
    return hash('sha256', $id . ':' . $email . ':' . $created . ':' . $secret);
  }

}
