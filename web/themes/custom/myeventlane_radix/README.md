# MyEventLane Radix Theme

MyEventLane v2 theme built on **Radix** (Bootstrap 5) for Drupal 11.

## Overview

This theme is an incremental migration from the existing `myeventlane_theme` (Stable9 base) to a modern Bootstrap 5 foundation. It is designed to be:

- **Non-destructive**: Existing Stable9 theme remains untouched
- **Incremental**: Can be developed and tested alongside the current theme
- **Token-based**: Design tokens extracted from the existing theme
- **SDC-ready**: Prepared for Single Directory Components (Drupal 11)
- **Mobile-first**: Responsive and accessible by default

## Status

🚧 **In Development** - This theme is not yet active and should not be enabled in production.

## Requirements

- Drupal 11
- Radix theme (install via Composer: `ddev composer require drupal/radix`)
- SCSS build tooling (Vite, Webpack, Gulp, or similar)
- Node.js and npm (for build process)

## Installation

### 1. Install Radix Base Theme

```bash
ddev composer require drupal/radix
```

### 2. Configure Build Tooling

This theme requires SCSS compilation. Choose one:

**Option A: Vite** (Recommended - matches existing theme setup)
```bash
cd web/themes/custom/myeventlane_radix
ddev exec npm init -y
ddev exec npm install --save-dev vite sass
```

Create `vite.config.js`:
```javascript
import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  build: {
    outDir: 'dist',
    rollupOptions: {
      input: resolve(__dirname, 'src/scss/main.scss'),
      output: {
        assetFileNames: 'main.css'
      }
    }
  },
  css: {
    preprocessorOptions: {
      scss: {
        additionalData: `@use "sass:math";`
      }
    }
  }
});
```

**Option B: Webpack / Gulp / Other**
Configure your preferred build tool to compile `src/scss/main.scss` to `dist/main.css`.

### 3. Build Assets

```bash
# Development (watch mode)
ddev exec npm run dev

# Production
ddev exec npm run build
```

### 4. Clear Drupal Cache

```bash
ddev drush cr
```

## Theme Structure

```
myeventlane_radix/
├── myeventlane_radix.info.yml      # Theme definition
├── myeventlane_radix.libraries.yml  # Library definitions
├── README.md                        # This file
├── src/
│   └── scss/
│       ├── main.scss                # Entry point
│       ├── tokens/
│       │   └── _tokens.scss         # Design tokens + Bootstrap overrides
│       ├── base/
│       │   └── _base.scss           # Base styles
│       └── components/
│           └── _placeholder.scss    # Component template
└── dist/                            # Compiled CSS (generated)
    └── main.css
```

## Design Tokens

Design tokens are extracted from `myeventlane_theme` and mapped to Bootstrap 5 variables:

- **Colors**: Primary (#ff6f61 coral), Secondary (#8d79f6 lavender), semantic colors
- **Typography**: Nunito font family, type scale (12px - 48px)
- **Spacing**: 8px grid system (0.5rem - 8rem)
- **Border Radius**: 6px, 12px, 18px, 28px, 999px (pill)

See `src/scss/tokens/_tokens.scss` for full token definitions and Bootstrap mappings.

## Bootstrap 5 Integration

This theme uses Radix as a base theme, which includes Bootstrap 5. Our tokens override Bootstrap's default variables:

- `$primary` → MyEventLane coral (#ff6f61)
- `$secondary` → MyEventLane lavender (#8d79f6)
- `$font-family-base` → Nunito
- `$spacer` → 1rem (16px, matches MyEventLane spacing scale)
- `$border-radius-*` → MyEventLane radius values

All Bootstrap 5 components and utilities are available out of the box.

## Single Directory Components (SDCs)

This theme is prepared for Drupal 11's Single Directory Components feature. When implementing SDCs:

1. Create component directories in `components/`:
   ```
   components/
     event-card/
       event-card.component.yml
       event-card.twig
       event-card.scss
       event-card.js (optional)
   ```

2. Component SCSS can be co-located with component files or kept in `src/scss/components/`.

3. Reference components in templates using `{{ include_component('event-card', ...) }}`.

See: [Drupal SDC Documentation](https://www.drupal.org/docs/core-modules-and-themes/core-modules/sdc-module)

## Development Workflow

### 1. Create Feature Branch

```bash
git checkout -b feature/radix-theme-migration
```

### 2. Develop Components

- Add component SCSS files in `src/scss/components/`
- Import in `src/scss/main.scss`
- Build and test locally

### 3. Test Incrementally

- Do not enable this theme as default yet
- Test specific pages/routes by temporarily switching themes
- Compare with existing Stable9 theme for visual consistency

### 4. CSS Purging (Production)

Configure PurgeCSS or similar tool to remove unused CSS:

```javascript
// purgecss.config.js
module.exports = {
  content: [
    './templates/**/*.twig',
    './src/**/*.scss',
    '../../modules/custom/**/*.twig',
    '../../modules/custom/**/*.php',
  ],
  safelist: [
    /^mel-/,      // MyEventLane classes
    /^btn-/,      // Bootstrap buttons
    /^bg-/,       // Bootstrap backgrounds
    /^text-/,     // Bootstrap text colors
  ]
};
```

## Next Steps (Manual Checklist)

- [ ] Install Radix theme via Composer
- [ ] Set up build tooling (Vite/Webpack/Gulp)
- [ ] Configure `package.json` scripts (dev, build)
- [ ] Test SCSS compilation
- [ ] Create component files (buttons, cards, forms, etc.)
- [ ] Create layout files (header, footer, navigation)
- [ ] Implement SDCs for reusable components
- [ ] Add Twig templates (override Radix templates as needed)
- [ ] Test on key pages (event pages, checkout, vendor dashboard)
- [ ] Configure CSS purging for production
- [ ] Document component usage
- [ ] Plan incremental rollout strategy

## Migration Strategy

This theme is designed for incremental migration:

1. **Phase 1** (Current): Create theme structure, extract tokens, set up build tooling
2. **Phase 2**: Build core components (buttons, cards, forms)
3. **Phase 3**: Migrate layout (header, footer, navigation)
4. **Phase 4**: Migrate page templates (event pages, checkout)
5. **Phase 5**: Implement SDCs for complex components
6. **Phase 6**: Test and refine
7. **Phase 7**: Switch default theme (when ready)

## Notes

- **Do not enable this theme as default** until migration is complete
- **Do not modify** `myeventlane_theme` (Stable9) during migration
- **Do not modify** custom modules unless explicitly requested
- All changes should be safe to commit on a feature branch

## Support

For questions or issues, refer to:
- [Radix Documentation](https://www.drupal.org/project/radix)
- [Bootstrap 5 Documentation](https://getbootstrap.com/docs/5.3/)
- [Drupal 11 Theme Development](https://www.drupal.org/docs/theming-drupal)
