# Staging indexing protection (`staging.myeventlane.com.au`)

This runbook applies **only** to staging and must never be applied to production (`myeventlane.com.au`).

## 1) Server-level `X-Robots-Tag` (required)

Add the header at the web server / edge layer for `staging.myeventlane.com.au` with `always` semantics so it is present on:

- HTML responses
- Drupal routes
- static files (including PDFs)
- error pages (403/404)

### Nginx example

Reference include file: [`infrastructure/nginx/staging-nginx.conf`](../../infrastructure/nginx/staging-nginx.conf)

```nginx
server {
  server_name staging.myeventlane.com.au;

  add_header X-Robots-Tag "noindex, nofollow" always;

  # Optional additional hardening.
  add_header Cache-Control "no-store" always;
}
```

### Apache vhost example

```apache
<VirtualHost *:443>
  ServerName staging.myeventlane.com.au

  Header always set X-Robots-Tag "noindex, nofollow"
</VirtualHost>
```

## 2) Optional additional protection: HTTP Basic Auth

If supported by hosting, add Basic Auth only on staging (recommended).

Do not commit plaintext credentials. Generate/pass secrets from environment/hosting secret manager.

### Nginx sketch (staging only)

```nginx
server {
  server_name staging.myeventlane.com.au;

  auth_basic "Restricted staging";
  auth_basic_user_file /etc/nginx/htpasswd/staging;
}
```

Provision `/etc/nginx/htpasswd/staging` from deployment secret material (not from git).

## 3) Drupal sitemap safety check (do not rely on robots.txt alone)

This repository does not currently include sitemap module config (`simple_sitemap` / `xmlsitemap`) in `config/sync`.

Verify in staging DB during deploy:

```bash
drush pml --status=enabled --type=module | grep -E 'simple_sitemap|xmlsitemap'
```

If either module is enabled on staging, disable sitemap generation/submission there only (do **not** change production behavior), e.g.:

```bash
drush pm:uninstall simple_sitemap xmlsitemap -y
```

If uninstall is not appropriate, set module-specific staging-only config to disable generation/submission.

## 4) Verification commands

Run after deployment:

```bash
curl -I https://staging.myeventlane.com.au
```

Expected header:

- `X-Robots-Tag: noindex, nofollow`

Production must not include the staging directive:

```bash
curl -I https://myeventlane.com.au
```

Expected:

- no `X-Robots-Tag: noindex, nofollow` staging header on production.

## Notes

- `web/robots.txt` may remain present, but it is not the primary control.
- Server/edge header and optional Basic Auth are the primary protection layers.
