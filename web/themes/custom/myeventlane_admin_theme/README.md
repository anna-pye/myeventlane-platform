# MyEventLane Admin Theme

Bootstrap 5.3 admin theme for vendor and admin workflows in MyEventLane.

## Overview

This theme is **NOT** the frontend theme. It is exclusively used for:
- Vendor dashboard and workflows (`/vendor/*`)
- Admin configuration pages (`/admin/myeventlane/*`)
- Node add/edit forms (`/node/add/*`, `/node/*/edit`)
- Dashboard routes (`/dashboard/*`, `/vendor/dashboard`)

The public theme (`myeventlane_theme`) remains untouched and handles all frontend experiences.

## Features

- **Bootstrap 5.3** (via CDN, no jQuery dependency)
- **Clean, readable forms** with 75-85% width on desktop
- **Mobile-first responsive** layout
- **Enhanced form elements**:
  - Taxonomy autocomplete with large, clickable inputs
  - Conditional fields with smooth show/hide transitions
  - Field groups with card styling
  - Vertical and horizontal tabs
  - Location fields with map integration
  - Media browser enhancements
- **Theme switching** via `ThemeNegotiator` - automatically activates for vendor/admin routes
- **No style leakage** - completely isolated from frontend theme

## Installation

### 1. Enable the Theme

```bash
ddev drush theme:enable myeventlane_admin
```

### 2. Set as Admin Theme

```bash
ddev drush config:set system.theme admin myeventlane_admin -y
```

Or via the UI:
1. Go to `/admin/appearance`
2. Find "MyEventLane Admin"
3. Click "Set as default" under "Administration theme"

### 3. Clear Cache

```bash
ddev drush cr
```

## Theme Switching Logic

The theme automatically activates for:

- **Routes matching**:
  - `/vendor/*` (except public vendor canonical pages)
  - `/dashboard/*` and `/vendor/dashboard`
  - `/admin/myeventlane/*`
  - `/admin/structure/myeventlane/*`
  - `/admin/config/myeventlane/*`
  - `/node/add/*` and `/node/*/edit`
  - `/create-event`
  - `/vendor/onboard`
  - `/my-categories`

- **User permissions**:
  - Users with `administer site configuration`
  - Users with `administer nodes`
  - Users with `access vendor dashboard`
  - **Never** for anonymous users

The theme switcher is implemented in:
`src/Theme/MyEventLaneAdminThemeNegotiator.php`

## File Structure

```
myeventlane_admin/
├── myeventlane_admin.info.yml          # Theme definition
├── myeventlane_admin.libraries.yml      # CSS/JS libraries
├── myeventlane_admin.theme             # Theme hooks and form alterations
├── myeventlane_admin.services.yml      # Service definitions
├── README.md                           # This file
├── css/
│   └── admin.css                       # Compiled CSS (from SCSS)
├── scss/
│   └── admin.scss                      # Source SCSS
├── js/
│   └── admin.js                        # JavaScript enhancements
├── templates/
│   ├── page.html.twig                  # Main page template
│   ├── form/
│   │   └── form.html.twig              # Form template
│   └── node/
│       └── node-edit.html.twig         # Node edit form template
└── src/
    └── Theme/
        └── MyEventLaneAdminThemeNegotiator.php  # Theme switching logic
```

## Development

### Compiling SCSS

The theme uses SCSS for styling. To compile:

```bash
cd web/themes/custom/myeventlane_admin
sass scss/admin.scss css/admin.css --style=expanded
```

Or watch for changes:

```bash
sass --watch scss/admin.scss:css/admin.css
```

### Customization

- **Colors**: Edit variables in `scss/admin.scss`
- **Layout**: Modify templates in `templates/`
- **Form enhancements**: Edit `myeventlane_admin.theme`
- **JavaScript**: Edit `js/admin.js`

## Verification

After installation, verify the theme is working:

1. **Login as vendor/admin user**
2. **Visit** `/node/add/event` - should see Bootstrap 5 styling
3. **Visit** `/vendor/dashboard` - should see admin theme
4. **Visit** `/admin/config/myeventlane` - should see admin theme
5. **Visit** public event page (e.g., `/event/123`) - should see **frontend theme** (myeventlane_theme)

## Troubleshooting

### Theme not activating

1. Check user has required permissions:
   ```bash
   ddev drush user:information <username>
   ```

2. Verify theme is enabled:
   ```bash
   ddev drush theme:list
   ```

3. Check route matches in `MyEventLaneAdminThemeNegotiator.php`

4. Clear all caches:
   ```bash
   ddev drush cr
   ```

### Styles not loading

1. Verify libraries are defined in `myeventlane_admin.libraries.yml`
2. Check CSS file exists: `css/admin.css`
3. Clear cache: `ddev drush cr`
4. Check browser console for 404 errors

### Bootstrap conflicts

The theme uses Bootstrap 5.3 via CDN. If you see conflicts:
- Check for other Bootstrap versions in other themes/modules
- Verify jQuery is not conflicting (Bootstrap 5 doesn't require jQuery)

## Replacing Gin Theme

This theme is designed to replace Gin. To fully remove Gin:

1. **Disable Gin**:
   ```bash
   ddev drush theme:disable gin
   ```

2. **Set MyEventLane Admin as admin theme** (see Installation above)

3. **Clear cache**

4. **Verify** all admin/vendor routes use the new theme

## Support

For issues or questions, refer to:
- Theme code: `web/themes/custom/myeventlane_admin/`
- Theme negotiator: `src/Theme/MyEventLaneAdminThemeNegotiator.php`
- Drupal theme documentation: https://www.drupal.org/docs/theming-drupal

---

**Version**: 1.0.0  
**Drupal**: 11.x  
**Bootstrap**: 5.3.3


















