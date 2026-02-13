# MyEventLane Admin Theme - Quick Setup Guide

## Step-by-Step Installation

### 1. Verify Theme Files

All files should be in place:
```bash
ls -la web/themes/custom/myeventlane_admin/
```

You should see:
- `myeventlane_admin.info.yml`
- `myeventlane_admin.libraries.yml`
- `myeventlane_admin.theme`
- `myeventlane_admin.services.yml`
- `css/admin.css` (compiled)
- `js/admin.js`
- `templates/` directory
- `src/Theme/` directory

### 2. Enable the Theme

```bash
ddev drush theme:enable myeventlane_admin
```

### 3. Set as Admin Theme

```bash
ddev drush config:set system.theme admin myeventlane_admin -y
```

### 4. Clear All Caches

```bash
ddev drush cr
```

### 5. Verify Theme Switching

**Test as Admin/Vendor User:**

1. **Login** as a user with vendor or admin permissions
2. **Visit** `/node/add/event` - Should see Bootstrap 5 admin theme
3. **Visit** `/vendor/dashboard` - Should see admin theme
4. **Visit** `/admin/config/myeventlane` - Should see admin theme
5. **Visit** `/node/1/edit` (any node) - Should see admin theme

**Test as Anonymous User:**

1. **Logout**
2. **Visit** `/node/add/event` - Should see **frontend theme** (myeventlane_theme)
3. **Visit** any public page - Should see **frontend theme**

### 6. Disable Gin (Optional)

If Gin was previously the admin theme:

```bash
ddev drush theme:disable gin
ddev drush cr
```

## Troubleshooting

### Theme Not Appearing

1. **Check theme is enabled:**
   ```bash
   ddev drush theme:list
   ```
   Look for `myeventlane_admin` in the list.

2. **Check admin theme setting:**
   ```bash
   ddev drush config:get system.theme admin
   ```
   Should return: `myeventlane_admin`

3. **Verify user permissions:**
   - User must have `access vendor dashboard` OR
   - User must have `administer nodes` OR
   - User must have `administer site configuration`

### Styles Not Loading

1. **Check CSS file exists:**
   ```bash
   ls -la web/themes/custom/myeventlane_admin/css/admin.css
   ```

2. **Recompile SCSS if needed:**
   ```bash
   cd web/themes/custom/myeventlane_admin
   sass scss/admin.scss css/admin.css --style=expanded
   ```

3. **Clear cache:**
   ```bash
   ddev drush cr
   ```

### Theme Negotiator Not Working

1. **Check service is registered:**
   ```bash
   ddev drush ev "print_r(\Drupal::service('theme.negotiator.myeventlane_admin'));"
   ```

2. **Verify route matching:**
   - Check `src/Theme/MyEventLaneAdminThemeNegotiator.php`
   - Ensure route paths match your routes

3. **Check user is not anonymous:**
   - Theme never activates for anonymous users
   - User must be logged in with appropriate permissions

## Next Steps

After installation:

1. **Customize colors** in `scss/admin.scss` if needed
2. **Adjust form widths** in `scss/admin.scss` (variables at top)
3. **Add custom templates** in `templates/` if needed
4. **Enhance JavaScript** in `js/admin.js` for specific form behaviors

## Support

- Full documentation: See `README.md`
- Theme code: `web/themes/custom/myeventlane_admin/`
- Drupal theme docs: https://www.drupal.org/docs/theming-drupal


















