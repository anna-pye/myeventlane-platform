#!/usr/bin/env bash
# Audit-only: verify branding page + gallery AJAX after upload-rebuild changes.
set -euo pipefail
LOGIN=$(drush user:login --uid=1 --uri=https://vendor.myeventlane.ddev.site 2>/dev/null | tail -1)
COOK=/tmp/mel-audit-cookies.txt
curl -skL -c "$COOK" "$LOGIN" -o /dev/null
curl -skL -b "$COOK" "https://vendor.myeventlane.ddev.site/vendor/events/1381/studio/branding" -o /tmp/mel-branding-get.html
echo "crop_notice_count=$(grep -c 'mel-es-branding-crop-notice' /tmp/mel-branding-get.html || true)"
echo "hero_tools_lib=$(grep -c 'mel_branding_hero_tools' /tmp/mel-branding-get.html || true)"
FB=$(grep -oP 'name="form_build_id" value="\K[^"]+' /tmp/mel-branding-get.html | head -1)
FT=$(grep -oP 'name="form_token" value="\K[^"]+' /tmp/mel-branding-get.html | head -1)
echo "form_build_id=${FB:0:24}..."
curl -skL -b "$COOK" -X POST \
  "https://vendor.myeventlane.ddev.site/vendor/events/1381/studio/branding?ajax_form=1&_wrapper_format=drupal_ajax" \
  -H "X-Requested-With: XMLHttpRequest" \
  -o /tmp/mel-gallery-ajax.json \
  -w "HTTP=%{http_code} CT=%{content_type}\n" \
  --data-urlencode "form_id=myeventlane_event_studio_branding_form" \
  --data-urlencode "form_build_id=$FB" \
  --data-urlencode "form_token=$FT" \
  --data-urlencode "field_mel_event_gallery-media-library-open-button-mel=Add media"
head -c 180 /tmp/mel-gallery-ajax.json; echo
grep -q 'OpenModalDialogCommand' /tmp/mel-gallery-ajax.json && echo AJAX=modal_json || echo AJAX=not_modal
grep -q 'mel_event_studio_empty_state--unavailable' /tmp/mel-gallery-ajax.json && echo EMPTY_STATE=yes || echo EMPTY_STATE=no
