<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles vendor retry actions for failed refunds.
 */
final class RetryController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly RefundProcessor $refundProcessor,
    private readonly RequestStack $requestStack,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.processor'),
      $container->get('request_stack'),
      $container->get('url_generator')
    );
  }

  /**
   * Retries a failed refund log.
   */
  public function retry(int $log): RedirectResponse {
    $redirect = $this->buildRedirectResponse($log);

    try {
      $newLogId = $this->refundProcessor->retryRefund($log);
      $this->messenger()->addStatus($this->t('Retry started — MEL is reprocessing this now.'));
      $this->getLogger('myeventlane_refunds')->notice('Refund retry started by user @uid: failed_log_id=@failed_log_id new_log_id=@new_log_id', [
        '@uid' => (int) $this->currentUser()->id(),
        '@failed_log_id' => $log,
        '@new_log_id' => $newLogId,
      ]);
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Retry failed — no changes were made.'));
      $this->getLogger('myeventlane_refunds')->error('Refund retry failed for log @log_id by user @uid: @message', [
        '@log_id' => $log,
        '@uid' => (int) $this->currentUser()->id(),
        '@message' => $exception->getMessage(),
      ]);
    }

    return $redirect;
  }

  /**
   * Builds the safest available redirect target.
   */
  private function buildRedirectResponse(int $logId): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $referer = $request?->headers->get('referer');

    if (is_string($referer) && $referer !== '') {
      $host = $request?->getSchemeAndHttpHost() ?? '';
      if (str_starts_with($referer, '/') || ($host !== '' && str_starts_with($referer, $host))) {
        return new RedirectResponse($referer);
      }
    }

    $log = $this->refundProcessor->loadRefundLog($logId);
    if ($log !== NULL && !empty($log['event_id'])) {
      return new RedirectResponse(Url::fromRoute('myeventlane_vendor.console.event_orders', [
        'event' => (int) $log['event_id'],
      ])->toString());
    }

    return new RedirectResponse($this->urlGenerator->generateFromRoute('<front>'));
  }

}
