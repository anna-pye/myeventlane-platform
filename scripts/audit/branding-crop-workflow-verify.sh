#!/usr/bin/env bash
# Branding crop workflow verification (Tests 1–3) via HTTP + Drush.
# Run: ddev exec bash scripts/audit/branding-crop-workflow-verify.sh [nid]
set -euo pipefail

NODE_ID="${1:-1381}"
BASE="${BASE_URL:-https://myeventlane.ddev.site}"
COOK="/tmp/mel-crop-verify-cookies.txt"
WORKDIR="/tmp/mel-crop-verify"
REPORT="${WORKDIR}/report.json"
IMG="${WORKDIR}/test-hero.jpg"
mkdir -p "$WORKDIR"
rm -f "$COOK"

drush_eval() {
  drush php:eval "$1"
}

node_state() {
  drush_eval "
    \$n = \\Drupal\\node\\Entity\\Node::load(${NODE_ID});
    if (!\$n) { echo json_encode(['error' => 'missing']); return; }
    \$hero = \$n->get('field_event_image')->isEmpty() ? null : (int) \$n->get('field_event_image')->target_id;
    \$gallery = [];
    foreach (\$n->get('field_mel_event_gallery') as \$item) {
      \$gallery[] = (int) \$item->target_id;
    }
    echo json_encode(['hero_fid' => \$hero, 'gallery' => \$gallery]);
  "
}

clear_hero_on_node() {
  drush_eval "
    \$n = \\Drupal\\node\\Entity\\Node::load(${NODE_ID});
    if (!\$n) { throw new \\RuntimeException('missing node'); }
    \$n->set('field_event_image', []);
    \$n->setNewRevision(TRUE);
    \$n->revision_log = 'Audit: clear hero for branding crop verification.';
    \$n->save();
    echo 'cleared';
  " >/dev/null
}

fetch_form() {
  local out="$1"
  curl -skL -b "$COOK" -c "$COOK" "$BASE/vendor/events/${NODE_ID}/studio/branding" -o "$out"
}

parse_meta() {
  local file="$1"
  FORM_BUILD_ID="$(grep -o 'name="form_build_id" value="[^"]*"' "$file" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
  FORM_TOKEN="$(grep -o 'name="form_token" value="[^"]*"' "$file" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
  FORM_ID="$(grep -o 'name="form_id" value="[^"]*"' "$file" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
}

login() {
  local uli
  uli="$(drush user:login --uid=1 --uri="$BASE" 2>/dev/null | tail -1)"
  curl -skL -c "$COOK" "$uli" -o /dev/null
}

prepare_image() {
  if [[ ! -s "$IMG" ]]; then
    drush_eval "
      foreach (\\Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['status' => 1]) as \$f) {
        if (str_starts_with(\$f->getMimeType(), 'image/jpeg') && file_exists(\$f->getFileUri())) {
          \$p = \\Drupal::service('file_system')->realpath(\$f->getFileUri());
          if (\$p && filesize(\$p) > 50000) { echo \$p; break; }
        }
      }
    " > "${WORKDIR}/src.txt"
    cp "$(tr -d '\n' < "${WORKDIR}/src.txt")" "$IMG"
  fi
}

resolve_upload_names() {
  local page="$1"
  FILE_INPUT="$(python3 - <<'PY' "$page"
import re, sys
t = open(sys.argv[1]).read()
m = re.search(r'name="(files\[[^\]]+\])"', t)
print(m.group(1) if m else "")
PY
)"
  UPLOAD_BTN="$(python3 - <<'PY' "$page"
import re, sys
t = open(sys.argv[1]).read()
for pat in [r'name="(mel_field_event_image_0_upload_button)"', r'name="(mel\[field_event_image\]\[0\]\[upload\])"']:
    m = re.search(pat, t)
    if m:
        print(m.group(1)); break
PY
)"
}

