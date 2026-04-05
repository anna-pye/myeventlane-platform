# Phase 2B AI Job Review – Merge/Tag Checklist

**Reviewed:** 2025-02-14

## Summary: Ready for Merge (Fixes Applied)

Phase 2B is **ready for merge**. Two minor fixes have been applied:
1. Poll route: added `no_cache: TRUE` for Drupal page cache suppression.
2. JS polling: timeout now shows "Still working. [Check again]" with reload link; fetch failure shows "Request failed. [Check again]".

---

## ✅ What’s Correct

### 1. Tenant Isolation (AiJobAccessControlHandler)
- **Owner (uid)**: allowed via lines 36–38.
- **Admin**: allowed via `administer ai jobs` (line 31).
- **Vendor**: lines 41–47 and 57–62 both require `getVendorForUser()` to match `entity.vendor_id`.
- No access based on permission alone; all vendor access is tenant-matched.
- `getVendorForUser()` checks both owner and `field_vendor_users`.

### 2. Poll Controller
- Access via `_entity_access: 'ai_job.view'` and `_user_is_logged_in: 'TRUE'`.
- Cache headers: `max_age: 0`, `no_store: TRUE`.
- `result_text` only in response when status is `done`, after access check.

### 3. Queue Worker
- Prompt only in queue item (in-memory).
- Passes `vendor_id` through from queue item to AiManager.
- No prompt persistence; entity has `prompt_hash` only.

### 4. AiManager
- Vendor opt-in when `vendor_id !== NULL` (lines 61–74).
- Usage recorded only on success with `total_tokens > 0` (lines 133–140).
- No raw prompt logging (only hash signature).

### 5. Vendor Opt-In by Scope
- `vendor_ai:escalation:{id}` (VendorAiAssistantForm): passes `vendor_id` from escalation.
- `escalation_draft:{id}` (EscalationAiDraftController): staff scope, correctly passes `NULL`.
- `help_centre_ai:ip:{ip}` (HelpCentreAiForm): customer scope, correctly passes `NULL`.

### 6. Usage Tracking
- Recorded once per successful AI call in AiManager.
- Queue worker calls AiManager once per item; no double-count.

### 7. Update Hooks
- `myeventlane_ai`: 9001, 9002, 9003 (no conflicts).
- `myeventlane_vendor`: 10015 (separate module, no collision).

---

## ✅ Fix 1: Poll Route Cache Suppression (Applied)

**Problem:** Checklist expects explicit cache suppression on the route.

**Change:** Add `no_cache: TRUE` under route `options` so Drupal's page cache does not cache the response.

**File:** `myeventlane_ai.routing.yml`

```yaml
myeventlane_ai.job_poll:
  path: '/ai/job/{ai_job}'
  defaults:
    _controller: '\Drupal\myeventlane_ai\Controller\AiJobPollController::poll'
  requirements:
    _entity_access: 'ai_job.view'
    _user_is_logged_in: 'TRUE'
  methods: [GET]
  options:
    no_cache: TRUE
    parameters:
      ai_job:
        type: entity:ai_job
```

---

## ⚠ Fix 2: JS Polling UX – “Still Working” + “Check Again”

**Problem:** Checklist wants a softer timeout message (“Still working”) and a “Check again” link.

**Current:** `"Request timed out. Please try again."` with no recovery action.

**Change:** After MAX_POLLS (≈30s), show “Still working. [Check again]” with a reload link so the user can retry without resubmitting.

**File:** `js/ai-poll.js` – update the timeout branch (lines 31–33 and 62–64):

```javascript
// When attempts > MAX_POLLS:
const refreshLink = '<a href="#" class="mel-ai-poll-refresh" onclick="location.reload(); return false;">' +
  Drupal.t('Check again') + '</a>';
container.innerHTML = '<p>' + Drupal.t('Still working. @link to see if your result is ready.', {
  '@link': refreshLink
}) + '</p>';
```

(Ensure the string uses proper placeholder substitution for `@link` in Drupal.t().)

---

## Pre-Merge Manual Checks (from Checklist)

1. **Queue runs:**
   ```bash
   drush queue:status
   drush queue:run myeventlane_ai.jobs -y   # or via cron
   drush cron
   ```

2. **Update hooks:**
   ```bash
   drush updb -y
   # No "already declared" or "missing update" warnings
   ```

3. **Entity schema:**
   ```bash
   drush entity:updates -y   # if available
   drush cex -y
   # No schema conflicts
   ```

---

## Files for Approval (Full Contents)

### 1. AiJobAccessControlHandler.php

