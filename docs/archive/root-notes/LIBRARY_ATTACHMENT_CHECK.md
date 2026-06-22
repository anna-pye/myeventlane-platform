# Library & CSS Attachment Check

## Libraries Configured

### 1. `myeventlane_theme/myeventlane_event_full`
- **Location**: `web/themes/custom/myeventlane_theme/myeventlane_theme.libraries.yml`
- **CSS**: `dist/main.css` (compiled from SCSS)
- **Dependencies**: 
  - `core/drupal`
  - `core/once`
  - `myeventlane_location/event_map` ✅ (just added)

### 2. `myeventlane_location/event_map`
- **Location**: `web/modules/custom/myeventlane_location/myeventlane_location.libraries.yml`
- **JS**: `js/event-map.js`
- **CSS**: `css/event-map.css`
- **Dependencies**: `core/drupal`, `core/jquery`

## Template Attachments

### `node--event--full.html.twig`
- ✅ Attaches `myeventlane_theme/myeventlane_event_full` (line 14)
- ✅ Conditionally attaches `myeventlane_location/event_map` if coordinates exist (lines 15-17)

### Preprocess Attachments
- ✅ `myeventlane_theme.theme` line 767: Attaches `myeventlane_event_full`
- ✅ `myeventlane_theme.theme` line 845: Attaches `myeventlane_location/event_map` if coordinates exist

## SCSS Compilation

### Main SCSS Entry Point
- **File**: `web/themes/custom/myeventlane_theme/src/scss/main.scss`
- ✅ Line 93: `@use 'pages/event';` - Event page styles are imported

### Event Page SCSS
- **File**: `web/themes/custom/myeventlane_theme/src/scss/pages/_event.scss`
- Contains all event page styles including:
  - `.mel-event-grid` (two-column layout)
  - `.event-sidebar` (sticky sidebar)
  - `.event-sidebar__action-card` (sticky card)
  - `.mel-event-card` (compact cards)
  - `.mel-organiser` (compact organiser)
  - `.event-sidebar__map` (map container)

## Build Status

✅ **SCSS is compiling**: `npm run build` completes successfully
✅ **Output**: `dist/main.css` (184.50 kB)

## Potential Issues

### 1. Cache
- **Solution**: Run `ddev drush cr` to clear Drupal cache
- **Why**: Library definitions are cached

### 2. CSS Not Loading
- **Check**: Browser DevTools → Network tab → Look for `main.css` and `event-map.css`
- **Verify**: Both files should load with 200 status

### 3. JavaScript Not Loading
- **Check**: Browser DevTools → Console → Look for map initialization logs
- **Verify**: Should see "MyEventLane Location: Initializing event map..."

### 4. Map Not Rendering
- **Check**: Browser DevTools → Console → Look for errors
- **Common issues**:
  - Google Maps API key missing/invalid
  - Coordinates not set (check `drupalSettings.myeventlaneLocationEvent`)
  - Container not found (check `.myeventlane-event-map-container` exists)

## Debugging Steps

1. **Clear all caches**:
   ```bash
   ddev drush cr
   ```

2. **Rebuild assets** (if SCSS changed):
   ```bash
   cd web/themes/custom/myeventlane_theme
   ddev exec npm run build
   ```

3. **Check browser console**:
   - Open DevTools (F12)
   - Check Console for errors
   - Check Network tab for CSS/JS files

4. **Verify drupalSettings**:
   ```javascript
   console.log(drupalSettings.myeventlaneLocation);
   console.log(drupalSettings.myeventlaneLocationEvent);
   ```

5. **Check map container**:
   ```javascript
   console.log(document.querySelector('.myeventlane-event-map-container'));
   ```

## Files Modified

1. ✅ `myeventlane_theme.libraries.yml` - Added `event_map` dependency
2. ✅ `node--event--full.html.twig` - Added conditional map library attachment
3. ✅ `pages/_event.scss` - All styles are in place
4. ✅ `event-map.js` - Map initialization code updated
5. ✅ `event-map.css` - Map styling updated

## Next Steps

1. **Clear cache**: `ddev drush cr`
2. **Hard refresh browser**: Ctrl+Shift+R (or Cmd+Shift+R on Mac)
3. **Check browser console** for any errors
4. **Verify libraries loaded** in Network tab