ajax_upload_hero() {
  local page="$1"
  parse_meta "$page"
  resolve_upload_names "$page"
  if [[ -z "$FILE_INPUT" || -z "$UPLOAD_BTN" ]]; then
    echo "ERROR: upload field names not found on page without hero" >&2
    grep -n 'type="file"\|upload_button\|field_event_image' "$page" | head -20 >&2 || true
    return 1
  fi
  curl -skL -b "$COOK" \
    -H "X-Requested-With: XMLHttpRequest" \
    -F "form_build_id=$FORM_BUILD_ID" \
    -F "form_token=$FORM_TOKEN" \
    -F "form_id=$FORM_ID" \
    -F "_drupal_ajax=1" \
    -F "_triggering_element_name=$UPLOAD_BTN" \
    -F "${UPLOAD_BTN}=Upload" \
    -F "$FILE_INPUT=@$IMG" \
    "$BASE/vendor/events/${NODE_ID}/studio/branding?element_parents=mel/field_event_image/widget/0&ajax_form=1" \
    -o "${WORKDIR}/upload-ajax.json"
}

extract_upload_fid() {
  python3 - <<'PY'
import json, re
from pathlib import Path
raw = Path("/tmp/mel-crop-verify/upload-ajax.json").read_text(errors="replace")
fid = None
try:
    data = json.loads(raw)
    if isinstance(data, list):
        for cmd in data:
            html = str(cmd.get("data", ""))
            m = re.search(r'\[fids\]" value="(\d+)"', html)
            if m:
                fid = m.group(1)
                break
except json.JSONDecodeError:
    pass
if not fid:
    m = re.search(r'\[fids\]" value="(\d+)"', raw)
    fid = m.group(1) if m else None
print(fid or "")
PY
}

extract_form_build_from_ajax() {
  python3 - <<'PY'
import json, re
from pathlib import Path
raw = Path("/tmp/mel-crop-verify/upload-ajax.json").read_text(errors="replace")
fb = None
try:
    data = json.loads(raw)
    if isinstance(data, list):
        for cmd in data:
            if cmd.get("command") == "updateBuildId" and cmd.get("new"):
                fb = cmd["new"]
                break
            html = str(cmd.get("data", ""))
            m = re.search(r'name="form_build_id" value="([^"]+)"', html)
            if m:
                fb = m.group(1)
except json.JSONDecodeError:
    pass
print(fb or "")
PY
}

