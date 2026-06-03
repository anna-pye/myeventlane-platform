#!/usr/bin/env bash
# Post-upload DOM audit: remove hero, capture empty state, upload, parse AJAX HTML.
set -euo pipefail

NODE_ID="${1:-1381}"
BASE="https://vendor.myeventlane.ddev.site"
COOK="/tmp/mel-dom-audit-cookies.txt"
EMPTY="/tmp/mel-dom-empty.html"
BEFORE="/tmp/mel-dom-before.html"
AFAX="/tmp/mel-dom-ajax.json"
AFHTML="/tmp/mel-dom-after.html"
IMG="/tmp/mel-dom-test-hero.jpg"

rm -f "$COOK" "$EMPTY" "$BEFORE" "$AFAX" "$AFHTML"

LOGIN="$(drush user:login --uid=1 --uri="$BASE" 2>/dev/null | tail -1)"
curl -skL -c "$COOK" "$LOGIN" -o /dev/null

fetch_form() {
  local out="$1"
  curl -skL -b "$COOK" "$BASE/vendor/events/${NODE_ID}/studio/branding" -o "$out"
}

form_meta() {
  local file="$1"
  FORM_BUILD_ID="$(grep -o 'name="form_build_id" value="[^"]*"' "$file" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
  FORM_TOKEN="$(grep -o 'name="form_token" value="[^"]*"' "$file" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
  FORM_ID="$(grep -o 'name="form_id" value="[^"]*"' "$file" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
}

count_in() {
  local label="$1" pat="$2" file="$3"
  local n
  n="$(grep -c "$pat" "$file" 2>/dev/null || true)"
  echo "${label}=${n}"
}

# State with existing hero (may already be uploaded).
fetch_form "$BEFORE"
form_meta "$BEFORE"
cp "$BEFORE" "$EMPTY"

echo "=== WITH EXISTING HERO (page load) ==="
count_in crop_wrapper 'image-data__crop-wrapper' "$BEFORE"
count_in cropper_container 'cropper-container' "$BEFORE"
count_in attention_class 'mel-es-branding-crop-wrapper--attention' "$BEFORE"
python3 - <<'PY'
import re
from pathlib import Path
t = Path("/tmp/mel-dom-before.html").read_text(errors="replace")
for m in re.finditer(r'<details[^>]*image-data__crop-wrapper[^>]*>', t):
    tag = m.group(0)
    print("details_open=" + str("open" in tag.lower()))
    print("details_tag=" + tag[:220])
PY

# Remove hero if fids present.
if grep -q 'name="mel\[field_event_image\]\[0\]\[fids\]" value="[0-9]' "$BEFORE"; then
  echo ""
  echo "=== REMOVING EXISTING HERO (AJAX) ==="
  curl -skL -b "$COOK" \
    -H "X-Requested-With: XMLHttpRequest" \
    -H "Accept: application/json" \
    -F "form_build_id=$FORM_BUILD_ID" \
    -F "form_token=$FORM_TOKEN" \
    -F "form_id=$FORM_ID" \
    -F "_drupal_ajax=1" \
    -F "_triggering_element_name=mel_field_event_image_0_remove_button" \
    -F "mel_field_event_image_0_remove_button=Remove" \
    "$BASE/vendor/events/${NODE_ID}/studio/branding?element_parents=mel/field_event_image/widget/0&ajax_form=1" \
    -o /tmp/mel-dom-remove.json
  fetch_form "$EMPTY"
  form_meta "$EMPTY"
fi

echo ""
echo "=== BEFORE UPLOAD (empty hero, full page) ==="
count_in crop_wrapper 'image-data__crop-wrapper' "$EMPTY"
count_in cropper_container 'cropper-container' "$EMPTY"
count_in attention_class 'mel-es-branding-crop-wrapper--attention' "$EMPTY"
count_in file_input 'type="file"' "$EMPTY"
python3 - <<'PY'
import re
from pathlib import Path
t = Path("/tmp/mel-dom-empty.html").read_text(errors="replace")
for m in re.finditer(r'type="file"[^>]*', t):
    print("file_input=" + m.group(0)[:240])
for m in re.finditer(r'name="mel_field_event_image_0[^"]*"', t):
    print("submit_name=" + m.group(0))
PY

# Test image: copy a real jpeg from files dir if available.
if ! [[ -s "$IMG" ]]; then
  drush php:eval "
    \$files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['status' => 1]);
    foreach (\$files as \$f) {
      if (str_starts_with(\$f->getMimeType(), 'image/') && file_exists(\$f->getFileUri())) {
        echo \Drupal::service('file_system')->realpath(\$f->getFileUri());
        break;
      }
    }
  " > /tmp/mel-dom-src.txt 2>/dev/null || true
  SRC="$(tr -d '\n' < /tmp/mel-dom-src.txt)"
  if [[ -n "$SRC" && -f "$SRC" ]]; then
    cp "$SRC" "$IMG"
  fi
fi
if ! [[ -s "$IMG" ]]; then
  echo "ERROR: no test image" >&2
  exit 2
fi
echo "test_image=$IMG size=$(wc -c < "$IMG")"

FILE_INPUT="$(python3 - <<'PY'
import re
from pathlib import Path
t = Path("/tmp/mel-dom-empty.html").read_text(errors="replace")
m = re.search(r'name="(files\[[^\]]+\])"', t)
print(m.group(1) if m else "")
PY
)"
UPLOAD_SUBMIT="$(python3 - <<'PY'
import re
from pathlib import Path
t = Path("/tmp/mel-dom-empty.html").read_text(errors="replace")
for pat in [r'name="(mel_field_event_image_0_upload_button)"', r'name="(mel\[field_event_image\]\[0\]\[upload\])"']:
    m = re.search(pat, t)
    if m:
        print(m.group(1))
        break
PY
)"

