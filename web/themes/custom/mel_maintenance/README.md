# MEL recovery pages

Maintenance mode uses `$settings['maintenance_theme'] = 'mel_maintenance'`.
Drupal returns HTTP 503 to visitors without maintenance bypass permission.
Administrators with bypass permission continue to see the normal site.

`system.site` maps errors to `/mel/403` and `/mel/404`. The core module's
`ErrorController` renders `page__403` / `page__404` through Drupal's bare HTML
renderer with this theme explicitly active. This is intentional: exception
subrequests can inherit an already selected public or vendor theme, so a route
theme negotiator alone is insufficient. The previous theme is restored even
if rendering fails. Responses retain 403/404 status and are not stored in caches.

The supplied MEL artwork is release-owned in `images/mel-thinking.png` and
shown at its native size or smaller. No managed-file or external image service
is needed. Maintenance retains the configurable site maintenance message.

Build from the repository root:

```sh
node_modules/.bin/sass web/themes/custom/mel_maintenance/scss:web/themes/custom/mel_maintenance/css --no-source-map --style=compressed
ddev drush cr
```

Verify anonymous requests to a missing path and a protected admin route, not
just direct visits to the two error routes. Check maintenance with an anonymous
session in local DDEV, and restore its prior state after testing. Do not enable
maintenance on staging or production merely to preview the design.