post_save_branding() {
  local page="$1"
  local fid="$2"
  local crop_applied="$3"
  local alt="$4"
  local include_gallery="${5:-1}"
  parse_meta "$page"
  local changed revision section
  changed="$(grep -o 'name="mel_studio_changed" value="[^"]*"' "$page" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
  revision="$(grep -o 'name="mel_studio_revision" value="[^"]*"' "$page" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
  section="$(grep -o 'name="mel_studio_section" value="[^"]*"' "$page" | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')"
  local out="${WORKDIR}/save-response.html"
  local -a args=(
    -skL -b "$COOK" -c "$COOK"
    -o "$out" -w "%{http_code}"
    -X POST
    "$BASE/vendor/events/${NODE_ID}/studio/branding"
    --data-urlencode "form_id=$FORM_ID"
    --data-urlencode "form_build_id=$FORM_BUILD_ID"
    --data-urlencode "form_token=$FORM_TOKEN"
    --data-urlencode "nid=$NODE_ID"
    --data-urlencode "op=Save branding"
    --data-urlencode "mel_studio_section=${section:-branding}"
    --data-urlencode "mel_studio_changed=${changed:-0}"
    --data-urlencode "mel_studio_revision=${revision:-0}"
    --data-urlencode "mel[field_event_image_alt]=$alt"
    --data-urlencode "mel[field_mel_page_style]=classic"
    --data-urlencode "mel[field_mel_theme_colour]=coral"
  )
  if [[ -n "$fid" ]]; then
    args+=(--data-urlencode "mel[field_event_image][0][fids]=$fid")
    args+=(--data-urlencode "mel[field_event_image][0][display]=1")
    args+=(--data-urlencode "mel[field_event_image][0][image_crop][crop_wrapper][event_hero][crop_container][values][crop_applied]=$crop_applied")
    if [[ "$crop_applied" == "1" ]]; then
      args+=(--data-urlencode "mel[field_event_image][0][image_crop][crop_wrapper][event_hero][crop_container][values][width]=1200")
      args+=(--data-urlencode "mel[field_event_image][0][image_crop][crop_wrapper][event_hero][crop_container][values][height]=630")
      args+=(--data-urlencode "mel[field_event_image][0][image_crop][crop_wrapper][event_hero][crop_container][values][x]=100")
      args+=(--data-urlencode "mel[field_event_image][0][image_crop][crop_wrapper][event_hero][crop_container][values][y]=50")
    fi
  fi
  if [[ "$include_gallery" == "1" ]]; then
    local gallery_id
    gallery_id="$(python3 - <<'PY' "$page"
import re, sys
t = open(sys.argv[1]).read()
m = re.search(r'name="mel\[field_mel_event_gallery\]\[selection\]\[0\]\[target_id\]" value="(\d+)"', t)
print(m.group(1) if m else "")
PY
)"
    if [[ -n "$gallery_id" ]]; then
      args+=(--data-urlencode "mel[field_mel_event_gallery][selection][0][target_id]=$gallery_id")
    fi
  fi
  local http
  http="$(curl "${args[@]}")"
  echo "$http|$out"
}

drush_save_branding() {
  local fid="$1"
  local crop_applied="$2"
  local alt="$3"
  local include_gallery="${4:-1}"
  drush_eval "
    \$user = \\Drupal::entityTypeManager()->getStorage('user')->load(1);
    \\Drupal::currentUser()->setAccount(\$user);
    \$node = \\Drupal::entityTypeManager()->getStorage('node')->load(${NODE_ID});
    if (!\$node) { throw new \\RuntimeException('missing node'); }
    \$form_object = \\Drupal::classResolver()->getInstanceFromDefinition(\\Drupal\\myeventlane_event_studio\\Form\\EventBrandingForm::class);
    \$fs = new \\Drupal\\Core\\Form\\FormState();
    \$form = \$form_object->buildForm([], \$fs, \$node);
    \$mel = [
      'field_event_image_alt' => '${alt}',
      'field_mel_page_style' => 'classic',
      'field_mel_theme_colour' => 'coral',
    ];
    if ('${fid}' !== '') {
      \$hero = [
        'fids' => (string) ${fid},
        'display' => 1,
        'image_crop' => [
          'crop_wrapper' => [
            'event_hero' => [
              'crop_container' => [
                'values' => [
                  'crop_applied' => '${crop_applied}',
                ],
              ],
            ],
          ],
        ],
      ];
      if ('${crop_applied}' === '1') {
        \$hero['image_crop']['crop_wrapper']['event_hero']['crop_container']['values'] += [
          'width' => '1200', 'height' => '630', 'x' => '100', 'y' => '50',
        ];
      }
      \$mel['field_event_image'] = [0 => \$hero];
    }
    if ('${include_gallery}' === '1' && \$node->hasField('field_mel_event_gallery')) {
      \$selection = [];
      foreach (\$node->get('field_mel_event_gallery') as \$delta => \$item) {
        \$selection[] = ['target_id' => (string) \$item->target_id];
      }
      if (\$selection !== []) {
        \$mel['field_mel_event_gallery'] = ['selection' => \$selection];
      }
    }
    \$fs->setValues([
      'nid' => '${NODE_ID}',
      'mel_studio_changed' => \$node->getChangedTime(),
      'mel_studio_revision' => \$node->getRevisionId(),
      'mel_studio_section' => 'branding',
      'mel' => \$mel,
    ]);
    \$fs->setCompleteForm(\$form);
    \$form_object->validateForm(\$form, \$fs);
    if (\$fs->getErrors()) {
      echo json_encode(['ok' => false, 'errors' => array_map('strval', \$fs->getErrors())]);
      return;
    }
    \$form_object->submitContinue(\$form, \$fs);
    \$node = \\Drupal::entityTypeManager()->getStorage('node')->load(${NODE_ID});
    echo json_encode([
      'ok' => true,
      'hero_fid' => \$node->get('field_event_image')->isEmpty() ? null : (int) \$node->get('field_event_image')->target_id,
      'gallery' => array_map(fn(\$i) => (int) \$i->target_id, iterator_to_array(\$node->get('field_mel_event_gallery'))),
    ]);
  "
}

analyze_page() {
  local file="$1"
  python3 - <<'PY' "$file"
import re, sys
t = open(sys.argv[1], errors="replace").read()
notice_hidden = bool(re.search(r'id="mel-es-branding-crop-notice"[^>]*hidden="hidden"', t))
notice_present = 'mel-es-branding-crop-notice' in t
notice_mentions_gallery = bool(re.search(r'mel-es-branding-crop-notice[\s\S]{0,500}gallery', t, re.I))
crop_error = bool(re.search(r'image-data__crop-wrapper[\s\S]{0,200}\berror\b|details[^>]*\berror\b[^>]*image-data__crop-wrapper', t))
crop_applied = re.search(r'crop_applied[^>]*value="(\d)"', t)
hero_preview = bool(re.search(r'field_event_image[\s\S]{0,2500}preview[\s\S]{0,600}<img', t))
gallery_count = len(re.findall(r'js-media-library-item', t))
errors = re.findall(r'messages--error[^>]*>([^<]+)', t)
success = re.findall(r'messages--status[^>]*>([^<]+)', t)
required_msg = bool(re.search(r'Event hero \(1200×630\) is required|event hero.*required', t, re.I))
branding_saved = bool(re.search(r'Branding saved', t, re.I))
print(f"notice_present={notice_present}")
print(f"notice_hidden={notice_hidden}")
print(f"notice_mentions_gallery={notice_mentions_gallery}")
print(f"crop_error={crop_error}")
print(f"crop_applied={crop_applied.group(1) if crop_applied else 'none'}")
print(f"hero_preview={hero_preview}")
print(f"gallery_count={gallery_count}")
print(f"required_msg={required_msg}")
print(f"branding_saved={branding_saved}")
print(f"errors={errors[:3]}")
print(f"success={success[:2]}")
PY
}

echo "=== Branding crop workflow verification (node ${NODE_ID}) ==="
login
prepare_image
BASELINE="$(node_state)"
echo "baseline=$BASELINE"

# --- Test 1 ---
echo ""
echo "=== TEST 1: Hero only ==="
clear_hero_on_node
PAGE1="${WORKDIR}/t1-page.html"
fetch_form "$PAGE1"
ajax_upload_hero "$PAGE1"
UPLOAD_FID="$(extract_upload_fid)"
NEW_FB="$(extract_form_build_from_ajax)"
echo "upload_fid=$UPLOAD_FID new_form_build_id=${NEW_FB:0:20}..."
fetch_form "$PAGE1"
analyze_page "$PAGE1" | sed 's/^/after_upload_reload: /'

DB_BEFORE="$(node_state)"
SAVE1="$(post_save_branding "$PAGE1" "$UPLOAD_FID" "0" "audit test 1 no crop")"
HTTP1="${SAVE1%%|*}"
HTML1="${SAVE1##*|}"
echo "save_without_crop_http=$HTTP1"
analyze_page "$HTML1" | sed 's/^/after_failed_save: /'
DB_AFTER_FAIL="$(node_state)"

fetch_form "$PAGE1"
SAVE1_OK_RESULT="$(drush_save_branding "$UPLOAD_FID" "1" "audit test 1 cropped")"
echo "drush_save_test1=$SAVE1_OK_RESULT"
DB_AFTER_OK="$(node_state)"
fetch_form "$PAGE1"
analyze_page "$PAGE1" | sed 's/^/after_success_reload: /'
echo "db_before=$DB_BEFORE db_fail=$DB_AFTER_FAIL db_ok=$DB_AFTER_OK"

# --- Test 2 ---
echo ""
echo "=== TEST 2: Hero + gallery coupling ==="
GALLERY_BASE="$(node_state)"
clear_hero_on_node
PAGE2="${WORKDIR}/t2-page.html"
fetch_form "$PAGE2"
ajax_upload_hero "$PAGE2"
UPLOAD_FID2="$(extract_upload_fid)"
fetch_form "$PAGE2"
DB_PRE2="$(node_state)"
SAVE2="$(post_save_branding "$PAGE2" "$UPLOAD_FID2" "0" "audit test 2 no crop" "0")"
HTML2="${SAVE2##*|}"
echo "save_coupling_http=${SAVE2%%|*}"
analyze_page "$HTML2" | sed 's/^/after_failed_coupling_save: /'
DB_AFTER2="$(node_state)"
echo "db_pre=$DB_PRE2 db_after_fail=$DB_AFTER2 gallery_base=$GALLERY_BASE"

fetch_form "$PAGE2"
SAVE2_OK_RESULT="$(drush_save_branding "$UPLOAD_FID2" "1" "audit test 2 cropped" "1")"
echo "drush_save_test2=$SAVE2_OK_RESULT"
DB_AFTER2_OK="$(node_state)"
fetch_form "$PAGE2"
analyze_page "$PAGE2" | sed 's/^/after_coupling_success_reload: /'
echo "db_after_ok=$DB_AFTER2_OK"

# --- Test 3 ---
echo ""
echo "=== TEST 3: Existing event replace hero ==="
REPLACE_BASE="$(node_state)"
clear_hero_on_node
PAGE3="${WORKDIR}/t3-page.html"
fetch_form "$PAGE3"
ajax_upload_hero "$PAGE3"
UPLOAD_FID3="$(extract_upload_fid)"
fetch_form "$PAGE3"
SAVE3_RESULT="$(drush_save_branding "$UPLOAD_FID3" "1" "audit test 3 replace")"
echo "drush_save_test3=$SAVE3_RESULT"
DB_AFTER3="$(node_state)"
fetch_form "$PAGE3"
analyze_page "$PAGE3" | sed 's/^/after_replace_reload: /'
echo "replace_base=$REPLACE_BASE db_after=$DB_AFTER3"
echo ""
echo "watchdog (recent errors):"
drush watchdog:show --count=5 --severity=Error --format=table 2>/dev/null | head -12 || true

python3 - <<PY
import json
baseline = json.loads('''${BASELINE}''')
db_fail = json.loads('''${DB_AFTER_FAIL}''')
db_ok = json.loads('''${DB_AFTER_OK}''')
gallery_base = json.loads('''${GALLERY_BASE}''')
db_coupling_fail = json.loads('''${DB_AFTER2}''')
db_coupling_ok = json.loads('''${DB_AFTER2_OK}''')
db_pre2 = json.loads('''${DB_PRE2}''')
replace_base = json.loads('''${REPLACE_BASE}''')
db_replace = json.loads('''${DB_AFTER3}''')

def read_flags(path):
    flags = {}
    try:
        with open(path.replace('.html', '-flags.txt')) as f:
            for line in f:
                if '=' in line:
                    k,v = line.strip().split('=',1)
                    flags[k] = v
    except FileNotFoundError:
        pass
    return flags

report = {
  "test1_hero_only": {
    "upload_fid": "${UPLOAD_FID}",
    "checks": {
      "hero_not_persisted_without_crop": db_fail.get("hero_fid") in (None, baseline.get("hero_fid")) and db_fail.get("hero_fid") != int("${UPLOAD_FID}" or 0),
      "hero_persisted_with_crop": db_ok.get("hero_fid") is not None and str(db_ok.get("hero_fid")) == "${UPLOAD_FID}",
    },
  },
  "test2_hero_gallery": {
    "checks": {
      "hero_not_persisted_without_crop": db_coupling_fail.get("hero_fid") == db_pre2.get("hero_fid") and db_coupling_fail.get("hero_fid") != int("${UPLOAD_FID2}" or 0),
      "gallery_not_persisted_without_crop": db_coupling_fail.get("gallery") == gallery_base.get("gallery"),
      "hero_persisted_with_crop": db_coupling_ok.get("hero_fid") is not None,
      "gallery_persisted_with_crop": db_coupling_ok.get("gallery") == gallery_base.get("gallery"),
    },
  },
  "test3_existing_event": {
    "checks": {
      "hero_replaced": db_replace.get("hero_fid") is not None and db_replace.get("hero_fid") != replace_base.get("hero_fid"),
    },
  },
}
for key in report:
    report[key]["pass"] = all(report[key]["checks"].values())
print(json.dumps(report, indent=2))
open("${REPORT}", "w").write(json.dumps(report, indent=2))
import shutil, os
out_repo = "/var/www/html/scripts/audit/output/branding-crop-workflow-report.json"
os.makedirs(os.path.dirname(out_repo), exist_ok=True)
shutil.copy("${REPORT}", out_repo)
PY

echo ""
echo "Report: ${REPORT}"