echo "file_input_name=$FILE_INPUT"
echo "upload_submit_name=$UPLOAD_SUBMIT"

if [[ -z "$FILE_INPUT" || -z "$UPLOAD_SUBMIT" ]]; then
  echo "Could not resolve upload field names."
  grep -n 'field_event_image' "$EMPTY" | head -25
  exit 2
fi

echo ""
echo "=== UPLOAD (managed file AJAX) ==="
curl -skL -b "$COOK" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Accept: application/json" \
  -F "form_build_id=$FORM_BUILD_ID" \
  -F "form_token=$FORM_TOKEN" \
  -F "form_id=$FORM_ID" \
  -F "_drupal_ajax=1" \
  -F "_triggering_element_name=$UPLOAD_SUBMIT" \
  -F "${UPLOAD_SUBMIT}=Upload" \
  -F "$FILE_INPUT=@$IMG" \
  "$BASE/vendor/events/${NODE_ID}/studio/branding?element_parents=mel/field_event_image/widget/0&ajax_form=1" \
  -o "$AFAX"

echo "ajax_bytes=$(wc -c < "$AFAX")"
head -c 300 "$AFAX"
echo ""

python3 - <<'PY'
import json, re
from pathlib import Path

raw = Path("/tmp/mel-dom-ajax.json").read_text(errors="replace")
html_chunks = []
try:
    data = json.loads(raw)
    if isinstance(data, list):
        for cmd in data:
            if isinstance(cmd, dict):
                if cmd.get("command") in ("insert", "replace", "append", "prepend", "html", "settings"):
                    if cmd.get("data"):
                        html_chunks.append(str(cmd["data"]))
except json.JSONDecodeError as e:
    print("json_error=" + str(e))

combined = "\n".join(html_chunks) if html_chunks else raw
Path("/tmp/mel-dom-after.html").write_text(combined)

def count(pat, text):
    return len(re.findall(pat, text))

print("\n=== AFTER UPLOAD (AJAX HTML fragments) ===")
print(f"crop_wrapper_count={count(r'image-data__crop-wrapper', combined)}")
print(f"cropper_container_count={count(r'cropper-container', combined)}")
print(f"attention_class={count(r'mel-es-branding-crop-wrapper--attention', combined)}")
print(f"crop_applied_0={count(r'crop_applied[^>]*value=\"0\"', combined)}")
print(f"crop_applied_1={count(r'crop_applied[^>]*value=\"1\"', combined)}")
print(f"image_widget_crop={count(r'image-widget-crop', combined)}")
print(f"iwc_crop_type_event_hero={count(r'data-drupal-iwc-id=\"event_hero\"', combined)}")

for dm in re.finditer(r'<details[^>]*image-data__crop-wrapper[^>]*>', combined):
    tag = dm.group(0)
    print("after_details_open=" + str("open" in tag.lower()))
    print("after_details_tag=" + tag[:240])

m = re.search(r'data-drupal-iwc-id="event_hero"[\s\S]{0,1200}', combined)
if m:
    print("\n--- event_hero crop block (truncated) ---")
    print(m.group(0)[:1200])
elif re.search(r'image-data__crop-wrapper', combined):
    m2 = re.search(r'.{0,100}image-data__crop-wrapper.{0,900}', combined, re.S)
    if m2:
        print("\n--- crop-wrapper snippet ---")
        print(m2.group(0)[:1000])
else:
    print("\nNo crop-wrapper in AJAX response.")
    if "error" in combined.lower()[:500]:
        print(combined[:800])
PY
