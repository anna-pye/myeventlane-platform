# Vite Build Fixes - MyEventLane Theme

## ✅ Issue Resolved

**Problem:** Build failing with `@extend .mel-btn` error in `_auth.scss` and `_event-form.scss`

**Root Cause:** When using Sass `@use` modules (modern Sass syntax), classes are namespaced and cannot be extended with `@extend` from other modules.

**Solution:** Replaced `@extend` directives with direct style application, copying the button styles inline where needed.

## Files Fixed

1. **`src/scss/pages/_auth.scss`**
   - Removed `@extend .mel-btn`, `@extend .mel-btn-primary`, etc.
   - Applied button styles directly to form submit buttons

2. **`src/scss/pages/_event-form.scss`**
   - Removed all `@extend` directives
   - Applied button styles directly with proper variants (primary, secondary, danger)

3. **`src/scss/pages/_user.scss`**
   - Removed `@extend .mel-auth-form` (no longer needed)

## Build Commands

```bash
# From theme directory
cd web/themes/custom/myeventlane_theme

# Production build
ddev npm run build

# Development with HMR
ddev npm run dev
```

## Vite Configuration

Current setup:
- **Entry:** `src/js/main.js` (imports `src/scss/main.scss`)
- **Output:** `dist/main.js` and `dist/main.css`
- **Sass:** Using `sass` package (v1.77.0) with modern compiler API
- **Manifest:** Enabled for Drupal library integration

## Recommended Vite Optimizations

### 1. Production Asset Hashing (Optional)

For better cache busting, you could enable hashed filenames in production:

```js
// vite.config.js - build section
build: {
  rollupOptions: {
    output: {
      // Hashed filenames for production
      entryFileNames: process.env.NODE_ENV === 'production' 
        ? '[name].[hash].js' 
        : '[name].js',
      assetFileNames: process.env.NODE_ENV === 'production'
        ? '[name].[hash][extname]'
        : '[name][extname]',
    },
  },
}
```

**Note:** If you enable hashing, you'll need to update `myeventlane_theme.theme` to read the manifest and update library paths dynamically (which you're already doing via `hook_library_info_alter()`).

### 2. Source Maps for Production Debugging

```js
build: {
  sourcemap: 'hidden', // Creates .map files but doesn't reference them in production
}
```

### 3. CSS Minification

Already handled by Vite automatically in production builds.

### 4. PostCSS Configuration

You have `postcss` and `autoprefixer` installed. Create `postcss.config.js`:

```js
export default {
  plugins: {
    autoprefixer: {},
  },
};
```

## Testing

After fixes, verify the build:

```bash
ddev npm run build
ddev drush cr
```

Then test:
- `/user/login` - Should show branded auth form
- `/user/register` - Should show branded registration form
- `/node/add/event` - Should show branded event creation form

All forms should now use MyEventLane styling without build errors.

## Future Considerations

1. **Twig Live Reload:** Vite HMR works for JS/CSS, but Twig changes require Drupal cache clear. Consider a watch script that auto-clears cache on Twig changes.

2. **Component Library:** If you add more reusable components, consider creating a mixin-based approach instead of classes to avoid `@extend` issues.

3. **CSS Modules:** For scoped styles, consider CSS Modules, but this requires template changes.























