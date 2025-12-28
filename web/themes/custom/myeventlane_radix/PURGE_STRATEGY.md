# Bootstrap Purge & Performance Strategy

**Theme:** MyEventLane Radix  
**Base:** Radix (Bootstrap 5)  
**Purpose:** Prevent Bootstrap CSS bloat in production while preserving all required classes.

---

## Overview

Bootstrap 5 includes a comprehensive utility system that can result in large CSS files (200KB+ uncompressed). In production, we need to remove unused Bootstrap classes while preserving:

1. **Bootstrap classes used in templates** (grid, buttons, forms, cards, etc.)
2. **MyEventLane custom classes** (all `mel-*` prefixed classes)
3. **Drupal-generated classes** (dynamic classes added by Drupal core/modules)
4. **JavaScript-dependent classes** (classes added/removed via JS, e.g., `is-active`, `show`, `collapsed`)

---

## Tool-Agnostic Strategy

This strategy works with any CSS purging tool:
- **PurgeCSS** (recommended for Vite/Webpack)
- **PostCSS with purgecss plugin**
- **Tailwind CSS JIT** (if using Tailwind)
- **uncss** (alternative)

---

## Content Sources

The purge tool must scan these locations for class usage:

```javascript
content: [
  // Theme templates
  './templates/**/*.twig',
  './src/**/*.scss',
  './src/**/*.js',
  
  // Custom modules (may reference theme classes)
  '../../modules/custom/**/*.twig',
  '../../modules/custom/**/*.php',
  
  // Radix base theme templates (for Bootstrap classes)
  '../../contrib/radix/**/*.twig',
  
  // Drupal core templates (if overriding)
  '../../core/**/*.twig', // Optional - usually not needed
]
```

---

## Safelist Configuration

### 1. MyEventLane Custom Classes

**Pattern:** All classes starting with `mel-`

```javascript
safelist: [
  /^mel-/,  // Preserves: mel-wizard, mel-checkout, mel-event-card, etc.
]
```

**Rationale:** All MyEventLane custom classes are intentional and should be preserved.

---

### 2. Bootstrap Utility Classes

**Pattern:** Common Bootstrap utility prefixes

```javascript
safelist: [
  // Spacing utilities
  /^p-/,      // padding (p-1, p-2, p-md-3, etc.)
  /^m-/,      // margin (m-1, m-2, m-md-3, etc.)
  /^gap-/,    // gap (gap-1, gap-2, gap-md-3, etc.)
  
  // Display utilities
  /^d-/,      // display (d-none, d-block, d-flex, d-md-grid, etc.)
  /^flex-/,   // flexbox (flex-row, flex-column, flex-wrap, etc.)
  /^grid-/,   // grid (grid-template-columns, etc.)
  
  // Sizing utilities
  /^w-/,      // width (w-25, w-50, w-100, etc.)
  /^h-/,      // height (h-25, h-50, h-100, etc.)
  
  // Color utilities
  /^bg-/,     // background (bg-primary, bg-light, bg-white, etc.)
  /^text-/,   // text color (text-primary, text-muted, text-white, etc.)
  /^border-/, // border (border, border-primary, border-0, etc.)
  
  // Typography utilities
  /^fw-/,     // font-weight (fw-bold, fw-semibold, etc.)
  /^fs-/,     // font-size (fs-1, fs-2, etc.)
  
  // Border radius utilities
  /^rounded-/, // rounded (rounded, rounded-0, rounded-circle, etc.)
  
  // Shadow utilities
  /^shadow-/,  // shadow (shadow, shadow-sm, shadow-lg, etc.)
  
  // Position utilities
  /^position-/, // position (position-relative, position-sticky, etc.)
  /^top-/,      // top (top-0, top-50, etc.)
  /^start-/,    // start (start-0, start-50, etc.)
  /^end-/,      // end (end-0, end-50, etc.)
]
```

**Rationale:** Bootstrap utilities are commonly used in templates and may be dynamically generated. Safelisting ensures they're never removed.

**Note:** This is a conservative approach. For more aggressive purging, remove utility safelists and rely on content scanning only.

---

### 3. Bootstrap Component Classes

**Pattern:** Bootstrap component class names