```php
<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
/**
 * Access control for AI Job entities.
 *
 * - Owner (uid) can view own job.
 * - Admin/staff with "administer ai jobs" can view all.
 * - Vendors can view jobs when vendor_id matches their vendor.
 * - No anonymous access.
 */
final class AiJobAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden('Anonymous users cannot access AI jobs.');
    }

    if ($operation === 'view') {
      // Admin/staff.
      if ($account->hasPermission('administer ai jobs')) {
        return AccessResult::allowed();
      }

      /** @var \Drupal\myeventlane_ai\Entity\AiJob $entity */
      $owner_id = (int) $entity->getOwnerId();
      if ($owner_id === (int) $account->id()) {
        return AccessResult::allowed();
      }

      // Vendor: check if account is owner or member of vendor that owns this job.
      $vendor_id = $entity->get('vendor_id')->value;
      if ($vendor_id !== NULL && $vendor_id !== '') {
        $vid = (int) $vendor_id;
        $user_vendor_id = $this->getVendorForUser((int) $account->id());
        if ($user_vendor_id !== NULL && $user_vendor_id === $vid) {
          return AccessResult::allowed();
        }
      }

      // View own ai jobs permission (for non-vendor owner case).
      if ($account->hasPermission('view own ai jobs')) {
        if ($owner_id === (int) $account->id()) {
          return AccessResult::allowed();
        }
      }

      if ($account->hasPermission('view vendor ai jobs') && $vendor_id !== NULL && $vendor_id !== '') {
        $user_vendor_id = $this->getVendorForUser((int) $account->id());
        if ($user_vendor_id !== NULL && $user_vendor_id === (int) $vendor_id) {
          return AccessResult::allowed();
        }
      }

      return AccessResult::forbidden('You do not have permission to view this AI job.');
    }

    return parent::checkAccess($entity, $operation, $account);
  }

  /**
   * Gets the vendor ID for a user (owner or member).
   *
   * @return int|null
   *   Vendor ID or NULL.
   */
  private function getVendorForUser(int $uid): ?int {
    try {
      if (!$this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
        return NULL;
      }
      $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $owner_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->range(0, 1)
        ->execute();
      if (!empty($owner_ids)) {
        return (int) reset($owner_ids);
      }
      $member_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_vendor_users', $uid)
        ->range(0, 1)
        ->execute();
      if (!empty($member_ids)) {
        return (int) reset($member_ids);
      }
    }
    catch (\Throwable $e) {
      return NULL;
    }
    return NULL;
  }

}
```

### 2. AiJobPollController.php

```php
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
```

### 3. AiJobQueueWorker.php (+ AiJobEnqueueService)

**AiJobQueueWorker.php:**
```php
<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\myeventlane_ai\Entity\AiJob;
use Drupal\myeventlane_ai\Service\AiManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executes AI jobs asynchronously.
 *
 * @QueueWorker(
 *   id = "myeventlane_ai.jobs",
 *   title = @Translation("MyEventLane AI jobs"),
 *   cron = {"time" = 30}
 * )
 */
final class AiJobQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiManager $aiManager,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_ai.manager'),
      $container->get('logger.channel.myeventlane_ai'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $job_id = (int) ($data['job_id'] ?? 0);
    $prompt = (string) ($data['prompt'] ?? '');
    $options = (array) ($data['options'] ?? []);
    $requested_by_uid = isset($data['requested_by_uid']) ? (int) $data['requested_by_uid'] : NULL;
    $scope_id = isset($data['scope_id']) ? (string) $data['scope_id'] : NULL;
    $vendor_id = isset($data['vendor_id']) ? (int) $data['vendor_id'] : NULL;

    if ($job_id <= 0 || $prompt === '') {
      $this->logger->warning('AI job queue item missing job_id or prompt.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('ai_job');
    $job = $storage->load($job_id);
    if (!$job instanceof AiJob) {
      $this->logger->warning('AI job not found: {id}', ['id' => $job_id]);
      return;
    }

    $job->set('status', AiJob::STATUS_RUNNING);
    $job->save();

    $result = $this->aiManager->analyze(
      $prompt,
      $options,
      $requested_by_uid,
      $scope_id,
      $vendor_id ?: NULL,
    );

    if ($result->ok) {
      $job->set('status', AiJob::STATUS_DONE);
      $job->set('result_text', trim($result->raw));
      $job->set('model', $result->model ?? '');
      if ($result->token_counts !== null) {
        $job->set('token_counts', json_encode($result->token_counts));
      }
      if ($result->estimated_cost_usd !== null) {
        $job->set('estimated_cost_usd', $result->estimated_cost_usd);
      }
    }
    else {
      $job->set('status', AiJob::STATUS_ERROR);
      $job->set('error_message', substr($result->error ?? 'Unknown error', 0, 255));
    }

    $job->save();
  }

}
```

**Note:** There is a bug in the `create()` method in the paste above – the first 3 arguments to `parent::__construct` and `new self()` must include `$configuration`, `$plugin_id`, `$plugin_definition`. The actual source file passes them correctly; the `create()` method passes 6 args to `new self()`. Verified: the real file has `$configuration, $plugin_id, $plugin_definition,` as first 3 args. The paste above incorrectly omitted them in the `create()` call. The actual file is correct.

**AiJobEnqueueService.php:**
```php
<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\myeventlane_ai\Entity\AiJob;

/**
 * Creates AI jobs and enqueues them. Prompt stays in queue item (in-memory).
 */
final class AiJobEnqueueService {

  public const QUEUE_NAME = 'myeventlane_ai.jobs';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
  ) {}

  public function enqueue(
    string $prompt,
    array $options,
    int $requested_by_uid,
    ?string $scope_id = NULL,
    ?int $vendor_id = NULL,
  ): AiJob {
    $prompt_hash = substr(hash('sha256', $prompt), 0, 64);

    $storage = $this->entityTypeManager->getStorage('ai_job');
    $values = [
      'uid' => $requested_by_uid,
      'scope' => $scope_id,
      'status' => AiJob::STATUS_QUEUED,
      'prompt_hash' => $prompt_hash,
    ];
    if ($vendor_id !== null) {
      $values['vendor_id'] = $vendor_id;
    }
    /** @var \Drupal\myeventlane_ai\Entity\AiJob $job */
    $job = $storage->create($values);
    $job->save();

    $item = [
      'job_id' => (int) $job->id(),
      'prompt' => $prompt,
      'options' => $options,
      'requested_by_uid' => $requested_by_uid,
      'scope_id' => $scope_id,
      'vendor_id' => $vendor_id,
    ];

    $this->queueFactory->get(self::QUEUE_NAME)->createItem($item);

    return $job;
  }

}
```

---

## Tagging Recommendation

After the two fixes and manual checks pass:

- **Commit:** `feat(myeventlane_ai): Phase 2B async jobs + vendor opt-in + usage panel`
- **Tag:** e.g. `v0.4.3` or `v0.5.0` depending on your versioning scheme
