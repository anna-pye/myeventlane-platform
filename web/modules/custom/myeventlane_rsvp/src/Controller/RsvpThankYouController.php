<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_rsvp\Service\RsvpMailer;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * RSVP Thank You controller.
 */
final class RsvpThankYouController {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly RsvpMailer $mailer,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('logger.factory'),
      $container->get('myeventlane_rsvp.mailer'),
    );
  }

  public function page(RouteMatchInterface $route_match): array {
    $event_param = $route_match->getParameter('event');
    if (!$event_param) {
      throw new NotFoundHttpException();
    }

    $event = $event_param instanceof NodeInterface
      ? $event_param
      : $this->entityTypeManager->getStorage('node')->load((int) $event_param);

    if (!$event instanceof NodeInterface) {
      throw new NotFoundHttpException();
    }

    $logger = $this->loggerFactory->get('myeventlane_rsvp');

    $request = $this->requestStack->getCurrentRequest();
    $order_id = (int) ($request?->query->get('order') ?? 0);

    $message = 'Your RSVP is confirmed.';
    $checkout_url = NULL;

    if ($order_id > 0) {
      $order = $this->entityTypeManager
        ->getStorage('commerce_order')
        ->load($order_id);

      if ($order instanceof OrderInterface) {
        $state = (string) ($order->getState()->getId() ?? $order->getState()->value ?? 'draft');

        if ($state !== 'draft') {
          foreach ($order->getItems() as $item) {
            if ((string) $item->bundle() !== 'rsvp_donation') {
              continue;
            }

            if (!$item->hasField('field_attendee_data') || $item->get('field_attendee_data')->isEmpty()) {
              continue;
            }

            $raw = (string) $item->get('field_attendee_data')->value;
            $data = json_decode($raw, TRUE);
            $sid = isset($data['rsvp_submission_id']) ? (int) $data['rsvp_submission_id'] : 0;

            if ($sid > 0) {
              $submission = $this->entityTypeManager
                ->getStorage('rsvp_submission')
                ->load($sid);

              if ($submission && $submission->hasField('status')) {
                $current = (string) $submission->get('status')->value;
                if ($current !== 'confirmed') {
                  $submission->set('status', 'confirmed');
                  $submission->save();

                  try {
                    $this->mailer->sendConfirmation($submission, $event);
                  }
                  catch (\Throwable $e) {
                    $logger->warning('RSVP email failed after checkout: @msg', [
                      '@msg' => $e->getMessage(),
                    ]);
                  }
                }

                $message = 'Payment received. Your RSVP is confirmed.';
              }
            }
          }
        }
        else {
          $message = 'Checkout not completed. Your RSVP is saved but not confirmed.';
          if (\Drupal::moduleHandler()->moduleExists('commerce_checkout')) {
            $checkout_url = Url::fromRoute('commerce_checkout.form', [
              'commerce_order' => $order_id,
              'step' => 'checkout',
            ]);
          }
        }
      }
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-card', 'mel-rsvp-thankyou']],
      'title' => [
        '#markup' => '<h2>Thanks — you’re booked in 🎉</h2>',
      ],
      'event' => [
        '#markup' => '<p>Event: ' . $event->label() . '</p>',
      ],
      'message' => [
        '#markup' => '<p>' . $message . '</p>',
      ],
      '#cache' => [
        'contexts' => ['url', 'url.query_args:order'],
      ],
    ];

    if (isset($checkout_url) && $checkout_url instanceof Url) {
      $build['complete_link'] = [
        '#type' => 'link',
        '#title' => 'Complete your donation',
        '#url' => $checkout_url,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    return $build;
  }

}