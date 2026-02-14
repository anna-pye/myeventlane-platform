<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Controller;

use Drupal\myeventlane_ai\Entity\AiJob;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns JSON for AI job polling (no caching).
 */
final class AiJobPollController extends ControllerBase {

  /**
   * Poll endpoint: returns job status and result.
   *
   * GET /ai/job/{ai_job}
   * Entity access enforced by route requirements.
   */
  public function poll(AiJob $ai_job): JsonResponse {
    $data = [
      'status' => $ai_job->get('status')->value,
    ];

    if ($ai_job->get('status')->value === AiJob::STATUS_DONE) {
      $data['result_text'] = (string) ($ai_job->get('result_text')->value ?? '');
      $token_counts = $ai_job->get('token_counts')->value;
      if ($token_counts !== NULL && $token_counts !== '') {
        $decoded = json_decode($token_counts, TRUE);
        $data['token_counts'] = is_array($decoded) ? $decoded : NULL;
      }
      else {
        $data['token_counts'] = NULL;
      }
    }

    if ($ai_job->get('status')->value === AiJob::STATUS_ERROR) {
      $data['error_message'] = (string) ($ai_job->get('error_message')->value ?? '');
    }

    $response = new JsonResponse($data);
    $response->setCache([
      'max_age' => 0,
      'no_store' => TRUE,
    ]);
    return $response;
  }

}
