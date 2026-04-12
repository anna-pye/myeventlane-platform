## Release hardening (MyEventLane)

### Config sync directory (CRITICAL)

The **config sync directory** is the single source of truth for Drupal configuration. On artifact-based hosts it must be **overwritten on every deploy** using `rsync -av --delete` from the extracted release’s `config/sync` into the server’s shared sync path (for example `/home/mel/staging/config/sync`). Failure to do so causes config drift, misleading `drush cim` results, partial sync states, and production instability.

**Operational rule:** do not hand-edit YAML under the shared sync directory on the server. Change configuration in local development, run `drush cex`, commit to Git, and deploy a new artifact. Treat staging and production sync directories as **read-only** except for the deploy pipeline.

Staging/production deploys implement this via `scripts/deploy/remote-deploy.sh` (shared sync + `drush cim` + `drush cst` verification when `RUN_CIM=1`).

### When to run

- **Before tagging a release** (local/DDEV): ensure code + config are consistent and diagnostics are clean.
- **After deploying to staging**: verify runtime health without sending attendee-facing messages.
- **After deploying to production**: run read-only checks only (no test dispatch).

### Checklist

- **Cache rebuild**

```bash
ddev drush cr
```

- **Diagnostic health check (read-only)**

```bash
ddev drush mel:health
```

- **Queue dry run (read-only)**
  - Covered by `mel:health`:
    - Can instantiate key queue workers (plugin/container sanity).
    - Reports queue backlog counts for key queues.

- **Test email dispatch (DDEV or staging only)**
  - Queue a test message (pick an enabled template key) and then process the messaging queue.

```bash
ddev drush mel:msg-test order_receipt you@example.com
ddev drush mel:msg-run
```

  - Expected: command completes without errors; the message is visible in your mail capture/inbox.

- **Config drift check**

```bash
ddev drush config:status
```

  - Expected: **no differences** (or differences are understood and intentionally pending import/export).

### Expected output (mel:health)

- A table with **OK/WARN/FAIL** rows for:
  - **Config drift**
  - **Queue worker instantiation**
  - **Queue backlog counts**
  - **Messaging template Twig compilation**

Exit codes:
- **0**: no failures (may include warnings).
- **1**: one or more failures.

### Failure interpretation

- **Config drift = FAIL**
  - Active config differs from sync; releases should not proceed until resolved or explicitly acknowledged.
- **Queue worker = FAIL**
  - A queue worker plugin cannot be instantiated; expect cron/queue processing failures.
- **Queue backlog = FAIL**
  - Queue backend is not readable for that queue (schema/runtime issue).
- **Messaging templates = FAIL**
  - An enabled messaging template has invalid Twig syntax and will not render correctly.
