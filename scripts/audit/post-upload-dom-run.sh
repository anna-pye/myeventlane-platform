#!/usr/bin/env bash
# Compact post-upload DOM audit via remove-then-upload AJAX chain.
set -euo pipefail
NODE_ID="${1:-1381}"
BASE="https://vendor.myeventlane.ddev.site"
COOK="/tmp/mel-dom-audit-cookies.txt"
PAGE="/tmp/mel-dom-page.html"
REMOVE="/tmp/mel-dom-remove.json"
UPLOAD="/tmp/mel-dom-upload.json"
IMG="/tmp/mel-dom-test-hero.jpg"

LOGIN="$(drush user:login --uid=1 --uri="$BASE" 2>/dev/null | tail -1)"
curl -skL -c "$COOK" "$LOGIN" -o /dev/null
curl -skL -b "$COOK" "$BASE/vendor/events/${NODE_ID}/studio/branding" -o "$PAGE"

FB="$(grep -o 'name="form_build_id" value="[^"]*"' "$PAGE" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
FT="$(grep -o 'name="form_token" value="[^"]*"' "$PAGE" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
FI="$(grep -o 'name="form_id" value="[^"]*"' "$PAGE" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"

# Remove hero (form state only).
curl -skL -b "$COOK" \
  -H "X-Requested-With: XMLHttpRequest" -H "Accept: application/json" \
  -F "form_build_id=$FB" -F "form_token=$FT" -F "form_id=$FI" -F "_drupal_ajax=1" \
  -F "_triggering_element_name=mel_field_event_image_0_remove_button" \
  -F "mel_field_event_image_0_remove_button=Remove" \
  "$BASE/vendor/events/${NODE_ID}/studio/branding?element_parents=mel/field_event_image/widget/0&ajax_form=1" \
  -o "$REMOVE"

# Real image for crop widget.
drush php:eval "
\$f = \Drupal::entityTypeManager()->getStorage('file')->load(454);
echo \$f ? \Drupal::service('file_system')->realpath(\$f->getFileUri()) : '';
" > /tmp/mel-dom-src.txt 2>/dev/null || true
SRC="$(tr -d '\n' < /tmp/mel-dom-src.txt)"
[[ -n "$SRC" && -f "$SRC" ]] && cp "$SRC" "$IMG"

# Upload.
curl -skL -b "$COOK" \
  -H "X-Requested-With: XMLHttpRequest" -H "Accept: application/json" \
  -F "form_build_id=$FB" -F "form_token=$FT" -F "form_id=$FI" -F "_drupal_ajax=1" \
  -F "_triggering_element_name=mel_field_event_image_0_upload_button" \
  -F "mel_field_event_image_0_upload_button=Upload" \
  -F "files[mel_field_event_image_0]=@$IMG" \
  "$BASE/vendor/events/${NODE_ID}/studio/branding?element_parents=mel/field_event_image/widget/0&ajax_form=1" \
  -o "$UPLOAD"

python3 <<'PY'
import json, re
from pathlib import Path

def html_from(path):
    raw = Path(path).read_text(errors='replace')
    chunks = []
    try:
        for cmd in json.loads(raw):
            if isinstance(cmd, dict) and cmd.get('command') in ('insert','replace','append','prepend','html') and cmd.get('data'):
                chunks.append(str(cmd['data']))
    except Exception as e:
        return raw, str(e)
    return '\n'.join(chunks), None

def report(label, text):
    print(f"\n=== {label} ===")
    print(f"html_len={len(text)}")
    checks = {
        'crop_wrapper': r'image-data__crop-wrapper',
        'cropper_container': r'cropper-container',
        'attention_class': r'mel-es-branding-crop-wrapper--attention',
        'file_input': r'type="file"',
        'event_hero_iwc': r'data-drupal-iwc-id="event_hero"',
        'crop_applied_0': r'crop_applied[^>]*value="0"',
        'crop_applied_1': r'crop_applied[^>]*value="1"',
        'fids_nonempty': r'name="mel\[field_event_image\]\[0\]\[fids\]" value="[0-9]+"',
    }
    for name, pat in checks.items():
        print(f"{name}={len(re.findall(pat, text))}")
    for m in re.finditer(r'<details[^>]*image-data__crop-wrapper[^>]*>', text):
        tag = m.group(0)
        print(f"details_open={'open' in tag.lower()} tag={tag[:180]}")
    for m in re.finditer(r'data-drupal-iwc-id="event_hero"[\s\S]{0,600}', text):
        print('event_hero_block:', m.group(0)[:600])

page = Path('/tmp/mel-dom-page.html').read_text(errors='replace')
remove_html, _ = html_from('/tmp/mel-dom-remove.json')
upload_html, err = html_from('/tmp/mel-dom-upload.json')

report('BEFORE UPLOAD (page with saved hero)', page)
report('BEFORE UPLOAD (after remove AJAX — empty widget)', remove_html)
report('AFTER UPLOAD (upload AJAX response)', upload_html)
if err:
    print('upload_json_note', err)
if len(upload_html) < 100:
    print('upload_raw_head', Path('/tmp/mel-dom-upload.json').read_text()[:500])
PY