```javascript
safelist: [
  /^card/,        // card, card-body, card-header, card-title, etc.
  /^btn/,         // btn, btn-primary, btn-lg, btn-outline-primary, etc.
  /^navbar/,      // navbar, navbar-nav, navbar-brand, etc.
  /^dropdown/,    // dropdown, dropdown-menu, dropdown-item, etc.
  /^modal/,       // modal, modal-dialog, modal-content, etc.
  /^form-/,       // form-control, form-label, form-select, form-check, etc.
  /^alert/,       // alert, alert-primary, alert-success, etc.
  /^badge/,       // badge, badge-primary, badge-pill, etc.
  /^list-group/,  // list-group, list-group-item, etc.
  /^table/,       // table, table-striped, table-hover, etc.
  /^container/,   // container, container-fluid, container-sm, etc.
  /^row/,         // row (used with Bootstrap grid)
  /^col/,         // col, col-12, col-md-6, col-lg-4, etc.
  /^g-/,          // g-0, g-1, g-2, g-3, g-4 (gap utilities)
]
```

**Rationale:** Bootstrap components are heavily used in Radix templates and custom templates.

---

### 4. Drupal Core Classes

**Pattern:** Drupal-specific classes

```javascript
safelist: [
  /^visually-hidden/,  // Drupal's screen-reader-only class
  /^skip-link/,       // Drupal's skip navigation link
  /^js-/,             // JavaScript-dependent classes (js-once, js-form-item, etc.)
  /^contextual/,      // Drupal contextual links
  /^field-/,          // Drupal field classes (field--name-title, etc.)
  /^node-/,           // Drupal node classes (node--type-event, etc.)
  /^view-/,           // Drupal Views classes
]
```

**Rationale:** Drupal generates these classes dynamically. They must be preserved.

---

### 5. JavaScript-Dependent Classes

**Pattern:** Classes added/removed via JavaScript

```javascript
safelist: [
  /^is-/,        // is-active, is-hidden, is-complete, is-disabled, etc.
  /^has-/,       // has-error, has-focus, etc.
  /^active/,     // active (Bootstrap active state)
  /^show/,       // show (Bootstrap show state)
  /^collapsed/,  // collapsed (Bootstrap collapse)
  /^disabled/,   // disabled state
  /^loading/,    // loading state
]
```

**Rationale:** These classes are added dynamically via JavaScript and won't appear in static templates. They must be safelisted.

---

## Complete PurgeCSS Configuration Example

**File:** `purgecss.config.js`

```javascript
module.exports = {
  content: [
    // Theme templates
    './templates/**/*.twig',
    './src/**/*.scss',
    './src/**/*.js',
    
    // Custom modules
    '../../modules/custom/**/*.twig',
    '../../modules/custom/**/*.php',
    
    // Radix base theme
    '../../contrib/radix/**/*.twig',
  ],
  
  safelist: [
    // MyEventLane classes
    /^mel-/,
    
    // Bootstrap utilities
    /^p-/, /^m-/, /^gap-/, /^d-/, /^flex-/, /^grid-/,
    /^w-/, /^h-/, /^bg-/, /^text-/, /^border-/, /^fw-/, /^fs-/,
    /^rounded-/, /^shadow-/, /^position-/, /^top-/, /^start-/, /^end-/,
    
    // Bootstrap components
    /^card/, /^btn/, /^navbar/, /^dropdown/, /^modal/, /^form-/,
    /^alert/, /^badge/, /^list-group/, /^table/, /^container/, /^row/, /^col/, /^g-/,
    
    // Drupal classes
    /^visually-hidden/, /^skip-link/, /^js-/, /^contextual/,
    /^field-/, /^node-/, /^view-/,
    
    // JavaScript-dependent
    /^is-/, /^has-/, /^active/, /^show/, /^collapsed/, /^disabled/, /^loading/,
  ],
  
  // Default extractor for Tailwind-like classes
  defaultExtractor: (content) => content.match(/[\w-/:]+(?<!:)/g) || [],
  
  // Output options
  output: './dist/',
  
  // Preserve font faces and keyframes
  fontFace: true,
  keyframes: true,
};
```

---

## Integration with Build Tools

### Vite (Recommended)

**Install:**
```bash
npm install --save-dev @fullhuman/postcss-purgecss
```

