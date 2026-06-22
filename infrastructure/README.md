# MEL infrastructure reference

Version-controlled **reference configuration** for server and edge layers. These files are **not** applied by GitHub Actions deploy jobs; operators install or include them on the target host.

## Ownership

| Path | Purpose | Owner | Applied by |
|------|---------|-------|------------|
| `nginx/staging-nginx.conf` | Staging security headers (`X-Robots-Tag`, cache hardening, optional Basic Auth) | Platform / deploy ops | Manual nginx include on staging VPS |

## Related runbooks

- [docs/STAGING_SETUP.md](../docs/STAGING_SETUP.md) — staging hardening setup (robots.txt, nginx include, env vars)
- [docs/operations/STAGING_INDEXING_PROTECTION.md](../docs/operations/STAGING_INDEXING_PROTECTION.md) — staging indexing protection verification
- [docs/STAGING_DEPLOY_GIT.md](../docs/STAGING_DEPLOY_GIT.md) — GitHub Actions staging deploy flow

## Conventions

1. Do not place Drupal application code here.
2. Do not commit secrets, htpasswd files, or TLS private keys.
3. Deploy artifacts intentionally exclude `infrastructure/` — copy or symlink configs on the server during provisioning, not on every app release.
4. When adding new reference configs, document path, owner, and runbook link in this file.
