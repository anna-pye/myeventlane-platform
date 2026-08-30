# Drupal dependency updates for MyEventLane

This runbook covers routine patch and minor updates to Drupal core, contributed modules, and contributed themes. It does not cover a Drupal major-version upgrade or a module major-version change.

## The rule that matters

Do not run `composer update` on staging or production.

Resolve and test updates in an isolated local branch. Commit the reviewed `composer.json` and `composer.lock`. MEL's build/deploy workflow must install the exact lock file with `composer install`, then run the existing database, configuration, and cache steps.

An “update all” button is not safe automation for MEL. Composer can update the files, but it cannot prove that checkout, Stripe, refunds, recurring billing, Event Studio, organiser access, email, or ticket fulfilment still work.

## MEL-specific risks

- `drupal/core`, `drupal/image_widget_crop`, and `drupal/commerce_stripe` have MEL patches in `composer.json`. After an update, confirm each patch applied and is still needed.
- MEL currently requires `drupal/core` directly rather than `drupal/core-recommended`. That permits broader movement in core's transitive dependencies. Do not switch package models inside a routine update; investigate and test that architecture change separately.
- `drupal/commerce_recurring` is payment-critical and currently uses an RC constraint. Treat its update as a payment release.
- `drupal/conditional_fields` uses an alpha constraint and participates in Event Studio forms. Test AJAX, media, ticket setup, save, and publish behaviour.
- The updater respects existing Composer constraints. It will not perform major-version upgrades. Change constraints only in a separately investigated upgrade.
- Configuration import/export is deliberately not automated locally. MEL has environment-specific payment and domain configuration that must not be overwritten blindly.
- The current staging workflow runs database updates and configuration import. The repository does not create a staging database backup first. Confirm a current, restorable staging backup before merging a dependency update to `main`.

## Recommended update size

Use the smallest practical update:

1. One security-affected package and its required dependencies.
2. Drupal core in its own PR.
3. A small related group of contributed modules.
4. `--scope all` only when the whole dependency set has been reviewed and the wider regression testing is justified.

Separating core and contributed updates makes failures and rollbacks easier to understand.

## 1. Prepare an isolated update branch

Start from a clean, current integration checkout. Do not reuse a feature branch or a worktree containing product work.

```bash
bash scripts/dev/mel-sync-main.sh
bash scripts/dev/mel-start-feature.sh drupal-updates-YYYY-MM
cd ~/myeventlane-wt-drupal-updates-YYYY-MM
```

Make sure this DDEV site has a representative, privacy-safe local database. The updater refuses to proceed when database updates are already pending. It records existing configuration status so it can detect new drift.

## 2. Preview first

Preview core:

```bash
bash scripts/maintenance/mel-update-drupal.sh --plan --scope core
```

Preview contributed projects:

```bash
bash scripts/maintenance/mel-update-drupal.sh --plan --scope contrib
```

Preview one or more packages:

```bash
bash scripts/maintenance/mel-update-drupal.sh \
  --plan \
  --package drupal/token \
  --package drupal/pathauto
```

The plan validates the current lock file, checks Drupal bootstrap and database-update status, lists available direct Drupal updates, runs the production dependency audit, and performs a Composer dry run.

Before applying, read the release notes and issue queues for every selected Drupal project. Check PHP 8.3, Drupal 11, Drupal Commerce 3, and Drush compatibility. A solver result is not compatibility evidence.

## 3. Apply locally

Core:

```bash
bash scripts/maintenance/mel-update-drupal.sh --apply --scope core
```

Contributed projects:

```bash
bash scripts/maintenance/mel-update-drupal.sh --apply --scope contrib
```

Specific package:

```bash
bash scripts/maintenance/mel-update-drupal.sh \
  --apply \
  --package drupal/token
```

The script requires a clean non-`main` branch and an explicit confirmation. It then:

- saves `composer.json`, `composer.lock`, package versions, and configuration status under ignored `backups/`;
- exports the local DDEV database;
- updates only the selected direct Drupal packages and required dependencies, within current constraints;
- validates and audits the new lock file;
- checks that a production-style `composer install --no-dev` can resolve;
- runs `drush updatedb`, cache rebuild, and update status;
- compares configuration status before and after, without importing or exporting it;
- runs MEL's repository safety checks and shows the resulting Git/package diff.

If the script stops, do not deploy the partial result. Read the error. Recovery material is in the printed backup directory. Database restore is a deliberate action; do not restore over work you need to keep.

## 4. Review and test before committing

At minimum, review:

```bash
git status --short
git diff -- composer.json composer.lock
ddev composer audit --locked --no-dev
ddev drush updatedb:status
ddev drush config:status
```

Then choose tests from the changed package impact, not from convenience:

- Core: custom PHPUnit/contracts, governance tests, theme lint/build, admin and public smoke tests.
- Commerce: clean-cart line items, quantities, adjustments, tax, total, checkout, payment failure/success, confirmation, email, ticket issue, refunds, and ownership/access.
- Commerce Stripe: Stripe test mode, webhook handling, connected-account/direct-charge behaviour, refund paths, and saved payment methods where relevant.
- Commerce Recurring: subscribe, renewal, failed payment/recovery, cancel, entitlement changes, cron/queue processing, and email.
- Conditional Fields/Image Widget Crop: Event Studio create/edit, AJAX rebuilds, upload, crop, media library, tickets, save, preview, and publish.

Do not claim an area passed unless that test or journey actually ran.

## 5. Commit, validate, and stage

Commit only the reviewed dependency files and any necessary, explained compatibility changes. Once the branch is clean:

```bash
bash scripts/validate-release.sh staging
```

Push and open a PR. CI must pass, including Composer validation, install, security audit, static checks, and governance checks. A green PR still is not deployment acceptance.

Before merging, confirm a current, restorable staging database backup using the approved hosting or operational backup process. I cannot confirm a repository-managed staging database backup.

Deploy the exact approved commit to staging using MEL's existing release workflow. Re-run the affected end-to-end journeys against staging and record the commit, environment, test data, and result.

## 6. Production

Production requires explicit approval of the exact commit and deployment plan. The release must install from `composer.lock`; never solve fresh versions on the server. Confirm backup/rollback readiness, maintenance behaviour, database updates, configuration import policy, cache rebuild, release provenance, health checks, and the highest-risk customer/organiser journeys.

A merge is not a deployment. A deployment is not acceptance.

## Rollback boundary

Before database updates, code can usually be returned to the previous lock file and reinstalled. After update hooks run, code rollback alone may be unsafe because the database schema/data may have changed. Use the matching pre-update database backup and previous release together, following MEL's controlled rollback process.

Never delete `composer.lock`, use `--ignore-platform-reqs`, or force a dependency resolution to “make it pass”. Find and fix the actual constraint or compatibility problem.

## Official references

- [Updating Drupal core via Composer](https://www.drupal.org/docs/updating-drupal/updating-drupal-core-via-composer)
- [Updating modules and themes using Composer](https://www.drupal.org/docs/updating-drupal/updating-modules-and-themes-using-composer)
- [Deploying a Drupal update](https://www.drupal.org/docs/updating-drupal/deploying-a-drupal-update)
- [Composer command-line reference](https://getcomposer.org/doc/03-cli.md)