**File:** `vite.config.js`
```javascript
import { defineConfig } from 'vite';
import purgecss from '@fullhuman/postcss-purgecss';

export default defineConfig({
  css: {
    postcss: {
      plugins: [
        purgecss({
          content: [
            './templates/**/*.twig',
            './src/**/*.scss',
            '../../modules/custom/**/*.twig',
          ],
          safelist: [
            /^mel-/, /^btn-/, /^bg-/, /^text-/, /^d-/, /^flex-/, /^grid-/,
            // ... (full safelist from above)
          ],
        }),
      ],
    },
  },
});
```

### Webpack

**Install:**
```bash
npm install --save-dev purgecss-webpack-plugin
```

**File:** `webpack.config.js`
```javascript
const PurgeCSSPlugin = require('purgecss-webpack-plugin');
const glob = require('glob');

module.exports = {
  plugins: [
    new PurgeCSSPlugin({
      paths: glob.sync([
        './templates/**/*.twig',
        './src/**/*.scss',
        '../../modules/custom/**/*.twig',
      ]),
      safelist: [
        /^mel-/, /^btn-/, /^bg-/, /^text-/, /^d-/, /^flex-/, /^grid-/,
        // ... (full safelist from above)
      ],
    }),
  ],
};
```

---

## Performance Checklist

### Pre-Production

- [ ] PurgeCSS configuration tested in development
- [ ] All Bootstrap utility classes used in templates are safelisted or detected
- [ ] All MyEventLane classes (`mel-*`) are preserved
- [ ] JavaScript-dependent classes are safelisted
- [ ] Drupal-generated classes are preserved
- [ ] Font faces and keyframes are preserved
- [ ] CSS file size reduced by at least 50% (target: <100KB gzipped)

### Testing

- [ ] Event Wizard renders correctly (all steps visible)
- [ ] Commerce checkout renders correctly (all panes visible)
- [ ] Form validation states work (error/success classes)
- [ ] Bootstrap modals work (show/hide classes)
- [ ] Bootstrap dropdowns work (show class)
- [ ] Bootstrap collapse works (collapsed class)
- [ ] Responsive utilities work (d-md-*, col-lg-*, etc.)
- [ ] Custom MyEventLane components render correctly

### Production

- [ ] PurgeCSS runs in production build only (not development)
- [ ] Source maps generated for debugging
- [ ] CSS minified and gzipped
- [ ] Browser cache headers configured
- [ ] CDN configured (if applicable)

---

## Expected Results

### Before Purge
- **Uncompressed:** ~250KB
- **Gzipped:** ~35KB

### After Purge (Conservative Safelist)
- **Uncompressed:** ~120KB (52% reduction)
- **Gzipped:** ~18KB (49% reduction)

### After Purge (Aggressive - No Utility Safelist)
- **Uncompressed:** ~80KB (68% reduction)
- **Gzipped:** ~12KB (66% reduction)

**Recommendation:** Start with conservative safelist, then remove utility safelists if testing passes.

---

## Troubleshooting

### Missing Classes After Purge

1. **Check content sources:** Ensure all template directories are included
2. **Check safelist:** Add missing class patterns to safelist
3. **Check extractor:** Ensure extractor handles Twig syntax correctly
4. **Check dynamic classes:** Add JavaScript-dependent classes to safelist

### CSS Still Too Large

1. **Remove utility safelists:** Rely on content scanning only
2. **Audit unused components:** Remove Bootstrap components not in use
3. **Split CSS:** Separate critical CSS from non-critical

### Build Errors

1. **Check file paths:** Ensure content paths are relative to config file
2. **Check glob patterns:** Test glob patterns match expected files
3. **Check PostCSS version:** Ensure PostCSS version is compatible

---

## Notes

- **Development:** Disable purging in development for faster builds
- **Production:** Always purge in production builds
- **Testing:** Test thoroughly after purging - missing classes can break layouts
- **Incremental:** Start conservative, then remove safelists incrementally

---

## References

- [PurgeCSS Documentation](https://purgecss.com/)
- [Bootstrap 5 Utilities](https://getbootstrap.com/docs/5.3/utilities/)
- [Drupal CSS Architecture](https://www.drupal.org/docs/theming-drupal/working-with-css-in-drupal)
