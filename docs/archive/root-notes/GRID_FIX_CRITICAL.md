# Critical Grid Fix - Sticker Wall Collapse Issue

## Problem
Cards are rendering as "skinny vertical strips" because:
1. Views output `.mel-event-grid` inside `.mel-sticker-wall`
2. `.mel-event-grid` uses `minmax(0, 1fr)` which allows items to shrink to 0
3. Cards don't have proper width constraints

## Solution Applied

### 1. Homepage Grid Override (Highest Specificity)
File: `src/scss/pages/_homepage.scss`

```scss
.mel-page--front .mel-sticker-wall .mel-event-grid {
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)) !important;
  > * {
    min-width: 260px !important;
  }
}
```

### 2. Card Width Constraints
- Added `min-width: 0` to prevent flex shrink
- Added `width: 100%` to cards
- Added `box-sizing: border-box`

### 3. Aspect Ratio Fix
- Poster cards: `aspect-ratio: 3 / 4`
- Applied to both `.mel-card-media` and `.mel-card__image-wrapper`

## Next Steps

1. **Compile SCSS**: `ddev exec npm run build`
2. **Clear cache**: `ddev drush cr`
3. **Inspect HTML**: Check if Views is outputting `.mel-event-grid` or `.view-content`
4. **Check browser DevTools**: Verify which CSS rules are actually applied

## If Still Not Working

The Views might be outputting a different structure. Check:
- Browser DevTools → Inspect a card
- Look for the actual class names on the grid container
- Verify if `.mel-sticker-wall` is actually wrapping the grid

If Views outputs `.view-content` instead of `.mel-event-grid`, the override might need adjustment.
